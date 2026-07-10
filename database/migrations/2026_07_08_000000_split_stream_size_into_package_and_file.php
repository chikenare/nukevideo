<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A stream can store two distinct S3 artifacts, so split the overloaded `size` into one column
     * per artifact (never a pre-summed total): `package_size` = the CMAF playback package,
     * `file_size` = the discrete file (uploaded source for `original`, kept rendition MP4 for
     * video/audio). Both nullable so a stream can exist before its bytes are known. Steps are
     * guarded so a partially-applied run is safe to re-apply.
     */
    public function up(): void
    {
        Schema::table('streams', function (Blueprint $table) {
            if (! Schema::hasColumn('streams', 'package_size')) {
                $table->unsignedBigInteger('package_size')->nullable()->after('type');
            }
            if (! Schema::hasColumn('streams', 'file_size')) {
                $table->unsignedBigInteger('file_size')->nullable()->after('package_size');
            }
        });

        if (Schema::hasColumn('streams', 'size')) {
            // Best-effort carry-over: the original's byte count is its file; every other type's legacy
            // `size` was its packaged footprint.
            DB::table('streams')->where('type', 'original')->update(['file_size' => DB::raw('size')]);
            DB::table('streams')->where('type', '!=', 'original')->update(['package_size' => DB::raw('size')]);

            Schema::table('streams', function (Blueprint $table) {
                $table->dropColumn('size');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('streams', 'size')) {
            Schema::table('streams', function (Blueprint $table) {
                $table->unsignedBigInteger('size')->default(0);
            });

            DB::statement('UPDATE streams SET size = COALESCE(package_size, 0) + COALESCE(file_size, 0)');
        }

        Schema::table('streams', function (Blueprint $table) {
            $table->dropColumn(['package_size', 'file_size']);
        });
    }
};
