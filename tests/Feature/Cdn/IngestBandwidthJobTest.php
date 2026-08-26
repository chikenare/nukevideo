<?php

/**
 * `usage` is a SummingMergeTree partitioned on `date`, so a row landing on the wrong day is not
 * something a later correction can take back — and the ingest runs on a timer, so a batch that
 * straddles midnight used to book the tail of one day to the next. The events now carry the day the
 * traffic actually happened; this pins that, the metric each zone lands under, the account
 * attribution resolved from the video, and the input filtering that protects the batch.
 *
 * Row shape, once and for all: [date, user_id, metric, external_user_id, video_ulid, ip, tracking_id, node_id, cache, value]
 */

use App\Jobs\IngestBandwidthJob;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use App\Services\Cdn\TrackingRegistry;
use ClickHouseDB\Client;
use ClickHouseDB\Statement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

const OTHER_ULID = '01HZXW3V5N8Q9R2T4Y6B8D0F9Z';

/** Runs the job against a fake ClickHouse and returns the rows it tried to insert. */
function insertedRows(array $events): array
{
    $captured = [];

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('insert')->andReturnUsing(function ($table, $rows) use (&$captured) {
        expect($table)->toBe('usage');
        $captured = $rows;

        return Mockery::mock(Statement::class);
    });

    app()->instance(Client::class, $client);

    (new IngestBandwidthJob($events))->handle();

    return $captured;
}

function videoOwnedBySomeone(string $externalUserId = ''): Video
{
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    return Video::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => 'completed',
        'external_user_id' => $externalUserId,
    ]);
}

it('dates a row by the traffic, not by the moment it was ingested', function () {
    $video = videoOwnedBySomeone();

    $rows = insertedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 500, 'date' => '2026-01-31'],
    ]);

    expect($rows)->toBe([['2026-01-31', $video->user_id, 'bandwidth_bytes', '', $video->ulid, '1.2.3.4', '', 0, '', 500]]);
});

it('accepts a full timestamp and keeps only its day', function () {
    $video = videoOwnedBySomeone();

    $rows = insertedRows([
        ['video_ulid' => $video->ulid, 'ip' => '::1', 'bytes' => 10, 'timestamp' => '2026-01-31T23:59:12Z'],
    ]);

    expect($rows[0][0])->toBe('2026-01-31');
});

it('falls back to today for an event from a producer that sends no date', function () {
    $video = videoOwnedBySomeone();

    $rows = insertedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10],
    ]);

    expect($rows[0][0])->toBe(now()->format('Y-m-d'));
});

it('drops a single unusable row rather than losing the whole batch to it', function () {
    // The column is `IPv6`, which cannot parse '' or '-' — ClickHouse rejects the entire block
    // over one bad value, so up to 1000 good events would go down with it.
    $video = videoOwnedBySomeone();

    $rows = insertedRows([
        ['video_ulid' => $video->ulid, 'ip' => '', 'bytes' => 100],
        ['video_ulid' => $video->ulid, 'ip' => '-', 'bytes' => 100],
        ['video_ulid' => 'not-a-ulid', 'ip' => '1.2.3.4', 'bytes' => 100],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 0],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 700],
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0][9])->toBe(700);
});

it('attributes traffic for a video that no longer exists to no user', function () {
    $rows = insertedRows([
        ['video_ulid' => OTHER_ULID, 'ip' => '1.2.3.4', 'bytes' => 42],
    ]);

    expect($rows[0][1])->toBe(0);
});

it('keeps traffic from different tracking ids in separate rows', function () {
    $video = videoOwnedBySomeone();

    // `tracking_id` is part of the sorting key of a SummingMergeTree, so two customers' bytes must arrive
    // as distinct rows; collapsing them here would be indistinguishable from a merge later.
    $rows = insertedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'date' => '2026-08-13', 'tracking_id' => 'customer-a'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 20, 'date' => '2026-08-13', 'tracking_id' => 'customer-b'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 30, 'date' => '2026-08-13'],
    ]);

    expect(array_column($rows, 6))->toBe(['customer-a', 'customer-b', '']);
});

it('blanks a tracking id that did not survive the round trip intact', function () {
    $video = videoOwnedBySomeone();

    // A `tracking_id` sent as-is is text that crossed a log and a queue, so it reaches here
    // untrusted and is clamped to the alphabet the request validation accepts.
    $rows = insertedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'date' => '2026-08-13', 'tracking_id' => 'a&b=c'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'date' => '2026-08-13', 'tracking_id' => str_repeat('x', 65)],
    ]);

    expect(array_column($rows, 6))->toBe(['', '']);
});

it('books each zone under its own metric, and an unknown one under the generic', function () {
    $video = videoOwnedBySomeone();

    // The zone is the only thing that separates a playback segment from a downloaded master, and
    // the old single-`bytes` column could not express it. A zone we do not recognise still counts:
    // the point of this pipeline is bandwidth, so an odd path costs its label, never its bytes.
    $rows = insertedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'zone' => 'play'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 20, 'zone' => 'download'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 30, 'zone' => 'assets'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 40, 'zone' => 'something-else'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 50],
    ]);

    expect(array_column($rows, 2))->toBe([
        'streaming_bytes', 'download_bytes', 'asset_bytes', 'bandwidth_bytes', 'bandwidth_bytes',
    ])->and(array_sum(array_column($rows, 9)))->toBe(150);
});

