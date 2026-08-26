<?php

use App\Models\Video;
use App\Services\Cdn\BunnyProvider;
use App\Settings\CdnSettings;
use Illuminate\Support\Carbon;

/**
 * Split a directory-mode Bunny URL into its token block and the trailing path.
 * Shape: https://host/bcdn_token=...&token_path=...&expires=.../{path}
 *
 * @return array{0: array<string, string>, 1: string}
 */
function bunnyDirParts(string $url): array
{
    $rest = substr($url, strlen('https://cdn.example.com/'));
    [$block, $tail] = explode('/', $rest, 2);
    parse_str($block, $parts);

    return [$parts, '/'.$tail];
}

function fakeCdnSettings(string $tokenKey = 'test-key'): void
{
    CdnSettings::fake([
        'provider' => 'bunny',
        'providers' => [
            'self_hosted' => [],
            'bunny' => [
                'host' => 'cdn.example.com',
                'token_key' => $tokenKey,
                'token_window' => 3600,
            ],
        ],
    ]);
}

beforeEach(fn () => Carbon::setTestNow('2026-01-01 00:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('embeds the token in the path, scoped to the manifest directory', function () {
    fakeCdnSettings();

    $link = app(BunnyProvider::class)->manifestUrl(new Video, 'vid-ulid/out-ulid.mpd', '1.2.3.4', false);
    $url = $link->url;

    expect($url)->toStartWith('https://cdn.example.com/bcdn_token=HS256-')
        ->and($url)->toEndWith('/vid-ulid/out-ulid.mpd');

    [$parts, $tail] = bunnyDirParts($url);
    expect($parts['token_path'])->toBe('/vid-ulid/')
        ->and((int) $parts['expires'])->toBe(Carbon::now()->timestamp + 3600)
        ->and($tail)->toBe('/vid-ulid/out-ulid.mpd')
        // The token handed back is the one in the URL, byte for byte: the ingest hashes what the
        // log shows, so the mint has to have hashed the same thing.
        ->and($link->token)->toBe($parts['bcdn_token'])
        ->and($link->tokenHash)->toBe(hash('sha256', $parts['bcdn_token']));
});

it('computes an HMAC-SHA256 token from token_path only, without the IP', function () {
    fakeCdnSettings();

    // A non-empty IP must not change the token: IP is intentionally left out of the signature.
    $url = app(BunnyProvider::class)->manifestUrl(new Video, 'vid-ulid/out-ulid.mpd', '1.2.3.4', false)->url;

    [$parts] = bunnyDirParts($url);
    $expires = (int) $parts['expires'];
    $tokenPath = $parts['token_path'];

    $message = $tokenPath.$expires."token_path={$tokenPath}";
    $digest = hash_hmac('sha256', $message, 'test-key', true);
    $expected = 'HS256-'.rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');

    expect($parts['bcdn_token'])->toBe($expected);
});

it('is a no-op passthrough (path only) when no token key is configured', function () {
    fakeCdnSettings(tokenKey: '');

    $link = app(BunnyProvider::class)->manifestUrl(new Video, 'vid-ulid/out-ulid.mpd', '1.2.3.4', false);

    expect($link->url)->toBe('https://cdn.example.com/vid-ulid/out-ulid.mpd')
        ->and($link->token)->toBeNull()
        ->and($link->tokenHash)->toBeNull();
});

it('signs a download for the one file, with no token_path', function () {
    fakeCdnSettings();

    $key = '01HTESTVIDEOULID0000000000/download/video/01HTESTFILEULID00000000000.mp4';
    $link = app(BunnyProvider::class)->downloadUrl('01HTESTVIDEOULID0000000000', $key, false);
    $url = $link->url;

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    // token_path is a directory PREFIX. It exists so a DASH manifest and its segments can share one
    // token; on a single file it would widen the link to everything under that prefix.
    expect($query)->not->toHaveKey('token_path')
        ->and(parse_url($url, PHP_URL_PATH))->toBe("/{$key}")
        ->and($query['expires'])->toBe((string) (Carbon::parse('2026-01-01 00:00:00')->timestamp + 3600));

    // Advanced token auth, no parameters and no IP: the base is the signature path plus the expiry.
    $expected = 'HS256-'.rtrim(strtr(base64_encode(
        hash_hmac('sha256', "/{$key}".$query['expires'], 'test-key', true)
    ), '+/', '-_'), '=');

    expect($query['token'])->toBe($expected)
        ->and($link->token)->toBe($expected);
});

it('carries no tracking id in a download URL: the token is the only carrier', function () {
    fakeCdnSettings();

    // A signed `tid` used to ride the download query. It no longer does: attribution hangs off
    // the token for every link ({@see \App\Services\Cdn\TrackingRegistry}), so the URL is the
    // same whoever asked for it.
    $key = '01HTESTVIDEOULID0000000000/download/audio/01HTESTFILEULID00000000000.mp4';
    $url = app(BunnyProvider::class)->downloadUrl('01HTESTVIDEOULID0000000000', $key, false)->url;

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query)->toHaveKeys(['token', 'expires'])
        ->and($query)->not->toHaveKey('tid');
});

it('mints distinct tokens for two viewers of the same video in the same second', function () {
    fakeCdnSettings();

    // The hash input is (token_path, expires): with the clock frozen, both mints would produce
    // the same token, and since the token is what the tracking mapping is keyed on, the second
    // viewer's session would be attributed to the first one's id. The jitter on the expiry, and
    // the claim each mint records, are what give each link a token of its own.
    $first = app(BunnyProvider::class)->manifestUrl(new Video, 'vid-ulid/out-ulid.mpd', '1.2.3.4', false);
    $second = app(BunnyProvider::class)->manifestUrl(new Video, 'vid-ulid/out-ulid.mpd', '5.6.7.8', false);

    expect($first->token)->not->toBe($second->token)
        ->and($first->tokenHash)->not->toBe($second->tokenHash);
});
