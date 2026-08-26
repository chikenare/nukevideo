<?php

declare(strict_types=1);

namespace App\Services\Cdn;

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
 *
 * The URL never names the viewer. A directory token refuses extra signed parameters (verified
 * against the edge: `tid=`, `nonce=`, even the documented `limit=` answer 403), an unsigned one
 * would be viewer-editable, and a query would not be inherited by the segments anyway. The token
 * is the carrier instead — it reaches the log on every segment — and the mint records what it
 * means ({@see TrackingRegistry}).
 */
class BunnyProvider implements CdnProvider
{
    /**
     * Extra seconds a playback token's expiry may be jittered by to make its hash unique
     * ({@see tokenFor}). Also the extra lifetime the tracking mapping allows for
     * ({@see TrackingRegistry}).
     */
    public const EXPIRY_JITTER = 300;

    public function __construct(private CdnSettings $settings) {}

    public function manifestUrl(Video $video, string $path, string $ip, bool $local): SignedLink
    {
        $urlPath = '/'.ltrim($path, '/'); // /{videoUlid}/play/{file}

        // Directory scope: the manifest and the relative segments it lists share a prefix, so one
        // token covers the whole session.
        return $this->signed($urlPath, $this->directoryOf($urlPath));
    }

    /**
     * A download is a single file, so it is signed the plain way: no `token_path` at all, and the
     * signature covers the object's own path. `token_path` exists to let a DASH manifest and the
     * segments it lists share one token; it is a directory PREFIX, and using it here would scope a
     * download link to everything sharing that prefix instead of to the one object.
     */
    public function downloadUrl(string $videoUlid, string $key, bool $local): SignedLink
    {
        $config = BunnyConfigData::from($this->settings->providers['bunny'] ?? []);
        $urlPath = '/'.ltrim($key, '/');

        if ($config->tokenKey === '') {
            return new SignedLink("https://{$config->host}{$urlPath}", null);
        }

        $expires = now()->timestamp + $config->tokenWindow;

        // Advanced token auth, no parameters and no IP: the hashable base is the signature path
        // plus the expiry, and the token rides as a query parameter.
        $token = $this->token($urlPath.$expires, $config->tokenKey);

        return new SignedLink("https://{$config->host}{$urlPath}?token={$token}&expires={$expires}", $token);
    }

    private function signed(string $urlPath, string $tokenPath): SignedLink
    {
        $config = BunnyConfigData::from($this->settings->providers['bunny'] ?? []);

        if ($config->tokenKey === '') {
            // No token, no identifier in the log: an unsigned zone cannot attribute playback.
            return new SignedLink("https://{$config->host}{$urlPath}", null);
        }

        // Bunny signs the alphabetically-sorted parameters; token_path is our only one.
        $parameters = ['token_path' => $tokenPath];
        ksort($parameters);

        $signingData = $this->joinParams($parameters, rawEncode: false);
        $urlData = $this->joinParams($parameters, rawEncode: true);

        [$token, $expires] = $this->tokenFor($tokenPath, $signingData, $config);

        return new SignedLink("https://{$config->host}/bcdn_token={$token}&{$urlData}&expires={$expires}{$urlPath}", $token);
    }

    /**
     * The signed token and its expiry, unique to this mint.
     *
     * The hash input is (token_path, expires) and nothing else the edge lets us vary, so two
     * viewers minting the same directory in the same second would share a token — and, since the
     * token is what attribution hangs off ({@see TrackingRegistry}), a label. The expiry is
     * jittered until this mint claims a token nobody else has; if every attempt finds it claimed
     * (hundreds of same-second mints of one video), the shared token is kept — the bytes are
     * counted either way, only the label may land on the other session's id.
     *
     * The claim key lives as long as the token can: a token is only free to reuse once nothing
     * minted before can still be attributed through it.
     *
     * @return array{string, int}
     */
    private function tokenFor(string $tokenPath, string $signingData, BunnyConfigData $config): array
    {
        $base = now()->timestamp + $config->tokenWindow;
        $ttl = $config->tokenWindow + self::EXPIRY_JITTER;
        $expires = $base;
        $token = '';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $expires = $attempt === 0 ? $base : $base + random_int(1, self::EXPIRY_JITTER);
            $token = $this->token($tokenPath.$expires.$signingData, $config->tokenKey);

            if (Cache::add("bunny-token-claim:{$token}", 1, $ttl)) {
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
