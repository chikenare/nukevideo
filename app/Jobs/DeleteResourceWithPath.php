<?php

namespace App\Jobs;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class DeleteResourceWithPath implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = 10;

    public function __construct(private string $path)
    {
    }

    public function handle(): void
    {
        if (Storage::exists($this->path)) {
            if (!Storage::deleteDirectory($this->path)) {
                throw new Exception("Error deleting dir, please try again. Path: $this->path");
            }
        }
    }
}
