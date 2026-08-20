<?php

use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use App\Services\ChunkPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The pure calculations are covered in ChunkWindowTest. This covers the plumbing that feeds them:
 * resolved parameters reach the planner from `streams.input_params`, through the array cast and
 * the codec catalogue, and the video's window is the tightest of its renditions. The two videos
 * below are the real pair that failed and completed on 2026-08-19 — same source, same 23.976fps,
 * one per template.
 */
function planningVideo(array $renditions): Video
{
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $template = Template::create([
        'name' => 'Template',
        'query' => ['outputs' => [['video_codec' => 'libsvtav1', 'variants' => []]]],
        'user_id' => $user->id,
        'project_id' => $project->id,
    ]);

    $video = Video::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'template_id' => $template->id,
        'name' => 'Feature.mkv',
        'duration' => 3619.579,
        'aspect_ratio' => '16:9',
        'status' => 'running',
    ]);

    foreach ($renditions as $i => [$width, $height, $params]) {
        $video->streams()->create([
            'path' => "{$video->ulid}/video/rendition{$i}.mp4",
            'type' => 'video',
            'width' => $width,
            'height' => $height,
            'input_params' => $params,
            'meta' => ['source_width' => 1920, 'source_height' => 1080, 'source_fps' => 23.976],
        ]);
    }

    return $video;
}

it('sizes an AV1 ladder off the parameters stored on its streams', function () {
    // Template 5's variants, verbatim: the 1080p rung carries svtav1_preset, the 720p one does not
    // and falls back to the codec base.
    $video = planningVideo([
        [1920, 1080, [
            'video_codec' => 'libsvtav1',
            'svtav1_preset' => 6,
            'svtav1_crf' => 25,
            'pixel_format' => 'yuv420p10le',
            'svtav1_film_grain' => 10,
            'gop_size' => 60,
        ]],
        [1280, 720, [
            'video_codec' => 'libsvtav1',
            'svtav1_crf' => 32,
            'pixel_format' => 'yuv420p',
            'gop_size' => 60,
        ]],
    ]);

    // 15s from the 1080p rung, 27s from the 720p one; the video takes the tightest.
    expect((new ChunkPlanner)->secondsFor($video))->toBe(15);
});

it('leaves an H.264 ladder on exactly the window it completed with', function () {
    // Template 11, the twin that succeeded on the same source and must not move.
    $video = planningVideo([
        [1920, 1080, ['video_codec' => 'libx264', 'preset' => 'medium', 'crf' => 23, 'level' => '4.1']],
        [1280, 720, ['video_codec' => 'libx264', 'preset' => 'medium', 'crf' => 23, 'level' => '3.1']],
    ]);

    expect((new ChunkPlanner)->secondsFor($video))->toBe(150);
});

it('lets the slowest rendition set the window for the whole video', function () {
    // Chunk boundaries are shared across renditions, so a mixed ladder cannot give the AV1 rung a
    // window its own encoder can finish and the x264 rung a longer one.
    $video = planningVideo([
        [1920, 1080, ['video_codec' => 'libx264', 'preset' => 'medium']],
        [1920, 1080, ['video_codec' => 'libsvtav1', 'svtav1_preset' => 4]],
    ]);

    expect((new ChunkPlanner)->secondsFor($video))->toBe(8);
});

it('falls back to the reference window when a stream carries no parameters at all', function () {
    $video = planningVideo([[1920, 1080, []]]);

    expect((new ChunkPlanner)->secondsFor($video))->toBe(150);
});

/** ffprobe's `packet=pts_time,flags` CSV for a source keyframed every $gop seconds. */
function probeWithKeyframes(float $duration, float $gop, float $fps = 23.976): string
{
    $lines = [];

    for ($t = 0.0; $t < $duration; $t += 1 / $fps) {
        $key = fmod($t + 1e-9, $gop) < (1 / $fps);
        $lines[] = sprintf('%.6f,%s', $t, $key ? 'K__' : '___');
    }

    return implode("\n", $lines)."\n";
}

