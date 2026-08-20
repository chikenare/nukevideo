<?php

namespace App\Services;

readonly class SampleResult
{
    /**
     * @param  float  $wallSeconds  how long the encode took — the only reading anywhere of what this
     *                              node costs for THIS command, on THIS content
     */
    public function __construct(
        public string $command,
        public bool $successful,
        public int $bytes,
        public string $error,
        public float $wallSeconds = 0.0,
    ) {}

    /** An encoder that refuses a parameter set aborts having written nothing, exit code aside. */
    public function wrote(): bool
    {
        return $this->successful && $this->bytes > 0;
    }
}
