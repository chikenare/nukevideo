<?php

/**
 * The viewer's country is resolved once, at ingest, and stored on the row. What has to hold: a
 * lookup that cannot be answered costs the country and never the bytes; a row written before the
 * database existed is filled in rather than split; and a download that is not a database never
 * replaces the one being read.
 *
 * The fixture is MaxMind's own published test database (github.com/maxmind/MaxMind-DB), a few
 * documentation ranges with made-up locations.
 */

use App\Enums\MetricDimension;
use App\Jobs\IngestBandwidthJob;
use App\Jobs\RefreshGeoIpJob;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use App\Services\AnalyticsService;
use App\Services\GeoIp;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const GEO_FIXTURE = __DIR__.'/../../Fixtures/GeoLite2-Country-Test.mmdb';
const GB_IP = '81.2.69.142';
const SE_IP = '89.160.20.112';
const JP_IP6 = '2001:218::1';

function geoVideo(): Video
{
    $user = User::factory()->create();

    return Video::create([
        'user_id' => $user->id,
        'project_id' => Project::factory()->for($user)->create()->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => 'completed',
    ]);
}

/** A scratch directory for the download tests, and the path the database is expected at. */
function geoTarget(): string
{
    $dir = sys_get_temp_dir().'/geoip-test-'.uniqid();
    File::ensureDirectoryExists($dir);
    config(['services.maxmind' => ['account_id' => '42', 'license_key' => 'secret', 'database' => $dir.'/country.mmdb']]);

    return $dir.'/country.mmdb';
}

/** What MaxMind serves: a tar.gz with one dated directory holding the database. */
function geoArchive(string $database = GEO_FIXTURE): string
{
    $tar = sys_get_temp_dir().'/geoip-fixture-'.uniqid().'.tar';
    $archive = new PharData($tar);
    $archive->addFile($database, 'GeoLite2-Country_20261006/GeoLite2-Country.mmdb');
    $archive->addFromString('GeoLite2-Country_20261006/LICENSE.txt', 'licence');
    $archive->compress(Phar::GZ);
    unset($archive);

    $bytes = file_get_contents($tar.'.gz');
    File::delete([$tar, $tar.'.gz']);

    return $bytes;
}

it('resolves v4 and v6 addresses, and leaves out the ones it cannot place', function () {
    config(['services.maxmind.database' => GEO_FIXTURE]);

    expect(app(GeoIp::class)->countries([GB_IP, SE_IP, JP_IP6, '10.0.0.1', '', GB_IP]))
        ->toBe([GB_IP => 'GB', SE_IP => 'SE', JP_IP6 => 'JP']);
});

it('answers nothing, rather than failing, when there is no database', function () {
    expect(app(GeoIp::class)->countries([GB_IP]))->toBe([]);
});

it('answers nothing when the file is not a database', function () {
    $path = geoTarget();
    File::put($path, 'not a database');

    expect(app(GeoIp::class)->countries([GB_IP]))->toBe([]);
});

it('stores the country on the delivery row and on its origin row', function () {
    config(['services.maxmind.database' => GEO_FIXTURE]);
    $video = geoVideo();

    IngestBandwidthJob::dispatchSync([
        ['video_ulid' => $video->ulid, 'ip' => GB_IP, 'bytes' => 500, 'origin' => 200, 'date' => '2026-04-10'],
        ['video_ulid' => $video->ulid, 'ip' => '10.0.0.1', 'bytes' => 70, 'date' => '2026-04-10'],
    ]);

    expect(DB::table(UsageService::TABLE)->orderBy('value')->pluck('country', 'metric')->all())
        ->toBe(['bandwidth_bytes' => 'GB', 'origin_bytes' => 'GB'])
        ->and(DB::table(UsageService::TABLE)->where('ip', '10.0.0.1')->value('country'))->toBe('');
});

it('keeps the bytes when there is no database, with no country', function () {
    $video = geoVideo();

    IngestBandwidthJob::dispatchSync([['video_ulid' => $video->ulid, 'ip' => GB_IP, 'bytes' => 500]]);

    $row = DB::table(UsageService::TABLE)->sole();

    expect((int) $row->value)->toBe(500)->and($row->country)->toBe('');
});

it('fills a row written without a country in, instead of splitting it in two', function () {
    $video = geoVideo();
    $event = ['video_ulid' => $video->ulid, 'ip' => GB_IP, 'bytes' => 500, 'date' => '2026-04-10'];

    IngestBandwidthJob::dispatchSync([$event]);

    config(['services.maxmind.database' => GEO_FIXTURE]);
    IngestBandwidthJob::dispatchSync([$event]);

    $row = DB::table(UsageService::TABLE)->sole();

    expect((int) $row->value)->toBe(1000)->and($row->country)->toBe('GB');
});

