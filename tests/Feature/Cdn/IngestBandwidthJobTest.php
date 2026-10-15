<?php

/**
 * A row in `usage_daily` is summed into its day's total, so one landing on the wrong day — or under
 * the wrong account, metric or viewer — is not something a later correction can pick back out. The
 * ingest runs on a timer, so a batch that straddles midnight used to book the tail of one day to
 * the next; the events now carry the day the traffic actually happened. This pins that, the metric
 * each zone lands under, the attribution resolved from the video, the input filtering, and the
 * folding of rows that share their dimensions.
 */

use App\Jobs\IngestBandwidthJob;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use App\Services\Cdn\TrackingRegistry;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const OTHER_ULID = '01HZXW3V5N8Q9R2T4Y6B8D0F9Z';

/**
 * Runs the job and returns what the table holds afterwards, smallest value first — the write
 * orders rows by key, so the order the events came in says nothing about the order they land in,
 * and the cases below give every row its own byte count to be told apart by.
 *
 * @return Collection<int, array<string, mixed>>
 */
function storedRows(array $events): Collection
{
    IngestBandwidthJob::dispatchSync($events);

    return DB::table(UsageService::TABLE)->orderBy('value')->orderBy('id')->get()->map(fn (object $row) => [
        ...(array) $row,
        // SQLite hands a double back as a float and MariaDB an unsigned as it pleases; the
        // assertions below are about the numbers.
        'value' => (int) $row->value,
        'requests' => (int) $row->requests,
        'user_id' => (int) $row->user_id,
        'project_id' => (int) $row->project_id,
        'node_id' => (int) $row->node_id,
    ]);
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

    $rows = storedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 500, 'date' => '2026-01-31'],
    ]);

    expect($rows)->toHaveCount(1)
        ->and(Arr::except($rows[0], 'id'))->toBe([
            'date' => '2026-01-31',
            'project_id' => $video->project_id,
            'user_id' => $video->user_id,
            'metric' => 'bandwidth_bytes',
            'external_user_id' => '',
            'video_ulid' => $video->ulid,
            'ip' => '1.2.3.4',
            'country' => '',
            'tracking_id' => '',
            'node_id' => 0,
            'cache' => '',
            'value' => 500,
            'requests' => 1,
        ]);
});

it('accepts a full timestamp and keeps only its day', function () {
    $video = videoOwnedBySomeone();

    $rows = storedRows([
        ['video_ulid' => $video->ulid, 'ip' => '::1', 'bytes' => 10, 'timestamp' => '2026-01-31T23:59:12Z'],
    ]);

    expect($rows[0]['date'])->toBe('2026-01-31');
});

it('falls back to today for an event from a producer that sends no date', function () {
    $video = videoOwnedBySomeone();

    $rows = storedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10],
    ]);

    expect($rows[0]['date'])->toBe(now()->format('Y-m-d'));
});

it('drops an unusable row and keeps the rest of the batch', function () {
    // '' and '-' are what a log line carries when it had no address; a row with no viewer behind
    // it would only inflate the distinct counts.
    $video = videoOwnedBySomeone();

    $rows = storedRows([
        ['video_ulid' => $video->ulid, 'ip' => '', 'bytes' => 100],
        ['video_ulid' => $video->ulid, 'ip' => '-', 'bytes' => 100],
        ['video_ulid' => 'not-a-ulid', 'ip' => '1.2.3.4', 'bytes' => 100],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 0],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 700],
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['value'])->toBe(700);
});

it('sums events that share every dimension into one row, and counts them', function () {
    // Rows are folded at write time: a session flushed once a minute names the same row every
    // time, and the table holds one row for it, not one per flush.
    $video = videoOwnedBySomeone();
    $event = ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 100, 'date' => '2026-08-13', 'zone' => 'play'];

    // Twice in one batch, with the request count the edge summed, and once more in a later one.
    storedRows([$event + ['requests' => 4], $event + ['requests' => 6]]);
    $rows = storedRows([$event]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['value'])->toBe(300)
        ->and($rows[0]['requests'])->toBe(11);
});

