<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Video;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    /**
     * Tear a project down through Eloquent, never through the DB cascade: `projects -> videos`
     * cascades in the schema, and a DB-level cascade skips VideoObserver, so every S3 package
     * and source object would be orphaned with no `video.deleted` webhook fired.
     */
    public function delete(Project $project): void
    {
        $this->guardAgainstActiveVideos($project);

        $project->videos()->cursor()->each->delete();
        $project->templates()->cursor()->each->delete();
        $project->tokens()->delete();

        $project->delete();
    }

    private function guardAgainstActiveVideos(Project $project): void
    {
        // Deleting mid-encode races the pipeline: a running PackageVideoJob syncs its output to
        // primary S3 after the wipe, leaving a package nothing will ever collect.
        $active = $project->videos()->whereIn('status', Video::ACTIVE_STATUSES)->count();

        if ($active === 0) {
            return;
        }

        throw ValidationException::withMessages([
            'message' => "Cannot delete this project while {$active} of its videos are still processing.",
        ])->status(409);
    }
}
