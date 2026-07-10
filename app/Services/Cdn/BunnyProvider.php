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
        $config = BunnyConfigData::from($this->settings->providers['bunny'] ?? []);
        $urlPath = '/'.ltrim($path, '/'); // /{videoUlid}/{file}

        if ($config->tokenKey === '') {
            return "https://{$config->host}{$urlPath}";
        }

        $expires = now()->timestamp + $config->tokenWindow;
        $tokenPath = $this->directoryOf($urlPath); // /{videoUlid}/

        // Bunny signs the alphabetically-sorted parameters; token_path is our only one.
        $parameters = ['token_path' => $tokenPath];
        ksort($parameters);

        $signingData = $this->joinParams($parameters, rawEncode: false);
        $urlData = $this->joinParams($parameters, rawEncode: true);

        $message = $tokenPath.$expires.$signingData;
        $digest = hash_hmac('sha256', $message, $config->tokenKey, true);
        $token = 'HS256-'.rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');

        return "https://{$config->host}/bcdn_token={$token}&{$urlData}&expires={$expires}{$urlPath}";
    }

    private function directoryOf(string $path): string
    {
        return substr($path, 0, (int) strrpos($path, '/')).'/'; // /{videoUlid}/
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
