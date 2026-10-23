<?php

namespace MyPinacle\Tracking;

/**
 * The one place the Meta Graph API version is defined. Every Meta call in every MyPinacle app (Conversions API, Facebook
 * login, WhatsApp Cloud API) builds its URL here. v26.0 = latest per developers.facebook.com/docs/graph-api/changelog
 * (checked 2026-10-23; v26.0 released 2026-07-29).
 */
final class Graph
{
    public const VERSION = 'v26.0';

    /** https://graph.facebook.com/{version}/{path} */
    public static function url(string $path): string
    {
        return 'https://graph.facebook.com/' . self::VERSION . '/' . ltrim($path, '/');
    }

    /** https://www.facebook.com/{version}/{path} (OAuth dialog). */
    public static function webUrl(string $path): string
    {
        return 'https://www.facebook.com/' . self::VERSION . '/' . ltrim($path, '/');
    }
}
