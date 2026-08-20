<?php

use App\Models\Project;
use App\Models\Stream;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use App\Services\ChunkPlanner;
use App\Services\Concerns\RecordsEncodeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Three probes measure the same thing with different amounts of company, in an order that depends
 * on the template. The rule that reconciles them is what {@see ChunkPlanner} trusts
 * over its own estimate, so it is worth pinning down on its own.
 */
function rateRecorder(): object
{
    return new class
    {
        use RecordsEncodeRate {
            recordEncodeRate as public record;
        }
    };
}

function streamForRate(): Stream
{
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $template = Template::create([
        'name' => 'Template',
        'query' => ['outputs' => []],
        'user_id' => $user->id,
        'project_id' => $project->id,
    ]);

    $video = Video::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'template_id' => $template->id,
        'name' => 'Clip.mkv',
        'duration' => 600,
        'aspect_ratio' => '16:9',
        'status' => 'running',
    ]);

    return $video->streams()->create([
        'path' => "{$video->ulid}/video/rendition.mp4",
        'type' => 'video',
        'width' => 1920,
        'height' => 1080,
        'input_params' => ['video_codec' => 'libsvtav1'],
        'meta' => [],
    ]);
}

it('records wall time as a cost per second of source', function () {
    $stream = streamForRate();

    rateRecorder()->record($stream, 64.0, 20.0);

    expect($stream->fresh()->meta['encode_rate'])->toBe(3.2);
});

it('keeps the least optimistic reading, whichever probe got there first', function () {
    // Every reading is optimistic against a chunk running with the node's full pool, so the
    // highest one is the closest to the truth — and taking the max is what makes the order the
    // probes happen to run in stop mattering.
    $stream = streamForRate();
    $recorder = rateRecorder();

    $recorder->record($stream, 64.0, 20.0);   // per-title anchors, two at a time
    $recorder->record($stream, 30.0, 20.0);   // bitrate probe, running alone
    $recorder->record($stream, 0.4, 1.0);     // preflight, one second of the opening

    expect($stream->fresh()->meta['encode_rate'])->toBe(3.2);
});

it('reaches the same reading in the opposite order', function () {
    $stream = streamForRate();
    $recorder = rateRecorder();

    $recorder->record($stream, 0.4, 1.0);
    $recorder->record($stream, 30.0, 20.0);
    $recorder->record($stream, 64.0, 20.0);

    expect($stream->fresh()->meta['encode_rate'])->toBe(3.2);
});

it('writes nothing when a probe measured nothing', function () {
    // A timed-out sample and a probe that never ran both arrive here as zero; neither is evidence
    // that the encode is free.
    $stream = streamForRate();

    rateRecorder()->record($stream, 0.0, 20.0);
    rateRecorder()->record($stream, 12.0, 0.0);

    expect($stream->fresh()->meta)->not->toHaveKey('encode_rate');
});

it('leaves the rest of the stream meta alone', function () {
    $stream = streamForRate();
    $stream->update(['meta' => ['source_fps' => 23.976, 'quality_bitrate' => 2400000]]);

    rateRecorder()->record($stream->fresh(), 64.0, 20.0);

    expect($stream->fresh()->meta)
        ->toMatchArray(['source_fps' => 23.976, 'quality_bitrate' => 2400000, 'encode_rate' => 3.2]);
});

it('survives the meta write the probe makes right after it', function () {
    // PerTitleCrfService measures the anchors (recording the rate), then writes meta['per_title']
    // built from $this->stream->meta. If that read predated the rate, the CRF write would erase a
    // measurement taken seconds earlier and the planner would silently fall back to its estimate.
    $stream = streamForRate();

    rateRecorder()->record($stream, 64.0, 20.0);

    // Exactly what resolve() does: read meta off the same instance, add to it, write it back.
    $meta = $stream->meta ?? [];
    $meta['per_title'] = ['chosen_crf' => 27];
    $stream->update(['meta' => $meta]);

    expect($stream->fresh()->meta)
        ->toHaveKey('encode_rate', 3.2)
        ->toHaveKey('per_title');
});
