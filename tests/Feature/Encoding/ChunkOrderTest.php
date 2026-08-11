<?php

/**
 * The packager splices a rendition back together from the chunks the fan-out produced. Order is
 * everything there: `-c copy` concat trusts the list it is handed, and a wrong order publishes a
 * complete, correctly-sized, perfectly playable file whose scenes are in the wrong places.
 */

use App\Models\Video;

it('names chunks so that sorting them by name keeps them in encode order', function (int $count) {
    $names = array_map(fn (int $i) => Video::chunkFilename($i), range(0, $count - 1));

    $sorted = $names;
    sort($sorted);

    // The padding used to be three digits, so chunk_1000 sorted between chunk_100 and chunk_101 —
    // and since the packager only ever checked the chunk COUNT, the scramble was invisible.
    expect($sorted)->toBe($names);
})->with([
    'a short video' => 10,
    'just under the old padding' => 999,
    'just over the old padding' => 1001,
    'a long 4K source' => 5000,
]);

it('keeps reading chunks an older build staged under the narrower padding', function () {
    // A video mid-flight across a deploy has its early chunks on disk under the old name; the
    // packager has to still find them or the retry fails against work that is already done.
    expect(Video::chunkFilenameCandidates(7))->toContain('chunk_000007.mp4', 'chunk_007.mp4');
});

it('writes every chunk under the current padding', function () {
    expect(Video::chunkFilename(7))->toBe('chunk_000007.mp4')
        ->and(Video::chunkFilename(1000))->toBe('chunk_001000.mp4');
});
