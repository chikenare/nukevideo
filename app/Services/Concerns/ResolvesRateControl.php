<?php

namespace App\Services\Concerns;

/**
 * Every decision about how many bits a rendition may spend. One question throughout — never spend
 * more than the source did ({@see sourceBitrateCap}) — and one entry point ({@see resolveRateControl});
 * the branches differ only in the lever each encoder honours. Reads `$this->stream` and
 * `parseBitrateValue()` from {@see DetectsStreamCopy}.
 */
trait ResolvesRateControl
{
    /** Below this a source bitrate is bogus probe data, and the cap would emit '-maxrate 0k'. */
    private const MIN_CLAMP_BPS = 100_000;

    /** Encoders whose quality mode honours no VBV, so their ceiling can't be left to the encoder. */
    private const VBV_BLIND_CODECS = ['av1_qsv'];

    /** Sample windows are the busy middle of a runtime, so tolerate a small overshoot. */
    private const OVERSHOOT_TOLERANCE = 1.2;

    /** A pinned average as a fraction of the peak cap, shared by QVBR steering and the VBR fallback. */
    private const AVERAGE_TARGET_RATIO = 0.75;

    /** Encoders that abort when a quality target is pinned next to an average bitrate. */
    private const ABR_WITHOUT_QUALITY_KNOB = ['libsvtav1', 'av1_qsv'];

    /** Encoders that abort when a VBV is attached to an average bitrate. */
    private const ABR_WITHOUT_VBV = ['libsvtav1'];

    /** The mode this rendition encodes in and the ceiling it may not cross. */
    private function resolveRateControl(array $params): array
    {
        // ABR: the template pinned an average, so it carries its own ceiling.
        if (! empty($params['constant_bitrate'])) {
            return self::normalizeAbr($params);
        }

        $cap = $this->sourceBitrateCap();

        if (in_array($params['video_codec'] ?? null, self::VBV_BLIND_CODECS, true)) {
            return $this->capBlindQualityMode($params, $cap);
        }

        if ($cap !== null) {
            $params = $this->clampMaxrateToSource($params, $cap);
        }

        return self::accelForCodec($params['video_codec'] ?? null) === 'intel'
            ? $this->steerQsvRateControl($params)
            : $params;
    }

    /**
     * The rendition's share of the source's own bitrate: the ceiling a re-encode must not outweigh.
     * Null when the probe data can't support one, or when the target codec is less efficient than
     * the source's — matching an AV1 source's bitrate with x264 would starve it.
     */
    public function sourceBitrateCap(): ?int
    {
        $meta = $this->stream->meta ?? [];

        if (self::codecRank(data_get($this->stream->input_params, 'video_codec')) < self::codecRank($meta['source_codec'] ?? null)) {
            return null;
        }

        $sourceRate = (int) ($meta['source_bit_rate'] ?? 0);
        $sourcePixels = (int) ($meta['source_width'] ?? 0) * (int) ($meta['source_height'] ?? 0);
        $targetPixels = (int) $this->stream->width * (int) $this->stream->height;

        if ($sourceRate <= 0 || $sourcePixels <= 0 || $targetPixels <= 0) {
            return null;
        }

        // Bitrate scales sublinearly with resolution (~0.75 power law).
        $cap = (int) round($sourceRate * min(1.0, ($targetPixels / $sourcePixels) ** 0.75));

        return $cap >= self::MIN_CLAMP_BPS ? $cap : null;
    }

    /** Whether this rendition's ceiling has to be measured before the encode instead of enforced during it. */
    public function needsQualityBitrateProbe(): bool
    {
        $params = $this->stream->input_params ?? [];

        return in_array($params['video_codec'] ?? null, self::VBV_BLIND_CODECS, true)
            && empty($params['constant_bitrate'])
            && $this->sourceBitrateCap() !== null;
    }

