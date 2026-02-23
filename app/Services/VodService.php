<?php

namespace App\Services;

class VodService
{
    public function __construct()
    {
    }

    /**
     * Generate VOD Auth Token signed URL
     *
     * @param string $baseUrl Base URL to sign
     * @param string $ulid Video identifier for ACL path
     * @param array $customParams Optional custom parameters to include in the signed token
     * @return string Signed URL with token including custom parameters
     */
    public function generateVodSignedUrl(string $baseUrl, string $ulid, array $customParams = []): string
    {
        $secretHex = config('node.vod.token_secret');
        $duration = config('node.vod.token_window');
        $tokenName = config('node.vod.token_name');
        $endTime = time() + $duration;

        $acl = "/hls/{$ulid}/*";

        // Build auth string with custom parameters
        $authString = "exp={$endTime}~acl={$acl}";

        // Add custom parameters to auth string (will be signed)
        foreach ($customParams as $key => $value) {
            $authString .= "~{$key}={$value}";
        }

        $binSecret = pack("H*", $secretHex);
        $hmac = hash_hmac('sha256', $authString, $binSecret);

        // Build token param with custom parameters
        $tokenParam = "exp={$endTime}~acl={$acl}";

        foreach ($customParams as $key => $value) {
            $tokenParam .= "~{$key}={$value}";
        }

        $tokenParam .= "~hmac={$hmac}";

        return $baseUrl . "?" . $tokenName . "=" . $tokenParam;
    }
}
