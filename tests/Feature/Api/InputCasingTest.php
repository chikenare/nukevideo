<?php

/**
 * Requests are camelCase, end to end.
 *
 * They were not always: the global input mapper is snake_case, so the read endpoints took
 * `per_page` and `tracking_ids` while every write took the camelCase spelling of the same idea.
 * Worse than inconsistent — Spatie binds a property's own name as a fallback too, while `rules()`,
 * `prepareForPipeline()` and validation attributes key on the MAPPED name, so the camelCase
 * spelling reached the property having skipped every guard written for it. A page-size cap of 100
 * answered `?perPage=100000`, and `trackingIds` walked past both a 1000-element cap and the
 * charset check. One spelling per field is what closes that: one name for the mapper, the rules
 * and the clamp alike.
 *
 * These pin the guards on the endpoints where one was actually reachable.
 */

use App\Data\Analytics\BatchQueryData;
use App\Data\Video\IndexVideosData;
use App\Models\Output;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);
});

it('clamps the listing page size', function () {
    // Each row of that page embeds the video's outputs and streams, so an unclamped size is not
    // just a big answer.
    expect($this->getJson('/api/videos?perPage=100000')->assertOk()->json('perPage'))
        ->toBe(IndexVideosData::PER_PAGE_MAX);
});

it('applies the metrics batch cap and the tracking id charset', function () {
    $over = array_map(fn ($i) => "id-{$i}", range(1, BatchQueryData::MAX_BATCH + 1));

    $this->postJson('/api/metrics', [
        'from' => '2026-01-01', 'to' => '2026-01-31', 'dimensions' => ['date'], 'trackingIds' => $over,
    ])->assertStatus(422);

    // The alphabet is the contract: a value that passes here is a value the log ingest keeps, so
    // one that slips past is traffic attributed to nobody.
    $this->postJson('/api/metrics', [
        'from' => '2026-01-01', 'to' => '2026-01-31', 'dimensions' => ['date'], 'trackingIds' => ['a&b=c'],
    ])->assertStatus(422);
});

it('applies the playback link attribute rules', function () {
    $video = Video::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'name' => 'Clip', 'duration' => 10, 'aspect_ratio' => '16:9', 'status' => 'completed',
    ]);
    $output = Output::create(['video_id' => $video->id, 'status' => 'completed']);
    $output->recordFormats(['dash', 'hls']);

    // `#[Max(255)]` lives in an attribute here — the case that writing every rule twice, the old
    // workaround, could never have covered: there is no second key to write.
    $this->postJson("/api/outputs/{$output->ulid}", ['externalUserId' => str_repeat('x', 300)])
        ->assertStatus(422);
});

it('leaves a snake_case key unbound rather than refusing it', function () {
    Video::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'name' => 'only', 'duration' => 1, 'aspect_ratio' => '16:9',
        'status' => 'completed', 'external_user_id' => 'user-1',
    ]);

    // The consequence of the migration, pinned so it is a known answer rather than a surprise:
    // an old snake_case caller is not rejected, its field simply does not arrive. The page size
    // falls back to the default and the filter narrows nothing. Loud where a field is `required`
    // (the batch reads answer 422), silent where it is optional — which is the cost of the break.
    $listing = $this->getJson('/api/videos?per_page=3&external_user_id=nobody')->assertOk();

    expect($listing->json('perPage'))->toBe(IndexVideosData::PER_PAGE_DEFAULT)
        ->and($listing->json('total'))->toBe(1);
});