it('attributes every row to the integrator customer that owns the video', function () {
    // Resolved here from the video, never carried in the URL. That is what makes it unforgeable:
    // a viewer can rewrite a link however they like and the bytes still land on the right account.
    $video = videoOwnedBySomeone('cliente-77');

    $rows = insertedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'zone' => 'play'],
        ['video_ulid' => $video->ulid, 'ip' => '5.6.7.8', 'bytes' => 20, 'zone' => 'download'],
    ]);

    expect(array_column($rows, 3))->toBe(['cliente-77', 'cliente-77']);
});

it('leaves the customer empty for a video that no longer exists, without losing the bytes', function () {
    $rows = insertedRows([
        ['video_ulid' => OTHER_ULID, 'ip' => '1.2.3.4', 'bytes' => 42, 'zone' => 'play'],
    ]);

    expect($rows[0][1])->toBe(0)
        ->and($rows[0][3])->toBe('')
        ->and($rows[0][9])->toBe(42);
});

it('records which edge served the bytes and whether its cache had them', function () {
    $video = videoOwnedBySomeone();

    $rows = insertedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'zone' => 'play', 'node' => 3, 'cache' => 'HIT'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 20, 'zone' => 'play', 'node' => 3, 'cache' => 'miss'],
        // An edge from before the fields existed, and a word nginx never emits.
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 30, 'zone' => 'play'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 40, 'zone' => 'play', 'node' => 3, 'cache' => "HIT'); DROP"],
    ]);

    expect(array_column($rows, 7))->toBe([3, 3, 0, 3])
        ->and(array_column($rows, 8))->toBe(['HIT', 'MISS', '', '']);
});

it('books what the edge fetched from the origin as its own metric, under nobody\'s account', function () {
    // `value` is the one summed column, so the origin bytes cannot ride on the delivery row. They
    // are the operator's cost, not the customer's usage: account 0 is what `/api/usage` can never
    // be asked for, and the metric keeps them out of every bandwidth query.
    $video = videoOwnedBySomeone('cliente-77');

    $rows = insertedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 1000, 'zone' => 'play', 'node' => 3, 'cache' => 'MISS', 'origin' => 1010, 'tracking_id' => 'abc'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 1000, 'zone' => 'play', 'node' => 3, 'cache' => 'HIT', 'origin' => 0],
    ]);

    expect($rows)->toHaveCount(3)
        ->and($rows[1][1])->toBe(0)
        ->and($rows[1][2])->toBe(IngestBandwidthJob::ORIGIN_METRIC)
        ->and($rows[1][3])->toBe('')
        ->and($rows[1][4])->toBe($video->ulid)
        ->and($rows[1][6])->toBe('')
        ->and($rows[1][7])->toBe(3)
        ->and($rows[1][9])->toBe(1010)
        ->and($rows[2][2])->toBe('streaming_bytes');
});

it('resolves the tracking id from the token hash the edge sends', function () {
    $video = videoOwnedBySomeone();

    // The self-hosted edge logs the link's token and Vector ships its SHA-256; the id it was
    // minted for lives in the mapping the mint recorded, which only the API can reach. A hash
    // nothing was recorded under (expired, pre-feature, cache restart) costs the label, never
    // the bytes — and a hash-shaped value that is not one never becomes a cache key.
    $known = hash('sha256', 'exp=1~acl=/x/*~hmac=ff');
    Cache::put(TrackingRegistry::cacheKey($known), 'customer-a', 600);

    $rows = insertedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'zone' => 'play', 'token_hash' => $known],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 20, 'zone' => 'play', 'token_hash' => str_repeat('0', 64)],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 30, 'zone' => 'play', 'token_hash' => 'not-a-hash'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 40, 'zone' => 'play', 'token_hash' => ''],
    ]);

    expect(array_column($rows, 6))->toBe(['customer-a', '', '', ''])
        ->and(array_column($rows, 9))->toBe([10, 20, 30, 40]);
});

it('looks every hash of a batch up in one round trip', function () {
    $video = videoOwnedBySomeone();

    $a = hash('sha256', 'a');
    $b = hash('sha256', 'b');
    Cache::put(TrackingRegistry::cacheKey($a), 'customer-a', 600);
    Cache::put(TrackingRegistry::cacheKey($b), 'customer-b', 600);

    Cache::spy();
    Cache::shouldReceive('many')->once()->andReturn([
        TrackingRegistry::cacheKey($a) => 'customer-a',
        TrackingRegistry::cacheKey($b) => 'customer-b',
    ]);

    $rows = insertedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'zone' => 'play', 'token_hash' => $a],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'zone' => 'play', 'token_hash' => $a],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'zone' => 'play', 'token_hash' => $b],
    ]);

    expect(array_column($rows, 6))->toBe(['customer-a', 'customer-a', 'customer-b']);
});
