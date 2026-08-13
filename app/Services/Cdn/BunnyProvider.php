<?php

declare(strict_types=1);

namespace App\Services\Cdn;

use App\Data\BunnyConfigData;
use App\Models\Video;
use App\Settings\CdnSettings;

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
    public function __construct(private CdnSettings $settings) {}

    public function manifestUrl(Video $video, string $path, string $ip, bool $local): string
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
        $parameters = $trackingId === null ? [] : ['tid' => $trackingId];

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

    private function signed(string $urlPath, string $tokenPath): string
    {
        $config = BunnyConfigData::from($this->settings->providers['bunny'] ?? []);

        if ($config->tokenKey === '') {
            return "https://{$config->host}{$urlPath}";
        }

        $expires = now()->timestamp + $config->tokenWindow;

        // Bunny signs the alphabetically-sorted parameters; token_path is our only one.
        $parameters = ['token_path' => $tokenPath];
        ksort($parameters);

        $signingData = $this->joinParams($parameters, rawEncode: false);
        $urlData = $this->joinParams($parameters, rawEncode: true);

        $token = $this->token($tokenPath.$expires.$signingData, $config->tokenKey);

        return "https://{$config->host}/bcdn_token={$token}&{$urlData}&expires={$expires}{$urlPath}";
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