    /**
     * AV1 on QSV honours no VBV: `-maxrate` silently selects CQP at the driver's default QP and
     * `-global_quality` stops being read with it, and there is no QVBR either (Arc B580 / iHD 1.22).
     * Its ceiling can only be chosen up front, from what the quality mode was measured to cost
     * ({@see \App\Services\QualityBitrateProbe}) — an overshoot is re-issued as capped VBR.
     */
    private function capBlindQualityMode(array $params, ?int $cap): array
    {
        $measured = (int) data_get($this->stream->meta, 'quality_bitrate', 0);

        if ($cap === null || $measured <= $cap * self::OVERSHOOT_TOLERANCE) {
            unset($params['maxrate'], $params['bufsize']);

            return $params;
        }

        return [
            ...self::withoutQualityKnob($params),
            'constant_bitrate' => self::kbps($cap * self::AVERAGE_TARGET_RATIO),
            'maxrate' => self::kbps($cap),
            'bufsize' => self::kbps($cap * 2),
        ];
    }

    /**
     * A pinned average is its own ceiling, but not every encoder takes the rest of the template next
     * to it — SVT-AV1 refuses a quality target and a VBV, av1_qsv refuses the quality target — and
     * each aborts having written nothing. QSV h264/hevc pair quality with a bitrate on purpose (that
     * is QVBR) and nvenc reads it as a VBR quality target, so both keep theirs.
     */
    private static function normalizeAbr(array $params): array
    {
        $codec = $params['video_codec'] ?? null;

        if (in_array($codec, self::ABR_WITHOUT_QUALITY_KNOB, true)) {
            $params = self::withoutQualityKnob($params);
        }

        if (in_array($codec, self::ABR_WITHOUT_VBV, true)) {
            unset($params['maxrate'], $params['bufsize']);
        }

        return $params;
    }

    private static function withoutQualityKnob(array $params): array
    {
        unset($params['qsv_global_quality'], $params['nvenc_cq'], $params['crf'], $params['svtav1_crf']);

        return $params;
    }

    /** Tighten the template's own VBV to the cap. Only ever tightens: a leaner template is already fine. */
    private function clampMaxrateToSource(array $params, int $cap): array
    {
        if (empty($params['maxrate'])) {
            return $params;
        }

        $maxrate = $this->parseBitrateValue($params['maxrate']);

        if ($cap >= $maxrate) {
            return $params;
        }

        if (! empty($params['bufsize'])) {
            // Keep the template's own bufsize:maxrate ratio — a strict 1x VBV stays strict.
            $params['bufsize'] = self::kbps($cap * $this->parseBitrateValue($params['bufsize']) / max(1, $maxrate));
        }

        $params['maxrate'] = self::kbps($cap);

        return $params;
    }

    /**
     * Steer a capped QSV quality mode into QVBR (Arc B580 / iHD): global_quality+maxrate alone
     * selects CQP and silently drops both, and QVBR only engages with a -b:v below the cap.
     * AV1 never reaches this — it has no QVBR ({@see capBlindQualityMode}).
     */
    private function steerQsvRateControl(array $params): array
    {
        if (empty($params['qsv_global_quality']) || empty($params['maxrate'])) {
            return $params;
        }

        $params['constant_bitrate'] = self::kbps($this->parseBitrateValue($params['maxrate']) * self::AVERAGE_TARGET_RATIO);

        return $params;
    }

    /** NVENC's CQ mode only bites with `-b:v 0` — its default 2M bitrate target caps it otherwise. */
    private function nvencBitrateReset(array $params): string
    {
        return ! empty($params['nvenc_cq']) && empty($params['constant_bitrate']) ? ' -b:v 0' : '';
    }

    /** Rough compression-efficiency ordering of codec generations. */
    private static function codecRank(?string $codec): int
    {
        return match ($codec) {
            'libsvtav1', 'av1', 'av1_qsv', 'av1_nvenc' => 3,
            'libx265', 'hevc', 'vp9', 'hevc_qsv', 'hevc_nvenc' => 2,
            default => 1, // h264 family and anything older/unknown
        };
    }

    private static function kbps(float $bps): string
    {
        return max(1, (int) round($bps / 1000)).'k';
    }
}