it('stores one spelling of an address, however the edge wrote it', function () {
    // `ip` is part of the row's key, so two spellings of one address are two rows — and one viewer
    // counted twice by every distinct count.
    $video = videoOwnedBySomeone();

    $rows = storedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'date' => '2026-08-13'],
        ['video_ulid' => $video->ulid, 'ip' => '::ffff:1.2.3.4', 'bytes' => 10, 'date' => '2026-08-13'],
        ['video_ulid' => $video->ulid, 'ip' => '2001:DB8:0:0:0:0:0:1', 'bytes' => 5, 'date' => '2026-08-13'],
        ['video_ulid' => $video->ulid, 'ip' => '2001:db8::1', 'bytes' => 5, 'date' => '2026-08-13'],
    ]);

    expect($rows->pluck('value', 'ip')->all())->toBe(['2001:db8::1' => 10, '1.2.3.4' => 20]);
});

it('attributes traffic for a video that no longer exists to no user', function () {
    $rows = storedRows([
        ['video_ulid' => OTHER_ULID, 'ip' => '1.2.3.4', 'bytes' => 42],
    ]);

    expect($rows[0]['user_id'])->toBe(0);
});

it('keeps traffic from different tracking ids in separate rows', function () {
    $video = videoOwnedBySomeone();

    // `tracking_id` is part of the key rows are summed on, so two subscribers' bytes stay apart —
    // including two ids that differ only in case, which the default collation would have folded.
    $rows = storedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'date' => '2026-08-13', 'tracking_id' => 'customer-a'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 20, 'date' => '2026-08-13', 'tracking_id' => 'Customer-A'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 30, 'date' => '2026-08-13'],
    ]);

    expect($rows->pluck('tracking_id')->all())->toBe(['customer-a', 'Customer-A', '']);
});

it('blanks a tracking id that did not survive the round trip intact', function () {
    $video = videoOwnedBySomeone();

    // A `tracking_id` sent as-is is text that crossed a log and a queue, so it reaches here
    // untrusted and is clamped to the alphabet the request validation accepts.
    $rows = storedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'date' => '2026-08-13', 'tracking_id' => 'a&b=c'],
        ['video_ulid' => $video->ulid, 'ip' => '5.6.7.8', 'bytes' => 20, 'date' => '2026-08-13', 'tracking_id' => str_repeat('x', 65)],
    ]);

    expect($rows->pluck('tracking_id')->all())->toBe(['', '']);
});

it('books each zone under its own metric, and an unknown one under the generic', function () {
    $video = videoOwnedBySomeone();

    // The zone is the only thing that separates a playback segment from a downloaded master. A
    // zone we do not recognise still counts: the point of this pipeline is bandwidth, so an odd
    // path costs its label, never its bytes.
    $rows = storedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'zone' => 'play'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 20, 'zone' => 'download'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 30, 'zone' => 'assets'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 40, 'zone' => 'something-else'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 50],
    ]);

    expect($rows->pluck('value', 'metric')->all())->toBe([
        'streaming_bytes' => 10, 'download_bytes' => 20, 'asset_bytes' => 30, 'bandwidth_bytes' => 90,
    ]);
});

it('attributes every row to the integrator customer that owns the video', function () {
    // Resolved here from the video, never carried in the URL. That is what makes it unforgeable:
    // a viewer can rewrite a link however they like and the bytes still land on the right account.
    $video = videoOwnedBySomeone('cliente-77');

    $rows = storedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'zone' => 'play'],
        ['video_ulid' => $video->ulid, 'ip' => '5.6.7.8', 'bytes' => 20, 'zone' => 'download'],
    ]);

    expect($rows->pluck('external_user_id')->all())->toBe(['cliente-77', 'cliente-77']);
});

it('leaves the customer empty for a video that no longer exists, without losing the bytes', function () {
    $rows = storedRows([
        ['video_ulid' => OTHER_ULID, 'ip' => '1.2.3.4', 'bytes' => 42, 'zone' => 'play'],
    ]);

    expect($rows[0]['user_id'])->toBe(0)
        ->and($rows[0]['external_user_id'])->toBe('')
        ->and($rows[0]['value'])->toBe(42);
});

it('records which edge served the bytes and whether its cache had them', function () {
    $video = videoOwnedBySomeone();

    $rows = storedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'zone' => 'play', 'node' => 3, 'cache' => 'HIT'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 20, 'zone' => 'play', 'node' => 3, 'cache' => 'miss'],
        // An edge from before the fields existed, and a word nginx never emits.
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 30, 'zone' => 'play'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 40, 'zone' => 'play', 'node' => 3, 'cache' => "HIT'); DROP"],
    ]);

    expect($rows->pluck('node_id')->all())->toBe([3, 3, 0, 3])
        ->and($rows->pluck('cache')->all())->toBe(['HIT', 'MISS', '', '']);
});

