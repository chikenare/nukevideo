<?php

namespace App\Http\Controllers\Api;

use App\Data\AppSettings\UpdateSshKeyData;
use App\Data\AppSettingsData;
use App\Http\Controllers\Controller;
use App\Services\SshKeyService;
use App\Settings\AppSettings;
use Illuminate\Http\JsonResponse;

class AppSettingsController extends Controller
{
    public function __construct(private SshKeyService $sshKeys) {}

    public function show(AppSettings $settings): JsonResponse
    {
        return response()->json(['data' => AppSettingsData::fromSettings($settings)]);
    }

    public function updateSshKey(UpdateSshKeyData $data): JsonResponse
    {
        return response()->json(['data' => AppSettingsData::fromSettings($this->sshKeys->set($data->privateKey))]);
    }

    public function rotateSshKey(): JsonResponse
    {
        return response()->json(['data' => $this->sshKeys->rotate()]);
    }
}
