<?php

use App\Services\SubtitlePackager;
use Illuminate\Support\Facades\Process;

/**
 * Shaka fails its ENTIRE run over one unparseable input, so the joint run has to fall back to one
 * run per track — otherwise a single bad subtitle strips every track from the manifest.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/subs-'.uniqid();
    mkdir($this->dir);
    file_put_contents("{$this->dir}/output.mpd", '<MPD/>');
    file_put_contents("{$this->dir}/output.m3u8", '#EXTM3U');

    $this->subs = collect(['a', 'b', 'c'])->map(fn ($ulid) => [
        'path' => "{$this->dir}/{$ulid}.vtt",
        'type' => 'subtitle',
        'ulid' => $ulid,
        'language' => 'en',
        'forced' => false,
        'name' => strtoupper($ulid),
    ])->all();
});

afterEach(function () {
    array_map('unlink', glob("{$this->dir}/*") ?: []);
    rmdir($this->dir);
});

/** Record how many inputs each packager invocation carried, and decide its exit code by that count. */
function fakePackager(ArrayObject $runs, callable $succeeds): void
{
    Process::fake(function ($process) use ($runs, $succeeds) {
        $command = is_array($process->command) ? $process->command : [(string) $process->command];
        $inputs = count(array_filter($command, fn ($arg) => str_starts_with((string) $arg, 'in=')));
        $runs[] = $inputs;

        return $succeeds($inputs)
            ? Process::result('ok')
            : Process::result(output: '', errorOutput: 'PARSER_FAILURE', exitCode: 1);
    });
}

it('packages every track in a single run when they all parse', function () {
    fakePackager($runs = new ArrayObject, fn () => true);

    app(SubtitlePackager::class)->packageAndGraft($this->subs, $this->dir, 100, 60);

    // One run per format, three tracks each — no per-track retry.
    expect($runs->getArrayCopy())->toBe([3, 3]);
});

it('falls back to one run per track when the joint run fails', function () {
    // Only the joint run fails, exactly as a single malformed cue makes it.
    fakePackager($runs = new ArrayObject, fn (int $inputs) => $inputs === 1);

    app(SubtitlePackager::class)->packageAndGraft($this->subs, $this->dir, 100, 60);

    expect($runs->getArrayCopy())->toBe([3, 1, 1, 1, 3, 1, 1, 1]);
});

it('does not retry a lone track that fails', function () {
    fakePackager($runs = new ArrayObject, fn () => false);

    app(SubtitlePackager::class)->packageAndGraft([$this->subs[0]], $this->dir, 100, 60);

    expect($runs->getArrayCopy())->toBe([1, 1]);
});
