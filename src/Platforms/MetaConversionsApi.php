<?php

namespace MyPinacle\Tracking\Platforms;

use MyPinacle\Tracking\Contracts\TrackingSettings;
use MyPinacle\Tracking\Delivery;
use MyPinacle\Tracking\Graph;
use MyPinacle\Tracking\Http\OutgoingRequest;
use MyPinacle\Tracking\Support\Country;
use MyPinacle\Tracking\Support\Hashing;
use MyPinacle\Tracking\TrackingContext;

/**
 * Meta Conversions API. event_id equals the browser fbq eventID so Meta deduplicates the pair. Keys: FACEBOOK_PIXEL_ID,
 * FACEBOOK_CAPI_TOKEN, FACEBOOK_CAPI_TEST_CODE (optional), TRACKING_ENABLED - all from the app's database settings.
 */
final class MetaConversionsApi
{
    /**
     * @param  array<string, mixed>  $payload  event_id, email, phone, first_name, last_name, city, country, external_id, value,
     *                                         currency, content_ids, content_name, contents, order_id, num_items, search_string,
     *                                         + log fields sale_order_id, session_id, utm_*
     * @return array<string, mixed>|null the request body when dispatched
     */
    public static function send(TrackingSettings $cfg, TrackingContext $ctx, string $eventName, array $payload): ?array
    {
        $eventId = (string) ($payload['event_id'] ?? '');
        if ($eventId === '' || !$cfg->flag('TRACKING_ENABLED', true)) {
            return null;
        }
        $pixelId = $cfg->str('FACEBOOK_PIXEL_ID');
        $token = $cfg->str('FACEBOOK_CAPI_TOKEN');
        if ($pixelId === '' || $token === '') {
            return null;
        }
        $log = self::logFields($cfg, $payload, $ctx);
        // Cookie banner on and no marketing consent: server-side events are tracking too - send nothing.
        if (!$ctx->marketing) {
            Delivery::skipped('meta', $eventName, $eventId, 'no marketing consent', $log);

            return null;
        }
        $body = ['data' => [self::buildEvent($cfg, $ctx, $eventName, $eventId, $payload)]];
        if (($test = $cfg->str('FACEBOOK_CAPI_TEST_CODE')) !== '') {
            $body['test_event_code'] = $test;
        }
        $url = Graph::url(rawurlencode($pixelId) . '/events') . '?access_token=' . rawurlencode($token);

        return Delivery::send(new OutgoingRequest('meta', $eventName, $eventId, $url, [], $body, $log)) ? $body : null;
    }

    /** @return array<string, mixed> one entry of the Conversions API `data` array */
    public static function buildEvent(TrackingSettings $cfg, TrackingContext $ctx, string $eventName, string $eventId, array $payload): array
    {
        $user = [];
        $hash = function (string $key, ?string $normalised) use (&$user) {
            if ($normalised !== null && $normalised !== '') {
                $user[$key] = [Hashing::sha($normalised)];
            }
        };
        $hash('em', Hashing::email($payload['email'] ?? null));
        $hash('ph', Hashing::metaPhone($payload['phone'] ?? null));
        $hash('fn', Hashing::name($payload['first_name'] ?? null));
        $hash('ln', Hashing::name($payload['last_name'] ?? null));
        $hash('ct', Hashing::city($payload['city'] ?? null));
        $hash('country', Country::resolve($payload['country'] ?? null, $cfg->defaultCountry()));
        $hash('external_id', Hashing::externalId($payload['external_id'] ?? null));
        // Not hashed (Meta spec): IP, user agent, browser id and click id.
        $user += array_filter([
            'client_ip_address' => $ctx->ip,
            'client_user_agent' => $ctx->userAgent,
            'fbp' => $ctx->fbp,
            'fbc' => $ctx->fbc,
        ], fn ($v) => $v !== null && $v !== '');

        $custom = array_filter([
            'currency' => $payload['currency'] ?? $cfg->currency(),
            'value' => isset($payload['value']) ? round((float) $payload['value'], 2) : null,
            'content_ids' => $payload['content_ids'] ?? null,
            'content_type' => $payload['content_type'] ?? 'product',
            'content_name' => $payload['content_name'] ?? null,
            'order_id' => isset($payload['order_id']) ? (string) $payload['order_id'] : null,
            'contents' => $payload['contents'] ?? null,
            'num_items' => $payload['num_items'] ?? null,
            'search_string' => $payload['search_string'] ?? null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        return [
            'event_name' => $eventName,
            'event_time' => time(),
            'event_id' => $eventId,
            'event_source_url' => $ctx->pageUrl ?: url('/'),
            'action_source' => 'website',
            'user_data' => $user,
            'custom_data' => $custom,
        ];
    }

    private static function logFields(TrackingSettings $cfg, array $p, TrackingContext $ctx): array
    {
        return [
            'sale_order_id' => $p['sale_order_id'] ?? null,
            'session_id' => $p['session_id'] ?? $ctx->sessionId,
            'utm_source' => $p['utm_source'] ?? null,
            'utm_medium' => $p['utm_medium'] ?? null,
            'utm_campaign' => $p['utm_campaign'] ?? null,
            'value' => $p['value'] ?? null,
            'currency' => $p['currency'] ?? $cfg->currency(),
        ];
    }
}
