<?php

namespace App\Services;

readonly class SampleResult
{
    public function __construct(
        public string $command,
        public bool $successful,
        public int $bytes,
        public string $error,
    ) {}

    /** An encoder that refuses a parameter set aborts having written nothing, exit code aside. */
    public function wrote(): bool
    {
        return $this->successful && $this->bytes > 0;
    }
}
