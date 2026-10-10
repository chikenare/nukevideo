<?php

namespace App\Support;

class IpAddress
{
    // The ::ffff:0:0/96 prefix an IPv4 address carries when it is written as IPv6.
    private const V4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /**
     * The one spelling of an address `usage_daily` stores, or null when the text is not one.
     *
     * The column is part of a unique key, so two spellings of the same address are two rows and a
     * viewer counted twice: `2001:DB8::1` against `2001:db8:0:0:0:0:0:1`, or `1.2.3.4` against the
     * `::ffff:1.2.3.4` a dual-stack edge logs it as. Round-tripping through the packed form
     * collapses the first kind, unmapping the second.
     */
    public static function canonical(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);

        if (strlen($packed) === 16 && str_starts_with($packed, self::V4_MAPPED_PREFIX)) {
            $packed = substr($packed, 12);
        }

        return inet_ntop($packed);
    }
}
