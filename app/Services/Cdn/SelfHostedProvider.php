<?php

declare(strict_types=1);

namespace App\Services\Cdn;

use App\Data\SelfHostedConfigData;
use App\Exceptions\NoCdnNodeAvailableException;
use App\Models\Node;
use App\Models\Video;
use App\Settings\CdnSettings;

/**
 * Our own CDN: the URL points at a per-video proxy node (edge nginx) and carries an Akamai
 * `__hdnea__` token. The edge validates it and re-signs the segment URLs in the manifest body.
 */
class SelfHostedProvider implements CdnProvider
{
    public function __construct(private CdnSettings $settings) {}

    public function manifestUrl(Video $video, string $path, string $ip, bool $local): string
    {
        $node = Node::findProxyForVideo($video->ulid);

        if (! $node) {
            throw new NoCdnNodeAvailableException;
        }

        $scheme = $local ? 'http://' : 'https://';
        $url = "{$scheme}{$node->hostname}/".ltrim($path, '/');

        return $this->sign($url, $ip);
    }

    private function sign(string $url, string $ip): string
    {
        $config = SelfHostedConfigData::from($this->settings->providers['self_hosted'] ?? []);

        if ($config->tokenSecret === '') {
            return $url;
        }

        $exp = now()->timestamp + $config->tokenWindow;
        $acl = $this->aclFor($url);

        $authString = "exp={$exp}~acl={$acl}~ip={$ip}";
        $hmac = hash_hmac('sha256', $authString, pack('H*', $config->tokenSecret));
        $token = "{$authString}~hmac={$hmac}";

        $separator = str_contains($url, '?') ? '&' : '?';

        return "{$url}{$separator}{$config->tokenName}={$token}";
    }

    private function aclFor(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH); // /{videoUlid}/manifest.mpd
        $dir = substr($path, 0, (int) strrpos($path, '/')); // /{videoUlid}

        return "{$dir}/*";
    }
}