it('never blanks a country a later write does not know', function () {
    $row = ['date' => '2026-04-10', 'metric' => 'streaming_bytes', 'ip' => GB_IP, 'value' => 1];

    UsageService::add([$row + ['country' => 'GB']]);
    UsageService::add([$row]);
    UsageService::add([$row + ['country' => 'SE']]);

    expect(DB::table(UsageService::TABLE)->sole()->country)->toBe('GB');
});

it('breaks delivered bytes down by country', function () {
    UsageService::add([
        ['date' => '2026-04-10', 'metric' => 'streaming_bytes', 'project_id' => 7, 'ip' => GB_IP, 'country' => 'GB', 'value' => 100],
        ['date' => '2026-04-10', 'metric' => 'streaming_bytes', 'project_id' => 7, 'ip' => '81.2.69.143', 'country' => 'GB', 'value' => 50],
        ['date' => '2026-04-10', 'metric' => 'streaming_bytes', 'project_id' => 7, 'ip' => SE_IP, 'country' => 'SE', 'value' => 30],
        ['date' => '2026-04-10', 'metric' => 'streaming_bytes', 'project_id' => 8, 'ip' => SE_IP, 'country' => 'SE', 'value' => 999],
    ]);

    $rows = app(AnalyticsService::class)->query('2026-04-01', '2026-04-30', [MetricDimension::COUNTRY], projectId: 7);

    expect(array_map(fn (array $row) => [$row['country'], (int) $row['value']], $rows))
        ->toBe([['GB', 150], ['SE', 30]]);
});

it('refuses a breakdown by country that is not narrowed to a project or to videos', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/metrics?from=2026-04-01&to=2026-04-30&dimensions[]=country')
        ->assertStatus(422)
        ->assertJsonValidationErrors('videos');
});

it('downloads the database with the account credentials and puts it in place', function () {
    $path = geoTarget();
    Http::fake(['download.maxmind.com/*' => Http::response(geoArchive())]);

    expect(app(GeoIp::class)->refresh())->toBeTrue()
        ->and(app(GeoIp::class)->countries([GB_IP]))->toBe([GB_IP => 'GB'])
        // Nothing of the download is left behind next to it.
        ->and(File::files(dirname($path)))->toHaveCount(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/geoip/databases/GeoLite2-Country/download?suffix=tar.gz')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('42:secret')));
});

it('downloads with the key alone when there is no account id', function () {
    geoTarget();
    config(['services.maxmind.account_id' => null]);
    Http::fake(['download.maxmind.com/*' => Http::response(geoArchive())]);

    expect(app(GeoIp::class)->refresh())->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/app/geoip_download?edition_id=GeoLite2-Country&license_key=secret')
        && ! $request->hasHeader('Authorization'));
});

it('asks for the database from the ingest when it is missing, once', function () {
    geoTarget();
    Queue::fake([RefreshGeoIpJob::class]);
    $video = geoVideo();
    $event = ['video_ulid' => $video->ulid, 'ip' => GB_IP, 'bytes' => 500];

    IngestBandwidthJob::dispatchSync([$event]);
    IngestBandwidthJob::dispatchSync([$event]);

    Queue::assertPushedOn(RefreshGeoIpJob::QUEUE, RefreshGeoIpJob::class);
    Queue::assertPushed(RefreshGeoIpJob::class, 1);
});

it('does not ask for a database it has, nor for one it has no key for', function (Closure $arrange) {
    $path = geoTarget();
    $arrange($path);
    Queue::fake([RefreshGeoIpJob::class]);

    IngestBandwidthJob::dispatchSync([['video_ulid' => geoVideo()->ulid, 'ip' => GB_IP, 'bytes' => 500]]);

    Queue::assertNotPushed(RefreshGeoIpJob::class);
})->with([
    'present' => [fn (string $path) => File::copy(GEO_FIXTURE, $path)],
    'no key' => [fn () => config(['services.maxmind.license_key' => null])],
]);

it('does nothing without a license key', function () {
    geoTarget();
    config(['services.maxmind.license_key' => null]);
    Http::fake();

    expect(app(GeoIp::class)->refresh())->toBeFalse();
    Http::assertNothingSent();
});

it('keeps the database it has when the download is not one', function (Closure $response) {
    $path = geoTarget();
    File::copy(GEO_FIXTURE, $path);
    Http::fake(['download.maxmind.com/*' => $response()]);

    expect(fn () => app(GeoIp::class)->refresh())->toThrow(Exception::class)
        ->and(app(GeoIp::class)->countries([GB_IP]))->toBe([GB_IP => 'GB'])
        ->and(File::files(dirname($path)))->toHaveCount(1);
})->with([
    'an error status' => [fn () => Http::response('Invalid license key', 401)],
    'a body that is not an archive' => [fn () => Http::response('<html>maintenance</html>')],
    'an archive holding something else' => [fn () => Http::response(geoArchive(__FILE__))],
]);
