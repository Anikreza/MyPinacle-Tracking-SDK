<?php

namespace MyPinacle\Tracking\Tests\Fakes;

use MyPinacle\Tracking\Settings;

final class FakeSettings extends Settings
{
    public function __construct(public array $values = [], public ?string $country = 'Bangladesh', public string $gaSecret = '')
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $v = $this->values[$key] ?? null;

        return ($v === null || $v === '') ? $default : $v;
    }

    public function currency(): string
    {
        return 'BDT';
    }

    public function defaultCountry(): ?string
    {
        return $this->country;
    }

    public function ga4ApiSecret(): string
    {
        return $this->gaSecret;
    }
}
