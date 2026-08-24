<?php

use App\Jobs\IngestBandwidthJob;
use App\Services\Cdn\BunnyProvider;
use App\Settings\CdnSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const BUNNY_LOGS_URL = 'logging.bunnycdn.com/v2/pullzones/*';
const ULID_A = '01HZXW3V5N8Q9R2T4Y6B8D0F1G';
const ULID_B = '01HZXW3V5N8Q9R2T4Y6B8D0F2H';

function fakeBunnySettings(string $provider = 'bunny', string $apiKey = 'api-key', string $pullZoneId = '4242'): void
{
    CdnSettings::fake([
        'provider' => $provider,
        'providers' => [
            'self_hosted' => [],
            'bunny' => [
                'host' => 'cdn.example.com',
                'token_key' => 'k',
                'token_window' => 3600,
                'api_key' => $apiKey,
                'pull_zone_id' => $pullZoneId,
            ],
        ],
    ]);
}

/**
 * The aggregated events with `date` dropped, so a case can assert the aggregation itself without
 * pinning the clock. The date is covered separately, where it is the point of the assertion.
 *
 * @return array<int, array<string, mixed>>
 */
function eventsWithoutDate(IngestBandwidthJob $job): array
{
    // `tid` and `zone` are dropped alongside `date`: these assertions are about aggregation shape,
    // and each is covered by its own case below.
    return array_map(
        fn (array $event) => collect($event)->except(['date', 'tracking_id', 'zone'])->all(),
        array_values($job->events),
    );
}

function bunnyLogLine(string $ulid, string $ip, int $bytes, string $timestamp = '2026-01-01T00:00:00Z'): array
{
    return [
        'timestamp' => $timestamp,
        'statusCode' => 200,
        'bytesSent' => $bytes,
        'remoteIp' => $ip,
        'path' => "/bcdn_token=abc&expires=123/{$ulid}/segment_00001.m4s",
    ];
}

function bunnyLogsPage(array $lines, bool $hasMore = false): array
{
    return [
        'data' => $lines,
        'pagination' => ['returned' => count($lines), 'hasMore' => $hasMore],
    ];
}

beforeEach(function () {
    Carbon::setTestNow('2026-01-01 01:00:00');
    Queue::fake();
});
afterEach(fn () => Carbon::setTestNow());

