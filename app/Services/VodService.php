<?php

namespace App\Services;

class VodService
{
    /**
     * Generate VOD Auth Token signed URL
     */
    public function generateVodSignedUrl(
        string $baseUrl,
        string $pathId,
        string $protocol,
        string $ip
    ): string {
        $secretHex = config('node.vod.token_secret');
        $duration = config('node.vod.token_window');
        $tokenName = config('node.vod.token_name');
        $endTime = time() + $duration;

        $acl = "/{$protocol}/{$pathId}/*";

        $authString = "exp={$endTime}~acl={$acl}~ip=$ip";

        $binSecret = pack('H*', $secretHex);
        $hmac = hash_hmac('sha256', $authString, $binSecret);

        $tokenValue = "{$authString}~hmac={$hmac}";

        return "{$baseUrl}?{$tokenName}={$tokenValue}";
    }
}