describe('what the plan reports about itself', function () {
    it('measures the interval the source actually offers, ignoring scene cuts', function () {
        // Regular keyframes every 2s with a scene cut between two of them: the median must report
        // the grid the file offers, not an average dragged down by the odd extra keyframe.
        $probe = "0.000000,K__\n1.000000,K__\n2.000000,K__\n4.000000,K__\n6.000000,K__\n8.000000,K__\n";

        $plan = (new ChunkPlanner)->plan(planningVideo([[1920, 1080, ['video_codec' => 'libx264', 'preset' => 'medium']]]), $probe, 8.0);

        expect($plan->keyframeInterval)->toBe(2.0);
    });

    it('reports the window it wanted next to the one the clamp allowed', function () {
        // 8K AV1: the model asks for 0.75s and MIN_WINDOW hands it 8 — a floor no sizing can get
        // under, which is why the chunk carries ten times the work the model budgeted for it.
        $video = planningVideo([[7680, 4320, ['video_codec' => 'libsvtav1', 'svtav1_preset' => 6]]]);
        $video->streams()->update(['meta' => ['source_width' => 7680, 'source_height' => 4320, 'source_fps' => 30.0]]);

        $plan = (new ChunkPlanner)->plan($video->fresh(), probeWithKeyframes(60.0, 2.0, 30.0), 60.0);

        expect($plan->target)->toBe(8)
            ->and(round($plan->ideal, 2))->toBe(0.75)
            ->and($plan->withinBudget())->toBeFalse()
            ->and($plan->limitedBy())->toBe('window floor');
    });

    it('leaves a 4K AV1 ladder alone, because the floor lifts it but it still fits', function () {
        // The floor takes it from 3s to 8s — a 2.7x overshoot that measurement says still lands
        // inside the deadline. Warning here would spend the reader's attention on a video that
        // encodes fine.
        $video = planningVideo([[3840, 2160, ['video_codec' => 'libsvtav1', 'svtav1_preset' => 6]]]);
        $video->streams()->update(['meta' => ['source_width' => 3840, 'source_height' => 2160, 'source_fps' => 30.0]]);

        $plan = (new ChunkPlanner)->plan($video->fresh(), probeWithKeyframes(60.0, 2.0, 30.0), 60.0);

        expect($plan->target)->toBe(8)
            ->and(round($plan->ideal, 2))->toBe(3.0)
            ->and($plan->withinBudget())->toBeTrue();
    });

    it('names the source keyframes when they are what stops the window shrinking', function () {
        // The AV1 ladder asks for 15s; a master keyframed once a minute has no such cut to give.
        $video = planningVideo([[1920, 1080, ['video_codec' => 'libsvtav1', 'svtav1_preset' => 6]]]);

        $plan = (new ChunkPlanner)->plan($video, probeWithKeyframes(600.0, 60.0), 600.0);

        expect($plan->target)->toBe(15)
            ->and($plan->longestWindow())->toBeGreaterThan(59.0)
            ->and($plan->limitedBy())->toBe('source keyframes')
            ->and($plan->withinBudget())->toBeFalse();
    });

    it('stays quiet about the rounding every ordinary source causes', function () {
        // The real 2.002s grid this fleet measured: a 15s target lands on 16s, which is the planner
        // working as designed and must not read as a problem.
        $video = planningVideo([[1920, 1080, ['video_codec' => 'libsvtav1', 'svtav1_preset' => 6]]]);

        $plan = (new ChunkPlanner)->plan($video, probeWithKeyframes(300.0, 2.002), 300.0);

        expect($plan->longestWindow())->toBeGreaterThan(15.0)->toBeLessThan(17.0)
            ->and($plan->withinBudget())->toBeTrue()
            ->and($plan->limitedBy())->toBe('nothing');
    });

    it('plans the same windows the pure calculation does', function () {
        // plan() and windowsFromPackets() must not drift: the first is the second plus its reasons.
        $video = planningVideo([[1920, 1080, ['video_codec' => 'libx264', 'preset' => 'medium']]]);
        $planner = new ChunkPlanner;
        $probe = probeWithKeyframes(600.0, 2.002);

        expect($planner->plan($video, $probe, 600.0)->windows)
            ->toBe($planner->windowsFromPackets($probe, 600.0, $planner->secondsFor($video)));
    });
});

