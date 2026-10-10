<?php

namespace App\Console\Commands;

use App\Jobs\RefreshGeoIpJob;
use App\Services\GeoIp;
use Illuminate\Console\Command;

/**
 * The manual counterpart of {@see RefreshGeoIpJob}, for not waiting for the next
 * ingest batch after setting the key. It writes to the disk of the container it is run in, so the one that
 * matters is the container running Horizon.
 */
class UpdateGeoIp extends Command
{
    protected $signature = 'geoip:update';

    protected $description = 'Download the GeoLite2 country database used to tag bandwidth by country';

    public function handle(GeoIp $geoIp): int
    {
        if (! config('services.maxmind.license_key')) {
            $this->warn('MAXMIND_LICENSE_KEY is not set — countries stay empty.');

            return self::SUCCESS;
        }

        $geoIp->refresh();
        $this->info("Downloaded to {$geoIp->path()}.");

        return self::SUCCESS;
    }
}
