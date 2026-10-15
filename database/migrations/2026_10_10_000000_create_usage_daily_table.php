<?php

use App\Services\UsageService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The metrics table. One row per day per combination of dimensions: nothing reads it finer than a
 * day, so rows are folded into their day's total at write time by an upsert
 * ({@see UsageService::add()}), in the database every worker already holds a connection to.
 *
 * Named `usage_daily` rather than `usage`: USAGE is a keyword in MariaDB, and the grain belongs in
 * the name anyway.
 *
 * Things about the shape that are not free choices:
 *
 * - The unique key over every dimension IS the aggregation. It is what the upsert collides on, so
 *   a dimension left out of it would fold rows that have to stay apart.
 * - Every dimension is NOT NULL with an empty default (`''`, 0). NULLs never collide in a unique
 *   index, so a nullable dimension would insert a fresh row per write instead of adding to one.
 * - `tracking_id` and `external_user_id` take a binary collation. The default one is
 *   case-insensitive, and both are identifiers somebody else chose: `Viewer1` and `viewer1` are two
 *   subscribers, and folding them is billing one for the other's traffic. The other text columns
 *   are written in one spelling only (metric names, a ULID, a canonical address, a cache status),
 *   so the default collation cannot fold two of their values and they are left alone.
 * - `ip` is the canonical text of the address rather than 16 bytes: SQLite (the test database) has
 *   no INET6_NTOA, and a column every read can return as stored needs no function at all.
 * - `value` stays one shared double whose unit lives in the metric name — seconds for
 *   `encoding_cpu`, bytes for the transfer metrics. `requests` counts what was summed into it.
 * - `country` is the one descriptive column that is NOT a dimension of the key. It is a function of
 *   `ip`, resolved once at ingest, so it can never tell two rows apart — and keeping it out of the
 *   key means a row written before the country database was there is filled in by the next write
 *   instead of being split in two.
 * - No foreign keys: 0 means "no project" or "video since deleted", and those bytes are kept.
 *
 * The key leads with `project_id, date` because that is how a tenant reads it. The secondary
 * indexes serve the reads that cannot use that prefix: the instance-wide ones (by date), the
 * account-pinned `/api/usage`, and the two batch reads, which name their values and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('usage_daily')) {
            return;
        }

        // The two columns whose case carries meaning (see above). SQLite compares bytes already and
        // rejects a collation name it does not know; null leaves the column's default alone.
        $exact = Schema::getConnection()->getDriverName() === 'sqlite' ? null : 'utf8mb4_bin';

        Schema::create('usage_daily', function (Blueprint $table) use ($exact) {
            $table->id();
            $table->date('date');
            $table->unsignedInteger('project_id')->default(0);
            $table->unsignedInteger('user_id')->default(0);
            $table->string('metric', 32);
            $table->string('external_user_id')->default('')->collation($exact);
            $table->char('video_ulid', 26)->default('');
            $table->string('ip', 45)->default('');
            // ISO 3166-1 alpha-2, '' when the address resolved to nothing.
            $table->char('country', 2)->default('');
            $table->string('tracking_id', 64)->default('')->collation($exact);
            $table->unsignedSmallInteger('node_id')->default(0);
            $table->string('cache', 12)->default('');
            $table->double('value')->default(0);
            $table->unsignedBigInteger('requests')->default(0);

            $table->unique(
                ['project_id', 'date', 'metric', 'video_ulid', 'tracking_id', 'ip', 'node_id', 'cache', 'user_id', 'external_user_id'],
                'usage_daily_dimensions_unique',
            );
            $table->index(['date', 'metric'], 'usage_daily_date_index');
            $table->index(['user_id', 'date'], 'usage_daily_account_index');
            $table->index(['tracking_id', 'date'], 'usage_daily_tracking_index');
            $table->index(['video_ulid', 'date'], 'usage_daily_video_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_daily');
    }
};