describe('planning against what the node actually measured', function () {
    it('predicts the chunk from the rate the probes measured, not from the model', function () {
        // 15s windows on a node measured at 3.2 wall seconds per source second: ~51s a chunk,
        // which is what this fleet really recorded for a 1080p10 AV1 window.
        $video = planningVideo([[1920, 1080, ['video_codec' => 'libsvtav1', 'svtav1_preset' => 6]]]);
        $video->streams()->update(['meta' => [
            'source_width' => 1920, 'source_height' => 1080, 'source_fps' => 23.976, 'encode_rate' => 3.2,
        ]]);

        $plan = (new ChunkPlanner)->plan($video->fresh(), probeWithKeyframes(300.0, 2.002), 300.0);

        expect($plan->predictedSeconds())->toBeGreaterThan(45.0)->toBeLessThan(55.0)
            ->and($plan->withinBudget())->toBeTrue()
            ->and($plan->context())->toHaveKeys(['encode_rate', 'predicted', 'deadline']);
    });

    it('trusts the measurement over the estimate when they disagree', function () {
        // The model is happy — a 15s target landing on 16s is a 1.1x overshoot — but this node
        // measured 20 wall seconds per source second, so the chunk needs ~320s of a 480s deadline.
        // The estimate would have said nothing; the measurement is what the chunk will actually do.
        $video = planningVideo([[1920, 1080, ['video_codec' => 'libsvtav1', 'svtav1_preset' => 6]]]);
        $video->streams()->update(['meta' => [
            'source_width' => 1920, 'source_height' => 1080, 'source_fps' => 23.976, 'encode_rate' => 20.0,
        ]]);

        $plan = (new ChunkPlanner)->plan($video->fresh(), probeWithKeyframes(300.0, 2.002), 300.0);

        expect($plan->overshoot())->toBeLessThan(1.2)
            ->and($plan->predictedSeconds())->toBeGreaterThan(300.0)
            ->and($plan->withinBudget())->toBeFalse();
    });

    it('takes the dearest rendition, since they all share the window', function () {
        $video = planningVideo([
            [1920, 1080, ['video_codec' => 'libsvtav1', 'svtav1_preset' => 6]],
            [1280, 720, ['video_codec' => 'libsvtav1', 'svtav1_preset' => 6]],
        ]);
        $streams = $video->streams()->where('type', 'video')->orderBy('id')->get();
        $streams[0]->update(['meta' => [...$streams[0]->meta, 'encode_rate' => 3.2]]);
        $streams[1]->update(['meta' => [...$streams[1]->meta, 'encode_rate' => 1.1]]);

        $plan = (new ChunkPlanner)->plan($video->fresh(), probeWithKeyframes(300.0, 2.002), 300.0);

        expect($plan->encodeRate)->toBe(3.2);
    });

    it('falls back to the estimate when no probe measured anything', function () {
        // A short video skips the bitrate probe and a foreign-hardware rendition skips both, which
        // must leave the plan exactly where it was before any of this existed.
        $video = planningVideo([[1920, 1080, ['video_codec' => 'libsvtav1', 'svtav1_preset' => 6]]]);

        $plan = (new ChunkPlanner)->plan($video, probeWithKeyframes(600.0, 60.0), 600.0);

        expect($plan->encodeRate)->toBeNull()
            ->and($plan->predictedSeconds())->toBeNull()
            ->and($plan->withinBudget())->toBeFalse()
            ->and($plan->context())->not->toHaveKey('predicted');
    });
});
