<?php

namespace App\Services;

class VodService
{
    public function signUrl(string $url, string $ip): string
    {
        $secret = (string) config('node.vod.token_secret');

        if ($secret === '') {
            return $url;
        }

        $window = (int) config('node.vod.token_window');
        $tokenName = (string) config('node.vod.token_name');

        $exp = time() + $window;
        $acl = $this->aclFor($url);

        $authString = "exp={$exp}~acl={$acl}~ip={$ip}";
        $hmac = hash_hmac('sha256', $authString, pack('H*', $secret));
        $token = "{$authString}~hmac={$hmac}";

        $separator = str_contains($url, '?') ? '&' : '?';

        return "{$url}{$separator}{$tokenName}={$token}";
    }

    private function aclFor(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH); // /{sid}/{videoUlid}/manifest.mpd
        $dir = substr($path, 0, (int) strrpos($path, '/')); // /{sid}/{videoUlid}

        return "{$dir}/*";
    }
}
