<?php

namespace MyPinacle\Tracking\Contracts;

/**
 * Tracker ids, secrets and switches of one storefront, read by the app from its database (E-com Config / E-commerce
 * settings). The package never reads keys from anywhere else.
 */
interface TrackingSettings
{
    /** Raw setting by its E-com Config key (FACEBOOK_PIXEL_ID, FACEBOOK_CAPI_TOKEN, TRACKING_ENABLED, ...). */
    public function get(string $key, mixed $default = null): mixed;

    /** Boolean switch; TRACKING_ENABLED=false turns every other switch off. */
    public function flag(string $key, bool $default = true): bool;

    /** Trimmed string setting ('' when empty). */
    public function str(string $key): string;

    /** ISO 4217 storefront currency, e.g. BDT. */
    public function currency(): string;

    /** Store default country as saved in settings (any form: "BD", "Bangladesh"); null when not set. */
    public function defaultCountry(): ?string;

    /** GA4 Measurement Protocol API secret ('' when not set). */
    public function ga4ApiSecret(): string;
}
