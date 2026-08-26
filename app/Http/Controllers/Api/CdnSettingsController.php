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
            'selfHosted.tokenName' => [Rule::requiredIf($selfHostedActive), 'string', 'regex:/^[a-z0-9_]+$/'],
            'selfHosted.tokenWindow' => [Rule::requiredIf($selfHostedActive), 'integer', 'min:1'],
            // Both become nginx directive values through envsubst on the edge, so anything but
            // an nginx time (`100d`, `1h`, `3600`) would break the config — or extend it.
            'selfHosted.secureTokenExpires' => [Rule::requiredIf($selfHostedActive), 'string', 'regex:/^\d+[smhd]?$/'],
            'selfHosted.secureTokenQueryExpires' => [Rule::requiredIf($selfHostedActive), 'string', 'regex:/^\d+[smhd]?$/'],

            'bunny' => [Rule::requiredIf($bunnyActive), 'array'],
            'bunny.host' => [Rule::requiredIf($bunnyActive), 'string'],
            'bunny.tokenKey' => ['nullable', 'string'],
            'bunny.tokenWindow' => [Rule::requiredIf($bunnyActive), 'integer', 'min:1'],
            'bunny.apiKey' => ['nullable', 'string'],
            'bunny.pullZoneId' => ['nullable', 'string'],
        ]);

        $providers = $settings->providers;

        if (isset($validated['selfHosted'])) {
            $providers['self_hosted'] = array_merge($providers['self_hosted'] ?? [], array_filter([
                'token_secret' => $validated['selfHosted']['tokenSecret'] ?? null,
                'token_name' => $validated['selfHosted']['tokenName'] ?? null,
                'token_window' => isset($validated['selfHosted']['tokenWindow']) ? (int) $validated['selfHosted']['tokenWindow'] : null,
                'secure_token_expires' => $validated['selfHosted']['secureTokenExpires'] ?? null,
                'secure_token_query_expires' => $validated['selfHosted']['secureTokenQueryExpires'] ?? null,
            ], fn ($v) => $v !== null));
        }

        if (isset($validated['bunny'])) {
            $providers['bunny'] = array_merge($providers['bunny'] ?? [], array_filter([
                'host' => $validated['bunny']['host'] ?? null,
                'token_key' => $validated['bunny']['tokenKey'] ?? null,
                'token_window' => isset($validated['bunny']['tokenWindow']) ? (int) $validated['bunny']['tokenWindow'] : null,
                'api_key' => $validated['bunny']['apiKey'] ?? null,
                'pull_zone_id' => $validated['bunny']['pullZoneId'] ?? null,
            ], fn ($v) => $v !== null));
        }

        $settings->provider = $validated['provider'];
        $settings->providers = $providers;
        $settings->save();

        return response()->json(['data' => CdnSettingsData::fromSettings($settings)]);
    }
}
