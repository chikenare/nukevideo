<?php

declare(strict_types=1);

namespace App\Services\Cdn;

use App\Console\Commands\IngestBunnyLogs;
use App\Data\BunnyConfigData;
use App\Models\Video;
use App\Settings\CdnSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Bunny CDN token authentication (HMAC-SHA256), per BunnyWay's reference url_signing.php.
 *
 * Uses directory mode: the token is embedded as a path prefix (/bcdn_token=.../{path}) scoped
 * to the manifest's directory via token_path, so the manifest AND its relative segments — which
 * inherit that path prefix when the player resolves them — authenticate under a single token.
 * Bunny strips the prefix before pulling from origin.
 *
 * IP is not folded into the signature: pull-zone IP validation is off, and a signed IP Bunny
 * doesn't check just fails validation.
 */
class BunnyProvider implements CdnProvider
{
    /**
     * Extra seconds a tracked token's expiry may be jittered by to make its hash unique
     * ({@see tokenFor}), and part of the mapping TTL for the same reason.
     */
    private const TRACKING_JITTER = 300;

    public function __construct(private CdnSettings $settings) {}

    /**
     * Cache key of the token → tracking id mapping a playback mint records, and the ingest reads
     * back ({@see IngestBunnyLogs}). Keyed by the token itself: it is the
     * one per-mint value every segment request carries into the access log.
     */
    public static function trackingCacheKey(string $token): string
    {
        return "bunny-tracking:{$token}";
    }

    public function manifestUrl(Video $video, string $path, string $ip, bool $local, ?string $trackingId = null): string
    {
        // $trackingId cannot travel in the URL here: the directory token refuses extra signed
        // parameters (verified against the edge), an unsigned one would be viewer-editable, and a
        // query would not be inherited by the segments anyway. The token itself is the carrier
        // instead — a path prefix every segment inherits and the access log keeps — so the mint
        // records what the token means ({@see tokenFor}) and the ingest resolves it.
        $urlPath = '/'.ltrim($path, '/'); // /{videoUlid}/play/{file}

        // Directory scope: the manifest and the relative segments it lists share a prefix, so one
        // token covers the whole session.
        return $this->signed($urlPath, $this->directoryOf($urlPath), $trackingId);
    }

    /**
     * A download is a single file, so it is signed the plain way: no `token_path` at all, and the
     * signature covers the object's own path. `token_path` exists to let a DASH manifest and the
     * segments it lists share one token; it is a directory PREFIX, and using it here would scope a
     * download link to everything sharing that prefix instead of to the one object.
     */
    public function downloadUrl(string $videoUlid, string $key, bool $local, ?string $trackingId = null): string
    {
        $config = BunnyConfigData::from($this->settings->providers['bunny'] ?? []);
        $urlPath = '/'.ltrim($key, '/');

        // `tid` is the caller's own tracking id. It has to be SIGNED, not merely appended: Bunny
        // folds every query parameter into the signature, so an unsigned extra would fail
        // validation outright. The reward is that it lands in the v2 logging API's `path`, which
        // carries the query string — which is what makes per-caller bandwidth attribution possible
        // ({@see \App\Console\Commands\IngestBunnyLogs}). `tid` is not one of Bunny's reserved
        // parameter names.
        //
        // Re-checked here, not just at the request boundary, and mirroring
        // {@see SelfHostedProvider::trackedPath()}: the signature serialises the parameters as
        // `key=value` joined by `&`, so an `&` or `=` that slipped past validation would let the
        // value reshape the signed parameter set. Refusing beats escaping — the value also has to
        // survive a log line intact to be attributable at all.
        if ($trackingId !== null && $trackingId !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $trackingId) !== 1) {
            throw new \InvalidArgumentException('Tracking id is not a valid signed parameter.');
        }

        $parameters = $trackingId === null || $trackingId === '' ? [] : ['tid' => $trackingId];

        if ($config->tokenKey === '') {
            $query = $parameters === [] ? '' : '?'.$this->joinParams($parameters, rawEncode: true);

            return "https://{$config->host}{$urlPath}{$query}";
        }

        $expires = now()->timestamp + $config->tokenWindow;
        ksort($parameters);

        $signingData = $this->joinParams($parameters, rawEncode: false);
        $urlData = $this->joinParams($parameters, rawEncode: true);

