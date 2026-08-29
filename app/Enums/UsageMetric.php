<?php

namespace App\Enums;

use App\Jobs\IngestBandwidthJob;
use App\Services\UsageService;

/**
 * Every metric `usage` can hold, in one place.
 *
 * The table stores all of them in a single `value` column whose unit lives in the metric name —
 * bytes for the transfer metrics, seconds for {@see UsageMetric::ENCODING_CPU} — and nothing in the
 * schema says which. That is why the name is never free text at an API boundary: a query that
 * summed across metrics would add seconds to bytes, and one that took an unrecognised name would
 * answer with an empty result, which for a caller building an invoice reads as zero.
 *
 * The writers are {@see UsageService::record()} (upload volume, encoding seconds) and
 * {@see IngestBandwidthJob} (everything delivered).
 */
enum UsageMetric: string
{
    /** Source file size, booked once when the upload lands. */
    case UPLOAD_BYTES = 'upload_bytes';

    /** Encoding time, in SECONDS — the one metric here that is not bytes. */
    case ENCODING_CPU = 'encoding_cpu';

    /** Delivered to players: manifests and CMAF segments. */
    case STREAMING_BYTES = 'streaming_bytes';

    /** Delivered through a minted track download link. */
    case DOWNLOAD_BYTES = 'download_bytes';

    /** Thumbnails and storyboards. */
    case ASSET_BYTES = 'asset_bytes';

    /** Delivery whose path zone could not be read, and what the pre-merge history was carried over under. */
    case BANDWIDTH_BYTES = 'bandwidth_bytes';

    /**
     * Bytes an edge had to fetch from S3 to serve a request — the cost side of the cache. Booked
     * under account 0, so no account-scoped read can ever be asked for it; it is the operator's
     * number and surfaces only through the admin per-node report.
     */
    case ORIGIN_BYTES = 'origin_bytes';

    /**
     * The metrics that measure bytes handed to a viewer. This is the set every bandwidth query is
     * constrained to: without it a sum would silently fold in encoding seconds and upload volume
     * and return a number that means nothing.
     *
     * @return list<string>
     */
    public static function delivery(): array
    {
        return array_map(fn (self $m) => $m->value, [
            self::STREAMING_BYTES, self::DOWNLOAD_BYTES, self::ASSET_BYTES, self::BANDWIDTH_BYTES,
        ]);
    }

    /**
     * What an account may ask its own usage for: everything except the operator's origin egress,
     * which lives under account 0 and would always answer empty.
     *
     * @return list<string>
     */
    public static function account(): array
    {
        return array_map(fn (self $m) => $m->value, array_filter(
            self::cases(),
            fn (self $m) => $m !== self::ORIGIN_BYTES,
        ));
    }

    /** The unit `value` carries for this metric. The reason a caller must never sum across them. */
    public function unit(): string
    {
        return $this === self::ENCODING_CPU ? 'seconds' : 'bytes';
    }
}
