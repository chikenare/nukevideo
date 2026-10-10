<?php

namespace App\Services;

use App\Jobs\RefreshGeoIpJob;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader;
use RuntimeException;

/**
 * Country of a viewer's address, from a local GeoLite2-Country database.
 *
 * Resolved once, where the traffic is ingested, and stored on the row — a report by country is
 * then a GROUP BY like any other, instead of a lookup per stored address every time it is asked.
 *
 * Everything here is optional by design. Without MaxMind credentials there is no database, a
 * lookup answers nothing, and `country` stays '': bandwidth accounting must never depend on a
 * third party's download being reachable.
 *
 * The file lives on the local disk of whichever container asks. In production the API, the
 * scheduler and Horizon are separate containers with no shared volume, which is why the refresh
 * is a queued job on `default` ({@see RefreshGeoIpJob}) rather than a scheduled
 * command: it has to run where the ingest job runs.
 */
class GeoIp
{
    private const EDITION = 'GeoLite2-Country';

    private const TIMEOUT_SECONDS = 120;

    /**
     * ISO 3166-1 alpha-2 codes for the addresses that resolve to one. An address that does not —
     * private, unallocated, or any address at all while there is no database — is simply absent.
     *
     * The database is opened per call rather than held: a call is a whole ingest batch, opening
     * costs one read of the file's tail, and a long-lived worker never keeps reading a file that
     * has since been replaced.
     *
     * @param  list<string>  $ips
     * @return array<string, string>
     */
    public function countries(array $ips): array
    {
        $ips = array_filter(array_unique($ips), fn (string $ip) => $ip !== '');

        if ($ips === [] || ! is_file($this->path())) {
            return [];
        }

        try {
            $reader = new Reader($this->path());
        } catch (\Throwable $e) {
            Log::warning('GeoIP database could not be opened: '.$e->getMessage());

            return [];
        }

        $countries = [];

        foreach ($ips as $ip) {
            try {
                $code = $reader->get($ip)['country']['iso_code'] ?? null;
            } catch (\Throwable) {
                continue;
            }

            // Read out of a downloaded file into a char(2): anything else is not stored.
            if (is_string($code) && preg_match('/^[A-Z]{2}\z/', $code) === 1) {
                $countries[$ip] = $code;
            }
        }

        $reader->close();

        return $countries;
    }

    /**
     * Whether a database could be had and is not there: credentials are set and the file is
     * missing. What a caller checks to ask for a download outside the weekly one — a recreated
     * container starts with an empty disk.
     */
    public function missing(): bool
    {
        clearstatcache(true, $this->path());

        return (string) config('services.maxmind.license_key') !== '' && ! is_file($this->path());
    }

    /**
     * Downloads the database, replacing the current one. Answers false, having done nothing, when
     * there are no credentials.
     *
     * The new file is opened before it replaces the old one, and moved into place with a rename,
     * so a truncated download or an error page never becomes the database being read.
     */
    public function refresh(): bool
    {
        $accountId = (string) config('services.maxmind.account_id');
        $licenseKey = (string) config('services.maxmind.license_key');

        if ($licenseKey === '') {
            return false;
        }

        $path = $this->path();

        File::ensureDirectoryExists(dirname($path));

        // A name of its own per run: PharData remembers an archive by path for the life of the
        // process, and a worker lives long enough to download twice.
        $archive = dirname($path).'/'.uniqid('download-', true).'.tar.gz';
        $staged = $archive.'.mmdb';

        try {
            $response = $this->download($accountId, $licenseKey)->throw();

            File::put($archive, $response->body());
            $this->extract($archive, $staged);

            // Throws on anything that is not a MaxMind database.
            (new Reader($staged))->close();

            File::move($staged, $path);
        } finally {
            File::delete([$archive, $staged]);
        }

        return true;
    }

    /**
     * With an account ID, MaxMind's current endpoint, which authenticates the pair. With the key
     * alone, the older one that takes it in the query string: still served, and all a key by
     * itself can use — but it puts the secret in a URL, so the pair is the better setup.
     */
    private function download(string $accountId, string $licenseKey): Response
    {
        $http = Http::timeout(self::TIMEOUT_SECONDS);

        return $accountId !== ''
            ? $http->withBasicAuth($accountId, $licenseKey)
                ->get('https://download.maxmind.com/geoip/databases/'.self::EDITION.'/download', ['suffix' => 'tar.gz'])
            : $http->get('https://download.maxmind.com/app/geoip_download', [
                'edition_id' => self::EDITION,
                'license_key' => $licenseKey,
                'suffix' => 'tar.gz',
            ]);
    }

    public function path(): string
    {
        return (string) config('services.maxmind.database');
    }

    /** The archive holds one dated directory with the database and its licence texts inside. */
    private function extract(string $archive, string $to): void
    {
        foreach (new \RecursiveIteratorIterator(new \PharData($archive)) as $file) {
            if (str_ends_with($file->getFilename(), '.mmdb')) {
                File::copy($file->getPathname(), $to);

                return;
            }
        }

        throw new RuntimeException('The MaxMind archive holds no .mmdb file.');
    }
}