        // Advanced token auth, no IP: the hashable base is the signature path, the expiry and the
        // alphabetically-sorted parameters, and the token rides as a query parameter.
        $token = $this->token($urlPath.$expires.$signingData, $config->tokenKey);
        $extra = $urlData === '' ? '' : "&{$urlData}";

        return "https://{$config->host}{$urlPath}?token={$token}&expires={$expires}{$extra}";
    }

    private function signed(string $urlPath, string $tokenPath, ?string $trackingId = null): string
    {
        $config = BunnyConfigData::from($this->settings->providers['bunny'] ?? []);

        if ($config->tokenKey === '') {
            // No token, no identifier in the log: an unsigned zone cannot attribute playback.
            return "https://{$config->host}{$urlPath}";
        }

        // Bunny signs the alphabetically-sorted parameters; token_path is our only one.
        $parameters = ['token_path' => $tokenPath];
        ksort($parameters);

        $signingData = $this->joinParams($parameters, rawEncode: false);
        $urlData = $this->joinParams($parameters, rawEncode: true);

        [$token, $expires] = $this->tokenFor($tokenPath, $signingData, $config, $trackingId);

        return "https://{$config->host}/bcdn_token={$token}&{$urlData}&expires={$expires}{$urlPath}";
    }

    /**
     * The signed token and its expiry — plus, when a tracking id rides along, the token → id
     * mapping the ingest resolves. The token is an opaque HMAC: it identifies nothing by itself,
     * so what it means has to be recorded at the only moment anyone knows — when it is minted.
     *
     * The hash input is (token_path, expires), so two viewers minting the same directory in the
     * same second would share a token, and with it a label. The expiry is jittered until the
     * mapping claims a token of its own; if every attempt finds the token claimed by another id
     * (hundreds of same-second mints of one video), the shared label is kept — a smaller wrong
     * than overwriting the other session's, and the bytes are counted either way.
     *
     * The mapping outlives the token by the jitter plus a margin: a segment fetched just before
     * expiry reaches the ingest minutes later ({@see IngestBunnyLogs}).
     *
     * @return array{string, int}
     */
    private function tokenFor(string $tokenPath, string $signingData, BunnyConfigData $config, ?string $trackingId): array
    {
        $base = now()->timestamp + $config->tokenWindow;

        if ($trackingId === null || $trackingId === '') {
            return [$this->token($tokenPath.$base.$signingData, $config->tokenKey), $base];
        }

        // Same clamp as the download links: the value only reaches our own cache here, but a label
        // outside the alphabet could never have come from the request validation.
        if (preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $trackingId) !== 1) {
            throw new \InvalidArgumentException('Tracking id is not a valid label.');
        }

        $ttl = $config->tokenWindow + self::TRACKING_JITTER + 1800;
        $expires = $base;
        $token = '';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $expires = $attempt === 0 ? $base : $base + random_int(1, self::TRACKING_JITTER);
            $token = $this->token($tokenPath.$expires.$signingData, $config->tokenKey);
            $key = self::trackingCacheKey($token);

            if (Cache::add($key, $trackingId, $ttl) || Cache::get($key) === $trackingId) {
                break;
            }
        }

        return [$token, $expires];
    }

    /** `HS256-` + base64url of the HMAC, per Bunny's advanced token scheme. */
    private function token(string $message, string $key): string
    {
        $digest = hash_hmac('sha256', $message, $key, true);

        return 'HS256-'.rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    }

    public function assetUrl(string $videoUlid, string $key, bool $local): string
    {
        $config = BunnyConfigData::from($this->settings->providers['bunny'] ?? []);

        // Always https: a Bunny pull zone has no local mode to fall back to.
        return "https://{$config->host}/".ltrim($key, '/');
    }

    private function directoryOf(string $path): string
    {
        return substr($path, 0, (int) strrpos($path, '/')).'/'; // /{videoUlid}/play/
    }

    /** @param  array<string, string>  $parameters */
    private function joinParams(array $parameters, bool $rawEncode): string
    {
        $parts = [];
        foreach ($parameters as $name => $value) {
            $parts[] = "{$name}=".($rawEncode ? rawurlencode($value) : $value);
        }

        return implode('&', $parts);
    }
}
