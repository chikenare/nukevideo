<?php

namespace App\Enums;

enum VideoStatus: string
{
    case PENDING = 'pending';
    case FAILED = 'failed';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case UPLOADING = 'uploading';
    case DOWNLOADING = 'downloading';
}
