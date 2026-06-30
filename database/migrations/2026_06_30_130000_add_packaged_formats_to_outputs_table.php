<?php

use App\Models\Output;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Freezes the formats an output actually packaged. Output::formats() used to be computed live
     * from the currently-attached streams' codecs on every read — correct at package time, but a
     * later stream deletion (which edits manifests in place, never deletes the file — see
     * ManifestEditor::removeStream) can silently change that computation's result without changing
     * what's actually servable on S3, producing a play link to a manifest that was never packaged.
     */
    public function up(): void
    {
        Schema::table('outputs', function (Blueprint $table) {
            $table->json('packaged_formats')->nullable()->after('status');
        });

        // Backfill already-completed outputs from their current streams, so the protection covers
        // existing data too, not just outputs packaged after this migration. Safe: at this point
        // nothing has deleted a stream out from under them yet, so this matches what packaging
        // already produced. Pending/failed outputs are left null (nothing was ever packaged for them).
        Output::where('status', 'completed')->with('streams')->chunkById(200, function ($outputs) {
            foreach ($outputs as $output) {
                $output->recordFormats($output->computedFormats());
            }
        });
    }

    public function down(): void
    {
        Schema::table('outputs', function (Blueprint $table) {
            $table->dropColumn('packaged_formats');
        });
    }
};
