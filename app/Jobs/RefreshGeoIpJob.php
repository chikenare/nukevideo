<?php

namespace App\Jobs;

use App\Services\GeoIp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the country database present and recent on the host that ingests bandwidth.
 *
 * A job and not a scheduled command, because of where the file has to end up: the scheduler runs
 * in its own container, and {@see IngestBandwidthJob} reads the database from the local disk of
 * the one that drains `default`.
 *
 * Two things dispatch it: the scheduler, weekly, which is what keeps the copy current (MaxMind
 * publishes twice a week and its licence asks for one no older than 30 days); and the ingest,
 * when it finds no file at all — a recreated container starts with an empty disk, and waiting
 * for the week to come round would leave days of traffic without a country.
 */
class RefreshGeoIpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // The API host's queue, the one the bandwidth ingest runs on.
    public const QUEUE = 'default';

    // No retries: a failed download leaves the previous database in place, and when there is
    // none the ingest asks again within the hour.
    public $tries = 1;

    public $timeout = 180;

    // How long the ingest waits before asking again for a database that is still missing. Without
    // it every batch would queue a download while MaxMind is unreachable or the key is wrong, and
    // an account gets 30 of them a day.
    private const REQUEST_INTERVAL_SECONDS = 3600;

    /** Queues a download if the database is missing and none was asked for recently. */
    public static function dispatchIfMissing(GeoIp $geoIp): void
    {
        if ($geoIp->missing() && Cache::add('geoip:refresh-requested', true, self::REQUEST_INTERVAL_SECONDS)) {
            self::dispatch()->onQueue(self::QUEUE);
        }
    }

    public function handle(GeoIp $geoIp): void
    {
        $geoIp->refresh();
    }
}
