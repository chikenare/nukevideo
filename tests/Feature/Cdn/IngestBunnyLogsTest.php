<?php

use App\Jobs\IngestBandwidthJob;
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

function bunnyLogLine(string $ulid, string $ip, int $bytes): array
{
    return [
        'timestamp' => '2026-01-01T00:00:00Z',
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
        return $job->events === [
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

    Queue::assertPushed(IngestBandwidthJob::class, fn (IngestBandwidthJob $job) => $job->events === [
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
