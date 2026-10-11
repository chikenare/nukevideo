<?php

use App\Services\CloudflareDns;
use App\Settings\CloudflareSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a proxy's DNS record goes behind Cloudflare's proxy is not one answer for a fleet: an
 * edge that needs its address hidden wants it, one pushing most of the video traffic is better
 * reached directly. The installation's setting ({@see CloudflareSettings}) stays the default and
 * this column overrides it per node — null, which is what every existing node gets, means "as
 * the installation says". Read by {@see CloudflareDns::sync()} at deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('nodes', 'cloudflare_proxied')) {
            return;
        }

        Schema::table('nodes', function (Blueprint $table) {
            $table->boolean('cloudflare_proxied')->nullable()->after('hostname');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('nodes', 'cloudflare_proxied')) {
            Schema::table('nodes', fn (Blueprint $table) => $table->dropColumn('cloudflare_proxied'));
        }
    }
};
