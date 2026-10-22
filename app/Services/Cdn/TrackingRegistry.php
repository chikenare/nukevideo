<?php

declare(strict_types=1);

namespace App\Services\Cdn;

use App\Data\BunnyConfigData;
use App\Data\SelfHostedConfigData;
use App\Enums\CdnDriver;
use App\Settings\CdnSettings;
use Illuminate\Support\Facades\Cache;

/**
 * What a minted link's token means: the tracking id the mint attributed it to.
 *
 * One mechanism for every provider. The URL never carries the id — Bunny's directory token
 * refuses extra signed parameters and a query is not inherited by the segments; on the
 * self-hosted edge it would cost a path segment nginx has to strip again. The token IS the
 * carrier: every request a link produces logs it, so the mint records `hash(token) → id` and
 * the log ingest resolves the hash back. Best-effort by nature — a mapping lost to a cache
 * restart costs the label, never the bytes.
 */
class TrackingRegistry
{
    /**
     * Seconds a mapping outlives the token it describes. A segment fetched right before expiry
     * reaches the ingest minutes later (Bunny's log API lags, and the command runs every five),
     * and a Bunny mint may push the expiry past the window to keep its token unique
     * ({@see BunnyProvider}).
     */
    private const GRACE = 1800 + BunnyProvider::EXPIRY_JITTER;

    public function __construct(private CdnSettings $settings) {}

    public static function cacheKey(string $tokenHash): string
    {
        return "link-tracking:{$tokenHash}";
    }

    public static function downloadKey(string $tokenHash): string
    {
        return "link-download:{$tokenHash}";
    }

    /**
     * Attribute a freshly minted link. A link with no token, or a mint that names no viewer,
     * records nothing: there is nothing to resolve, and an empty mapping would only shadow a
     * later one.
     */
    public function record(SignedLink $link, ?string $trackingId): void
    {
        $this->recordMany([$link], $trackingId);
    }

    /**
     * The same for a batch mint, in one round trip rather than one per track — the write side of
     * {@see resolveMany}. A batch is one viewer fetching one video's pieces, so the whole batch
     * shares an id.
     *
     * `$download` marks the links as an offline download, so the ingest books their bytes as
     * `download_bytes` rather than `streaming_bytes`: the segments are the same files either way.
     *
     * @param  list<SignedLink>  $links
     */
    public function recordMany(array $links, ?string $trackingId, bool $download = false): void
    {
        $mapping = [];

        foreach ($links as $link) {
            if ($link->tokenHash === null) {
                continue;
            }
            if ($trackingId !== null && $trackingId !== '') {
                $mapping[self::cacheKey($link->tokenHash)] = $trackingId;
            }
            if ($download) {
                $mapping[self::downloadKey($link->tokenHash)] = true;
            }
        }

        if ($mapping !== []) {
            Cache::putMany($mapping, $this->tokenWindow() + self::GRACE);
        }
    }

    /** The tracking id a logged token was minted for, or null when no mapping (still) exists. */
    public function resolve(string $tokenHash): ?string
    {
        return $this->resolveMany([$tokenHash])[$tokenHash];
    }

    /**
     * The same, for a batch: one round trip for every hash of an ingest batch rather than one
     * per row. Every requested hash is present in the result, null when unmapped.
     *
     * @param  list<string>  $tokenHashes
     * @return array<string, string|null>
     */
    public function resolveMany(array $tokenHashes): array
    {
        return $this->lookupMany($tokenHashes)['tracking'];
    }

    /**
     * The tracking ids and the offline-download marks of a batch, in one round trip.
     *
     * @param  list<string>  $tokenHashes
     * @return array{tracking: array<string, string|null>, downloads: array<string, true>}
     */
    public function lookupMany(array $tokenHashes): array
    {
        $tokenHashes = array_values(array_unique($tokenHashes));

        if ($tokenHashes === []) {
            return ['tracking' => [], 'downloads' => []];
        }

        $found = Cache::many([
            ...array_map(self::cacheKey(...), $tokenHashes),
            ...array_map(self::downloadKey(...), $tokenHashes),
        ]);
        $tracking = [];
        $downloads = [];

        foreach ($tokenHashes as $hash) {
            $trackingId = $found[self::cacheKey($hash)] ?? null;
            $tracking[$hash] = is_string($trackingId) && $trackingId !== '' ? $trackingId : null;

            if (($found[self::downloadKey($hash)] ?? null) === true) {
                $downloads[$hash] = true;
            }
        }

        return ['tracking' => $tracking, 'downloads' => $downloads];
    }

    /**
     * How long the active provider's links stay valid. Each provider keeps its own window, so
     * reading one of them unconditionally would report the self-hosted lifetime for a link Bunny
     * signed with a different one.
     */
    public function tokenWindow(): int
    {
        $config = $this->settings->providers[$this->settings->provider] ?? [];

        return match (CdnDriver::from($this->settings->provider)) {
            CdnDriver::Bunny => BunnyConfigData::from($config)->tokenWindow,
            CdnDriver::SelfHosted => SelfHostedConfigData::from($config)->tokenWindow,
        };
    }
}
