<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Sanctum::actingAs(new User));

it('exposes codec attributes in camelCase', function () {
    $codecs = collect($this->getJson('/api/templates-config')->json('data.codecs'));

    $aac = $codecs->firstWhere('codec', 'aac');

    expect($aac)->toHaveKey('availableFor')
        ->and($aac)->not->toHaveKey('available_for')
        ->and($aac['availableFor'])->toContain('libx264');
});

it('exposes parameter attributes in camelCase but keeps parameter names snake_case', function () {
    $parameters = $this->getJson('/api/templates-config')->json('data.parameters');

    expect($parameters)->toHaveKey('video_profile');

    expect($parameters['preset'])->toHaveKey('inputType')
        ->and($parameters['preset'])->not->toHaveKey('input_type')
        ->and($parameters['preset']['inputType'])->toBe('select')
        ->and($parameters['preset']['availableFor'])->toBe(['libx264', 'libx265']);
});
