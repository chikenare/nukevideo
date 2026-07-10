<?php

namespace App\Http\Controllers\Api;

use App\Data\CdnSettingsData;
use App\Enums\CdnDriver;
use App\Http\Controllers\Controller;
use App\Settings\CdnSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CdnSettingsController extends Controller
{
    public function show(CdnSettings $settings): JsonResponse
    {
        return response()->json(['data' => CdnSettingsData::fromSettings($settings)]);
    }

    public function update(Request $request, CdnSettings $settings): JsonResponse
    {
        // Only the active provider's config is required; the other is left as-is so switching
        // providers never forces you to fill a config you aren't using.
        $selfHostedActive = $request->input('provider') === CdnDriver::SelfHosted->value;
        $bunnyActive = $request->input('provider') === CdnDriver::Bunny->value;

        $validated = $request->validate([
            'provider' => ['required', Rule::enum(CdnDriver::class)],

            'selfHosted' => [Rule::requiredIf($selfHostedActive), 'array'],
            'selfHosted.tokenSecret' => ['nullable', 'string'],
            'selfHosted.tokenName' => [Rule::requiredIf($selfHostedActive), 'string'],
            'selfHosted.tokenWindow' => [Rule::requiredIf($selfHostedActive), 'integer', 'min:1'],
            'selfHosted.secureTokenExpires' => [Rule::requiredIf($selfHostedActive), 'string'],
            'selfHosted.secureTokenQueryExpires' => [Rule::requiredIf($selfHostedActive), 'string'],
            'selfHosted.cacheMaxSize' => [Rule::requiredIf($selfHostedActive), 'string'],
            'selfHosted.cacheInactive' => [Rule::requiredIf($selfHostedActive), 'string'],

            'bunny' => [Rule::requiredIf($bunnyActive), 'array'],
            'bunny.host' => [Rule::requiredIf($bunnyActive), 'string'],
            'bunny.tokenKey' => ['nullable', 'string'],
            'bunny.tokenWindow' => [Rule::requiredIf($bunnyActive), 'integer', 'min:1'],
        ]);

        $providers = $settings->providers;

        if (isset($validated['selfHosted'])) {
            $providers['self_hosted'] = array_merge($providers['self_hosted'] ?? [], array_filter([
                'token_secret' => $validated['selfHosted']['tokenSecret'] ?? null,
                'token_name' => $validated['selfHosted']['tokenName'] ?? null,
                'token_window' => isset($validated['selfHosted']['tokenWindow']) ? (int) $validated['selfHosted']['tokenWindow'] : null,
                'secure_token_expires' => $validated['selfHosted']['secureTokenExpires'] ?? null,
                'secure_token_query_expires' => $validated['selfHosted']['secureTokenQueryExpires'] ?? null,
                'cache_max_size' => $validated['selfHosted']['cacheMaxSize'] ?? null,
                'cache_inactive' => $validated['selfHosted']['cacheInactive'] ?? null,
            ], fn ($v) => $v !== null));
        }

        if (isset($validated['bunny'])) {
            $providers['bunny'] = array_merge($providers['bunny'] ?? [], array_filter([
                'host' => $validated['bunny']['host'] ?? null,
                'token_key' => $validated['bunny']['tokenKey'] ?? null,
                'token_window' => isset($validated['bunny']['tokenWindow']) ? (int) $validated['bunny']['tokenWindow'] : null,
            ], fn ($v) => $v !== null));
        }

        $settings->provider = $validated['provider'];
        $settings->providers = $providers;
        $settings->save();

        return response()->json(['data' => CdnSettingsData::fromSettings($settings)]);
    }
}
