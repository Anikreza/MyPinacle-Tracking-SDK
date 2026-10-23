<?php

namespace MyPinacle\Tracking\Platforms;

use MyPinacle\Tracking\Contracts\TrackingSettings;
use MyPinacle\Tracking\Delivery;
use MyPinacle\Tracking\Http\OutgoingRequest;
use MyPinacle\Tracking\Support\Hashing;
use MyPinacle\Tracking\TrackingContext;

/**
 * TikTok Events API 2.0 - POST /open_api/v1.3/event/track/. event_id equals the browser ttq.track event_id. Keys:
 * TIKTOK_PIXEL_ID, TIKTOK_ACCESS_TOKEN, TIKTOK_TEST_EVENT_CODE, TRACKING_TIKTOK_CAPI - from the app's database settings.
 */
final class TikTokEventsApi
{
    public const ENDPOINT = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';

    /**
     * @param  array<string, mixed>  $user  email / phone / external_id (raw - hashed here), sale_order_id
     * @param  array<string, mixed>  $properties  contents[content_id, content_name, quantity, price], value, currency, query
     */
    public static function send(TrackingSettings $cfg, TrackingContext $ctx, string $event, string $eventId, array $user = [], array $properties = []): ?array
    {
        $token = $cfg->str('TIKTOK_ACCESS_TOKEN');
        $pixelId = $cfg->str('TIKTOK_PIXEL_ID');
        if ($eventId === '' || !$cfg->flag('TRACKING_TIKTOK_CAPI', true) || $token === '' || $pixelId === '' || !$ctx->marketing) {
            return null;
        }
        $email = Hashing::email($user['email'] ?? null);
        $phone = Hashing::e164($user['phone'] ?? null);
        $ext = Hashing::externalId($user['external_id'] ?? null);
        $body = [
            'event_source' => 'web',
            'event_source_id' => $pixelId,
            'data' => [[
                'event' => $event,
                'event_id' => $eventId,
                'event_time' => time(),
                // Events API 2.0 field names are `ip` and `user_agent`.
                'user' => array_filter([
                    'ip' => $ctx->ip,
                    'user_agent' => $ctx->userAgent,
                    'ttp' => $ctx->ttp,
                    'ttclid' => $ctx->ttclid,
                    'email' => $email !== null ? Hashing::sha($email) : null,
                    'phone_number' => $phone !== null ? Hashing::sha($phone) : null,
                    'external_id' => $ext !== null ? Hashing::sha($ext) : null,
                ]),
                'properties' => $properties + ['content_type' => 'product'],
                'page' => array_filter(['url' => $ctx->pageUrl ?: url('/'), 'referrer' => $ctx->referrer]),
            ]],
        ];
        if (($test = $cfg->str('TIKTOK_TEST_EVENT_CODE')) !== '') {
            $body['test_event_code'] = $test;
        }

        return Delivery::send(new OutgoingRequest('tiktok', $event, $eventId, self::ENDPOINT, ['Access-Token' => $token], $body, [
            'value' => $properties['value'] ?? null,
            'currency' => $properties['currency'] ?? $cfg->currency(),
            'sale_order_id' => $user['sale_order_id'] ?? null,
            'session_id' => $ctx->sessionId,
        ])) ? $body : null;
    }
}
