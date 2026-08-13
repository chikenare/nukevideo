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

    $url = app(BunnyProvider::class)->manifestUrl(new Video, 'vid-ulid/out-ulid.mpd', '1.2.3.4', false);

    expect($url)->toStartWith('https://cdn.example.com/bcdn_token=HS256-')
        ->and($url)->toEndWith('/vid-ulid/out-ulid.mpd');

    [$parts, $tail] = bunnyDirParts($url);
    expect($parts['token_path'])->toBe('/vid-ulid/')
        ->and((int) $parts['expires'])->toBe(Carbon::now()->timestamp + 3600)
        ->and($tail)->toBe('/vid-ulid/out-ulid.mpd');
});

it('computes an HMAC-SHA256 token from token_path only, without the IP', function () {
    fakeCdnSettings();

    // A non-empty IP must not change the token: IP is intentionally left out of the signature.
    $url = app(BunnyProvider::class)->manifestUrl(new Video, 'vid-ulid/out-ulid.mpd', '1.2.3.4', false);

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

    $url = app(BunnyProvider::class)->manifestUrl(new Video, 'vid-ulid/out-ulid.mpd', '1.2.3.4', false);

    expect($url)->toBe('https://cdn.example.com/vid-ulid/out-ulid.mpd');
});

it('signs a download for the one file, with no token_path', function () {
    fakeCdnSettings();

    $key = '01HTESTVIDEOULID0000000000/download/video/01HTESTFILEULID00000000000.mp4';
    $url = app(BunnyProvider::class)->downloadUrl('01HTESTVIDEOULID0000000000', $key, false);

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

    expect($query['token'])->toBe($expected);
});

it('signs the caller tracking id into the download token', function () {
    fakeCdnSettings();

    $key = '01HTESTVIDEOULID0000000000/download/audio/01HTESTFILEULID00000000000.mp4';
    $url = app(BunnyProvider::class)->downloadUrl('01HTESTVIDEOULID0000000000', $key, false, 'client-42');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query['tid'])->toBe('client-42');

    // Bunny folds every query parameter into the signature, so `tid` has to be part of the hashable
    // base — appended but unsigned, the edge rejects the request outright. Verified against the
    // staging pull zone: altering the id afterwards, or adding one to a URL signed without it,
    // both answer 403.
    $expected = 'HS256-'.rtrim(strtr(base64_encode(
        hash_hmac('sha256', "/{$key}".$query['expires'].'tid=client-42', 'test-key', true)
    ), '+/', '-_'), '=');

    expect($query['token'])->toBe($expected);
});
