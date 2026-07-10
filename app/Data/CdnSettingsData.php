<?php

namespace App\Data;

use App\Enums\CdnDriver;
use App\Settings\CdnSettings;
use Spatie\LaravelData\Data;

class CdnSettingsData extends Data
{
    public function __construct(
        public CdnDriver $provider,
        public SelfHostedConfigData $selfHosted,
        public BunnyConfigData $bunny,
    ) {}

    public static function fromSettings(CdnSettings $settings): self
    {
        return new self(
            provider: CdnDriver::from($settings->provider),
            selfHosted: SelfHostedConfigData::from($settings->providers['self_hosted'] ?? []),
            bunny: BunnyConfigData::from($settings->providers['bunny'] ?? []),
        );
    }
}
