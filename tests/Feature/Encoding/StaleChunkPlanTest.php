<?php

use App\Jobs\PrepareVideoJob;
use App\Models\Project;
use App\Models\Stream;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('chunks'));

function planRendition(array $params = []): Stream
{
    $video = projectVideo(Project::factory()->create());

    return $video->streams()->create([
        'path' => "{$video->ulid}/video/".Str::ulid().'.mp4',
        'type' => 'video',
        'width' => 1920,
        'height' => 1080,
        'input_params' => $params + ['video_codec' => 'libsvtav1', 'svtav1_crf' => 26, 'svtav1_preset' => 6, 'gop_size' => 48],
        'meta' => [],
    ]);
}

function discardStaleChunks(Stream $stream, array $windows): void
{
    $video = Video::find($stream->video_id);
    $job = new PrepareVideoJob($video->id, 'original.mp4');

    (fn () => $this->discardStaleChunks($video, $video->streams()->where('type', 'video')->get(), $windows))->call($job);
}

function stageChunk(Stream $stream, int $index): string
{
    $key = Video::find($stream->video_id)->chunkKey($stream, $index);
    Storage::disk('chunks')->put($key, 'chunk');

    return $key;
}

it('keeps staged chunks when a retry plans the same windows and parameters', function () {
    $stream = planRendition();
    discardStaleChunks($stream, [[0.0, 15.0], [15.0, 30.0]]);
    $chunk = stageChunk($stream, 0);

    discardStaleChunks($stream, [[0.0, 15.0], [15.0, 30.0]]);

    Storage::disk('chunks')->assertExists($chunk);
});

it('discards staged chunks when a retry cuts the windows differently', function () {
    // Index 1 used to start at 15s; a chunk encoded for that window would repeat footage here.
    $stream = planRendition();
    discardStaleChunks($stream, [[0.0, 15.0], [15.0, 30.0]]);
    $chunk = stageChunk($stream, 1);

    discardStaleChunks($stream, [[0.0, 10.0], [10.0, 20.0], [20.0, 30.0]]);

    Storage::disk('chunks')->assertMissing($chunk);
});

it('discards staged chunks when the rendition would now encode differently', function () {
    $stream = planRendition();
    discardStaleChunks($stream, [[0.0, 15.0]]);
    $chunk = stageChunk($stream, 0);

    $stream->update(['input_params' => ['svtav1_crf' => 30] + $stream->input_params]);
    discardStaleChunks($stream->refresh(), [[0.0, 15.0]]);

    Storage::disk('chunks')->assertMissing($chunk);
});

it('adopts chunks staged before plans were recorded', function () {
    $stream = planRendition();
    $chunk = stageChunk($stream, 0);

    discardStaleChunks($stream, [[0.0, 15.0]]);

    Storage::disk('chunks')->assertExists($chunk);
});

it('keeps the plan out of the rendition directory, where it would read as a chunk', function () {
    $stream = planRendition();
    discardStaleChunks($stream, [[0.0, 15.0]]);

    $video = Video::find($stream->video_id);
    expect(Storage::disk('chunks')->files("{$video->chunksDir()}/{$stream->ulid}"))->toBe([])
        ->and(Storage::disk('chunks')->exists("{$video->chunksDir()}/{$stream->ulid}.plan"))->toBeTrue();
});
