<?php

use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function activeVideo(string $status = 'running'): Video
{
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $template = Template::create([
        'name' => 'Template',
        'query' => [],
        'user_id' => $user->id,
        'project_id' => $project->id,
    ]);

    return Video::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'template_id' => $template->id,
        'name' => 'Clip',
        'duration' => 600,
        'aspect_ratio' => '16:9',
        'status' => $status,
    ]);
}

function lastFailureReason(Video $video): ?string
{
    $properties = DB::table('activity_log')
        ->where('subject_id', $video->id)
        ->where('event', 'video_failed')
        ->orderByDesc('id')
        ->value('properties');

    return json_decode((string) $properties, true)['reason'] ?? null;
}

describe('failure reason', function () {
    it('records why the video failed, where the panel can read it back', function () {
        // Nothing else explains a video no single stream owns the failure of — before this, a
        // reaped video was a dead end only log forensics could account for.
        $video = activeVideo();

        $video->markAsFailed('Preparation failed: template needs an intel worker node.');

        expect($video->fresh()->status)->toBe('failed')
            ->and(lastFailureReason($video))->toBe('Preparation failed: template needs an intel worker node.');
    });

    it('keeps the reason readable when ffmpeg dumps its whole stderr', function () {
        $video = activeVideo();

        // 6 KB in, a head that still fits a panel and a log line out.
        $video->markAsFailed(str_repeat('ffmpeg noise ', 500));

        expect(strlen(lastFailureReason($video)))->toBeLessThan(1100);
    });

    it('logs no reason when it is given none', function () {
        $video = activeVideo();

        $video->markAsFailed();

        expect($video->fresh()->status)->toBe('failed')
            ->and(lastFailureReason($video))->toBeNull();
    });

    it('leaves a video that is already terminal alone', function () {
        $video = activeVideo('completed');

        $video->markAsFailed('too late');

        expect($video->fresh()->status)->toBe('completed')
            ->and(lastFailureReason($video))->toBeNull();
    });

    it('says how long the reaper waited', function () {
        $video = activeVideo();
        $video->forceFill(['last_heartbeat_at' => now()->subHours(3)])->save();

        $this->artisan('videos:reap')->assertSuccessful();

        expect(lastFailureReason($video))->toContain('minutes');
    });
});