it('books what the edge fetched from the origin as its own metric, under nobody\'s account', function () {
    // `value` is the one summed column, so the origin bytes cannot ride on the delivery row. They
    // are the operator's cost, not the customer's usage: account 0 is what `/api/usage` can never
    // be asked for, and the metric keeps them out of every bandwidth query.
    $video = videoOwnedBySomeone('cliente-77');

    $rows = storedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 1000, 'zone' => 'play', 'node' => 3, 'cache' => 'MISS', 'origin' => 1010, 'tracking_id' => 'abc'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 999, 'zone' => 'play', 'node' => 3, 'cache' => 'HIT', 'origin' => 0],
    ]);

    $origin = $rows->firstWhere('metric', IngestBandwidthJob::ORIGIN_METRIC);

    expect($rows)->toHaveCount(3)
        ->and($origin['user_id'])->toBe(0)
        ->and($origin['external_user_id'])->toBe('')
        ->and($origin['video_ulid'])->toBe($video->ulid)
        ->and($origin['tracking_id'])->toBe('')
        ->and($origin['node_id'])->toBe(3)
        ->and($origin['value'])->toBe(1010)
        // The requests are the ones already counted on the delivery row.
        ->and($origin['requests'])->toBe(0)
        ->and($rows->where('metric', 'streaming_bytes'))->toHaveCount(2);
});

it('resolves the tracking id from the token hash the edge sends', function () {
    $video = videoOwnedBySomeone();

    // The self-hosted edge logs the link's token and Vector ships its SHA-256; the id it was
    // minted for lives in the mapping the mint recorded, which only the API can reach. A hash
    // nothing was recorded under (expired, pre-feature, cache restart) costs the label, never
    // the bytes — and a hash-shaped value that is not one never becomes a cache key.
    $known = hash('sha256', 'exp=1~acl=/x/*~hmac=ff');
    Cache::put(TrackingRegistry::cacheKey($known), 'customer-a', 600);

    $rows = storedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'zone' => 'play', 'token_hash' => $known],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 20, 'zone' => 'play', 'token_hash' => str_repeat('0', 64)],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 30, 'zone' => 'play', 'token_hash' => 'not-a-hash'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 40, 'zone' => 'play', 'token_hash' => ''],
    ]);

    // The three unattributed events are one viewer on one video, so they are one row.
    expect($rows->pluck('value', 'tracking_id')->all())->toBe(['customer-a' => 10, '' => 90]);
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

    $rows = storedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'zone' => 'play', 'token_hash' => $a],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'zone' => 'play', 'token_hash' => $a],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 5, 'zone' => 'play', 'token_hash' => $b],
    ]);

    expect($rows->pluck('value', 'tracking_id')->all())->toBe(['customer-b' => 5, 'customer-a' => 20]);
});

it('attributes every row to the project that owns the video', function () {
    // The only column that can narrow this table to one tenant: an account holds many projects, so
    // `user_id` alone lets a project API key read every sibling project's traffic. Resolved from
    // the video like the account is, off the same lookup, so it is no more forgeable.
    $video = videoOwnedBySomeone('cliente-77');

    $rows = storedRows([
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'zone' => 'play'],
        ['video_ulid' => $video->ulid, 'ip' => '5.6.7.8', 'bytes' => 20, 'zone' => 'download'],
    ]);

    expect($rows->pluck('project_id')->all())->toBe([$video->project_id, $video->project_id]);
});

it('leaves the project empty for traffic it cannot attribute', function () {
    // A deleted video takes its project with it, and origin egress never had one: it is the
    // operator's cost, and must not fall inside any tenant's project-scoped read.
    $video = videoOwnedBySomeone();

    $rows = storedRows([
        ['video_ulid' => OTHER_ULID, 'ip' => '1.2.3.4', 'bytes' => 42, 'zone' => 'play'],
        ['video_ulid' => $video->ulid, 'ip' => '1.2.3.4', 'bytes' => 10, 'zone' => 'play', 'node' => 3, 'cache' => 'MISS', 'origin' => 99],
    ]);

    expect($rows->firstWhere('video_ulid', OTHER_ULID)['project_id'])->toBe(0)
        ->and($rows->firstWhere('metric', IngestBandwidthJob::ORIGIN_METRIC)['project_id'])->toBe(0);
});
