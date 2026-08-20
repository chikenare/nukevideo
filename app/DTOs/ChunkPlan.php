<?php

namespace App\DTOs;

/**
 * The chunk windows a video was cut into, and the three numbers that explain why they came out the
 * length they did: what the cost model asked for, what the clamps allowed, and what the source's
 * own keyframes actually permitted.
 *
 * They are carried together because separately none of them is an answer. A chunk that overruns the
 * per-chunk timeout is a fact about the last of the three, and by the time the timeout fires the
 * first two are gone — which is exactly how a feature film spent eight of its windows discovering,
 * 480 seconds at a time, that nothing had ever been able to size them.
 */
class ChunkPlan
{
    /**
     * The sizing model aims a chunk at roughly an eighth of the per-chunk ffmpeg deadline — measured
     * on this fleet: ~58s of encode against 480s. An overshoot spends that margin proportionally, so
     * at 3x two thirds of it is gone and a timeout stops being a surprise.
     *
     * Calibrated against the shapes we can measure rather than picked: ordinary keyframe rounding
     * (a 15s window landing on 16s) sits at 1.1x and stays silent, a 4K AV1 ladder lifted off the
     * window floor sits at 2.7x and does still fit, while a master keyframed once a minute (4.0x)
     * and an 8K AV1 ladder (10.7x) are both reported.
     */
    private const OVERSHOOT_BUDGET = 3.0;

    /**
     * Share of the per-chunk deadline a measured prediction may claim. Half, because the prediction
     * is optimistic by construction — the samples it comes from ran with the node far quieter than
     * the fan-out will leave it. On the fleet's own measurements a chunk pool doubles its own
     * wall times, so half the deadline is where a chunk stops being safe rather than where it
     * starts being late.
     */
    private const MEASURED_BUDGET = 0.5;

    /**
     * @param  list<array{0:float,1:float}>  $windows
     * @param  int  $target  window length asked for, after the clamps
     * @param  float  $ideal  window length the cost model wanted, before them
     * @param  float  $keyframeInterval  median gap between the source's keyframes
     * @param  float|null  $encodeRate  measured wall seconds per source second, null if unmeasured
     * @param  int  $deadline  the per-chunk ffmpeg deadline these windows have to finish inside
     */
    public function __construct(
        public readonly array $windows,
        public readonly int $target,
        public readonly float $ideal,
        public readonly float $keyframeInterval,
        public readonly ?float $encodeRate = null,
        public readonly int $deadline = 0,
    ) {}

    /** The longest window produced: the chunk that reaches the timeout first, if any does. */
    public function longestWindow(): float
    {
        $lengths = array_map(fn (array $w) => $w[1] - $w[0], $this->windows);

        return $lengths ? max($lengths) : 0.0;
    }

    /** How many times more source a chunk carries than the cost model asked it to. */
    public function overshoot(): float
    {
        return $this->ideal > 0 ? $this->longestWindow() / $this->ideal : 1.0;
    }

    /**
     * What the longest chunk is expected to cost, from what the probes measured on this node. Null
     * when nothing measured.
     *
     * Optimistic on purpose, and knowingly: the samples ran on the orchestration supervisor, while
     * the chunks will run against the node's full encode concurrency. So a prediction that already
     * exceeds the deadline is not a warning, it is a certainty — which is exactly the reading worth
     * having, and why the threshold below sits at half.
     */
    public function predictedSeconds(): ?float
    {
        return $this->encodeRate ? $this->longestWindow() * $this->encodeRate : null;
    }

    /**
     * Evidence first: a measured prediction answers the actual question — does this chunk finish
     * inside the deadline on this node — where the overshoot only says the window drifted from
     * what the model wanted. The estimate stands in when no probe ran.
     */
    public function withinBudget(): bool
    {
        $predicted = $this->predictedSeconds();

        if ($predicted !== null && $this->deadline > 0) {
            return $predicted < $this->deadline * self::MEASURED_BUDGET;
        }

        return $this->overshoot() < self::OVERSHOOT_BUDGET;
    }

    /**
     * Which of the two floors kept the plan over budget. Both can bind at once — an 8K AV1 ladder
     * off a long-GOP master hits the clamp AND the keyframes — so this names the dominant one,
     * because that is the one worth acting on. A plan inside its budget is limited by nothing
     * worth reporting: every window rounds up to some keyframe, and saying so on every video would
     * teach the reader to skip the field on the one video where it matters.
     */
    public function limitedBy(): string
    {
        if ($this->withinBudget()) {
            return 'nothing';
        }

        $byClamp = $this->ideal > 0 ? $this->target / $this->ideal : 1.0;
        $byKeyframes = $this->target > 0 ? $this->longestWindow() / $this->target : 1.0;

        return $byClamp >= $byKeyframes ? 'window floor' : 'source keyframes';
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return [
            'chunks' => count($this->windows),
            'target' => $this->target,
            'ideal' => round($this->ideal, 2),
            'longest' => round($this->longestWindow(), 2),
            'keyframe_interval' => round($this->keyframeInterval, 3),
            'overshoot' => round($this->overshoot(), 1),
            'limited_by' => $this->limitedBy(),
            // Absent rather than null when nothing measured: a log line that says "predicted: null"
            // reads as a failed measurement, when the truth is that no probe was due to run.
            ...$this->encodeRate ? [
                'encode_rate' => round($this->encodeRate, 3),
                'predicted' => round((float) $this->predictedSeconds(), 1),
                'deadline' => $this->deadline,
            ] : [],
        ];
    }
}
