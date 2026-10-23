<?php

namespace MyPinacle\Tracking;

use MyPinacle\Tracking\Contracts\TrackingSettings;

/**
 * Base for app settings adapters: implement get(), currency(), defaultCountry() and ga4ApiSecret(); flag() and str() follow
 * the rules both apps already use (ecom_tracking_flag / ecom_cfg).
 */
abstract class Settings implements TrackingSettings
{
    public function flag(string $key, bool $default = true): bool
    {
        if ($key !== 'TRACKING_ENABLED' && !$this->flag('TRACKING_ENABLED', true)) {
            return false;
        }
        $v = $this->get($key, $default);
        if (is_bool($v)) {
            return $v;
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN) || (is_numeric($v) && (int) $v === 1);
    }

    public function str(string $key): string
    {
        $v = $this->get($key);

        return is_scalar($v) ? trim((string) $v) : '';
    }
}