it('does nothing when the Bunny driver is not active', function () {
    fakeBunnySettings(provider: 'self_hosted');
    Http::fake();

    $this->artisan('bunny:ingest-logs')->assertExitCode(0);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('does nothing when the logging credentials are not configured', function () {
    fakeBunnySettings(apiKey: '');
    Http::fake();

    $this->artisan('bunny:ingest-logs')->assertExitCode(0);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('aggregates log lines by video and ip and dispatches the ingest job', function () {
    fakeBunnySettings();
    Http::fake([BUNNY_LOGS_URL => Http::response(bunnyLogsPage([
        bunnyLogLine(ULID_A, '1.2.3.4', 1000),
        bunnyLogLine(ULID_A, '1.2.3.4', 500),
        bunnyLogLine(ULID_A, '5.6.7.8', 200),
        bunnyLogLine(ULID_B, '1.2.3.4', 300),
        ['statusCode' => 200, 'bytesSent' => 999, 'remoteIp' => '1.2.3.4', 'path' => '/favicon.ico'],
        ['statusCode' => 200, 'bytesSent' => 999, 'remoteIp' => '', 'path' => '/'.ULID_A.'/x.m4s'],
    ]))]);

    $this->artisan('bunny:ingest-logs')->assertExitCode(0);

    Http::assertSent(fn ($request) => $request->hasHeader('AccessKey', 'api-key')
        && str_contains($request->url(), '/v2/pullzones/4242/logs')
        && $request['status'] === '2xx');

    Queue::assertPushed(IngestBandwidthJob::class, function (IngestBandwidthJob $job) {
        // Every event carries the day the traffic happened, not the day it was ingested: the run
        // just after midnight reads the tail of the previous day, and `date` is what partitions
        // the ClickHouse table these land in. Which day, per line, is asserted separately below.
        expect($job->events)->each->toHaveKey('date');

        return eventsWithoutDate($job) === [
            ['video_ulid' => ULID_A, 'ip' => '1.2.3.4', 'bytes' => 1500],
            ['video_ulid' => ULID_A, 'ip' => '5.6.7.8', 'bytes' => 200],
            ['video_ulid' => ULID_B, 'ip' => '1.2.3.4', 'bytes' => 300],
        ];
    });
});

it('walks pagination until the API reports no more entries', function () {
    fakeBunnySettings();
    Http::fake([BUNNY_LOGS_URL => Http::sequence()
        ->push(bunnyLogsPage([bunnyLogLine(ULID_A, '1.2.3.4', 100)], hasMore: true))
        ->push(bunnyLogsPage([bunnyLogLine(ULID_A, '1.2.3.4', 50)]))]);

    $this->artisan('bunny:ingest-logs')->assertExitCode(0);

    Queue::assertPushed(IngestBandwidthJob::class, fn (IngestBandwidthJob $job) => eventsWithoutDate($job) === [
        ['video_ulid' => ULID_A, 'ip' => '1.2.3.4', 'bytes' => 150],
    ]);
});

it('advances the cursor so the next run starts where the last one ended', function () {
    fakeBunnySettings();
    Http::fake([BUNNY_LOGS_URL => Http::response(bunnyLogsPage([]))]);

    $this->artisan('bunny:ingest-logs')->assertExitCode(0);

    // First run without a cursor: window is [to - 600s, now - 120s].
    $to = now()->subSeconds(120)->toIso8601ZuluString();
    Http::assertSent(fn ($request) => $request['from'] === now()->subSeconds(720)->toIso8601ZuluString()
        && $request['to'] === $to);
    expect(Cache::get('bunny-ingest-logs:cursor'))->toBe($to);

    Carbon::setTestNow('2026-01-01 01:05:00');
    $this->artisan('bunny:ingest-logs')->assertExitCode(0);

    Http::assertSent(fn ($request) => $request['from'] === $to
        && $request['to'] === now()->subSeconds(120)->toIso8601ZuluString());
});

it('keeps the cursor and fails when the API errors, so the window is retried', function () {
    fakeBunnySettings();
    Http::fake([BUNNY_LOGS_URL => Http::response(null, 500)]);

    $this->artisan('bunny:ingest-logs')->assertExitCode(1);

    Queue::assertNothingPushed();
    expect(Cache::get('bunny-ingest-logs:cursor'))->toBeNull();
});

it('dates every event by its own log line, so a window that straddles midnight splits', function () {
    fakeBunnySettings();

    // A window can span a midnight: `--from`, or a cursor recovered after an outage. `date` is the
    // partition key of a SummingMergeTree, so stamping the whole window with its start would book
    // post-midnight traffic to the previous day with no way back.
    Http::fake([BUNNY_LOGS_URL => Http::response(bunnyLogsPage([
        bunnyLogLine(ULID_A, '1.2.3.4', 100, '2026-01-01T23:59:59+00:00'),
        bunnyLogLine(ULID_A, '1.2.3.4', 40, '2026-01-02T00:00:01+00:00'),
        bunnyLogLine(ULID_A, '1.2.3.4', 60, '2026-01-02T00:30:00+00:00'),
    ]))]);

    Carbon::setTestNow('2026-01-02 01:00:00');

    $this->artisan('bunny:ingest-logs', ['--from' => '2026-01-01T23:00:00Z'])->assertExitCode(0);

    Queue::assertPushed(IngestBandwidthJob::class, function (IngestBandwidthJob $job) {
        return array_values($job->events) === [
            ['video_ulid' => ULID_A, 'ip' => '1.2.3.4', 'bytes' => 100, 'date' => '2026-01-01', 'tracking_id' => '', 'zone' => ''],
            ['video_ulid' => ULID_A, 'ip' => '1.2.3.4', 'bytes' => 100, 'date' => '2026-01-02', 'tracking_id' => '', 'zone' => ''],
        ];
    });
});

it('falls back to the window date only when the line carries no timestamp', function () {
    fakeBunnySettings();
    Http::fake([BUNNY_LOGS_URL => Http::response(bunnyLogsPage([
        ['statusCode' => 200, 'bytesSent' => 100, 'remoteIp' => '1.2.3.4', 'path' => '/'.ULID_A.'/play/x.m4s'],
    ]))]);

    $this->artisan('bunny:ingest-logs')->assertExitCode(0);

    Queue::assertPushed(IngestBandwidthJob::class, fn (IngestBandwidthJob $job) => array_values($job->events)[0]['date'] === now()->subSeconds(720)->toDateString());
});

it('drops non-2xx lines even when the API filter does not', function () {
    fakeBunnySettings();

    // `status=2xx` is a server-side filter and the only thing between an error page's bytes and a
    // customer's bill. A parameter the API renames or ignores must not silently start billing the
    // 403s an expired token produces.
    Http::fake([BUNNY_LOGS_URL => Http::response(bunnyLogsPage([
        ['statusCode' => 403, 'bytesSent' => 2538, 'remoteIp' => '1.2.3.4', 'path' => '/'.ULID_A.'/play/x.m4s?tid=spoofed'],
        ['statusCode' => 404, 'bytesSent' => 1207, 'remoteIp' => '1.2.3.4', 'path' => '/'.ULID_A.'/download/video/x.mp4'],
        // 206 is what a resumed download or a ranged player request reports, and Bunny's own
        // `2xx` filter returns it. It has to count.
        ['statusCode' => 206, 'bytesSent' => 700, 'remoteIp' => '1.2.3.4', 'path' => '/'.ULID_A.'/download/video/x.mp4'],
    ]))]);

    $this->artisan('bunny:ingest-logs')->assertExitCode(0);

    Queue::assertPushed(IngestBandwidthJob::class, fn (IngestBandwidthJob $job) => eventsWithoutDate($job) === [
        ['video_ulid' => ULID_A, 'ip' => '1.2.3.4', 'bytes' => 700],
    ]);
});

it('attributes traffic to the tracking id carried in the logged query string', function () {
    fakeBunnySettings();

    // The v2 logging API reports `path` WITH the query string, which is the only reason a download
    // link's `tid` can be attributed at all. Two ids must not collapse into one row.
    Http::fake([BUNNY_LOGS_URL => Http::response(bunnyLogsPage([
        ['statusCode' => 200, 'bytesSent' => 100, 'remoteIp' => '1.2.3.4', 'path' => '/'.ULID_A.'/download/video/x.mp4?token=t&expires=1&tid=customer-a'],
        ['statusCode' => 200, 'bytesSent' => 50, 'remoteIp' => '1.2.3.4', 'path' => '/'.ULID_A.'/download/video/x.mp4?token=t&expires=1&tid=customer-b'],
        ['statusCode' => 200, 'bytesSent' => 25, 'remoteIp' => '1.2.3.4', 'path' => '/'.ULID_A.'/play/x.mpd'],
    ]))]);

    $this->artisan('bunny:ingest-logs')->assertExitCode(0);

    Queue::assertPushed(IngestBandwidthJob::class, function (IngestBandwidthJob $job) {
        $byTid = collect($job->events)->keyBy('tracking_id')->map->bytes;

        return $byTid->get('customer-a') === 100
            && $byTid->get('customer-b') === 50
            && $byTid->get('') === 25;
    });
});

it('counts the bytes of a tracking id that did not survive the round trip, as unattributed', function () {
    fakeBunnySettings();

    // The point of the whole pipeline is bandwidth. A malformed id is a broken LABEL, never a
    // reason to drop the line: those bytes were really delivered and really cost money, and
    // `usage` is a SummingMergeTree where a dropped row is gone for good.
    Http::fake([BUNNY_LOGS_URL => Http::response(bunnyLogsPage([
        ['statusCode' => 200, 'bytesSent' => 70, 'remoteIp' => '1.2.3.4', 'path' => '/'.ULID_A.'/download/video/x.mp4?tid=a%26b%3Dc'],
        ['statusCode' => 200, 'bytesSent' => 30, 'remoteIp' => '1.2.3.4', 'path' => '/'.ULID_A.'/download/video/x.mp4?tid='.str_repeat('x', 65)],
    ]))]);

    $this->artisan('bunny:ingest-logs')->assertExitCode(0);

    Queue::assertPushed(IngestBandwidthJob::class, fn (IngestBandwidthJob $job) => eventsWithoutDate($job) === [
        ['video_ulid' => ULID_A, 'ip' => '1.2.3.4', 'bytes' => 100],
    ]);
});

it('reads the delivery zone out of the logged path', function () {
    fakeBunnySettings();

    // `play` vs `download` is what separates streaming bytes from downloaded ones once the batch
    // reaches ClickHouse, and the log path is the only place that distinction survives. The zone
    // is anchored on the ULID's own position, so the `bcdn_token=` prefix cannot be mistaken for it.
    Http::fake([BUNNY_LOGS_URL => Http::response(bunnyLogsPage([
        ['statusCode' => 200, 'bytesSent' => 10, 'remoteIp' => '1.2.3.4', 'path' => '/bcdn_token=abc&token_path=%2Fplay%2F&expires=1/'.ULID_A.'/play/chunk-0001.m4s'],
        ['statusCode' => 200, 'bytesSent' => 20, 'remoteIp' => '1.2.3.4', 'path' => '/'.ULID_A.'/download/video/x.mp4?token=t'],
        ['statusCode' => 200, 'bytesSent' => 30, 'remoteIp' => '1.2.3.4', 'path' => '/'.ULID_A.'/assets/thumb.jpg'],
    ]))]);

    $this->artisan('bunny:ingest-logs')->assertExitCode(0);

    Queue::assertPushed(IngestBandwidthJob::class, function (IngestBandwidthJob $job) {
        return collect($job->events)->pluck('bytes', 'zone')->all() === [
            'play' => 10,
            'download' => 20,
            'assets' => 30,
        ];
    });
});

it('keeps the zones apart instead of summing them into one row', function () {
    fakeBunnySettings();
    Http::fake([BUNNY_LOGS_URL => Http::response(bunnyLogsPage([
        ['statusCode' => 200, 'bytesSent' => 10, 'remoteIp' => '1.2.3.4', 'path' => '/'.ULID_A.'/play/a.m4s'],
        ['statusCode' => 200, 'bytesSent' => 5, 'remoteIp' => '1.2.3.4', 'path' => '/'.ULID_A.'/play/b.m4s'],
        ['statusCode' => 200, 'bytesSent' => 20, 'remoteIp' => '1.2.3.4', 'path' => '/'.ULID_A.'/download/video/x.mp4'],
    ]))]);

    $this->artisan('bunny:ingest-logs')->assertExitCode(0);

    Queue::assertPushed(IngestBandwidthJob::class, fn (IngestBandwidthJob $job) => collect($job->events)->pluck('bytes', 'zone')->all() === [
        'play' => 15,
        'download' => 20,
    ]);
});

it('attributes playback lines through the minted token mapping', function () {
    fakeBunnySettings();

    // Playback links carry no `tid` — the directory token leaves no room for one — but the token
    // prefix itself reaches the log on every segment, and the mint recorded what it means
    // ({@see \App\Services\Cdn\BunnyProvider::trackingCacheKey}). An unmapped token (expired,
    // pre-feature, cache restart) costs the label, never the bytes.
    Cache::put(BunnyProvider::trackingCacheKey('HS256-known_token'), 'customer-42', 600);

    Http::fake([BUNNY_LOGS_URL => Http::response(bunnyLogsPage([
        ['statusCode' => 200, 'bytesSent' => 100, 'remoteIp' => '1.2.3.4', 'path' => '/bcdn_token=HS256-known_token&token_path=%2F'.ULID_A.'%2Fplay%2F&expires=123/'.ULID_A.'/play/segment_00001.m4s'],
        ['statusCode' => 200, 'bytesSent' => 40, 'remoteIp' => '1.2.3.4', 'path' => '/bcdn_token=HS256-known_token&token_path=%2F'.ULID_A.'%2Fplay%2F&expires=123/'.ULID_A.'/play/segment_00002.m4s'],
        ['statusCode' => 200, 'bytesSent' => 25, 'remoteIp' => '1.2.3.4', 'path' => '/bcdn_token=HS256-unknown&token_path=%2F'.ULID_A.'%2Fplay%2F&expires=123/'.ULID_A.'/play/segment_00001.m4s'],
    ]))]);

    $this->artisan('bunny:ingest-logs')->assertExitCode(0);

    Queue::assertPushed(IngestBandwidthJob::class, function (IngestBandwidthJob $job) {
        $byTid = collect($job->events)->keyBy('tracking_id')->map->bytes;

        return $byTid->get('customer-42') === 140
            && $byTid->get('') === 25;
    });
});
