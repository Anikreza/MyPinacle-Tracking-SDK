<?php

namespace MyPinacle\Tracking\Platforms;

use MyPinacle\Tracking\Contracts\TrackingSettings;
use MyPinacle\Tracking\Delivery;
use MyPinacle\Tracking\Http\OutgoingRequest;
use MyPinacle\Tracking\Support\EventTime;
use MyPinacle\Tracking\TrackingContext;

/**
 * GA4 Measurement Protocol purchase / refund. Used for gateway payments (the confirming request can be a webhook) and for
 * confirmed-order purchases sent from an app outbox. GA4 deduplicates purchases by transaction_id. Keys:
 * GA_MEASUREMENT_ID + the GA4 API secret, from the app's database settings.
 *
 * $extra keys: shipping, tax, payment_type, sale_order_id, timestamp_micros (or event_time: unix / DateTimeInterface; GA4
 * accepts up to 72h back), ga_session_id (GA4 session id from the `_ga_<id>` cookie, attributes the hit to the session).
 */
final class Ga4MeasurementProtocol
{
    /**
     * @param  list<array{item_id:string,item_name:string,price:float,quantity:int}>  $items
     */
    public static function purchase(TrackingSettings $cfg, TrackingContext $ctx, string $transactionId, float $value, array $items, ?string $userId = null, array $extra = []): ?array
    {
        $request = self::purchaseRequest($cfg, $ctx, $transactionId, $value, $items, $userId, $extra);

        return $request && Delivery::send($request) ? $request->body : null;
    }

    /** Built purchase request, NOT dispatched and NOT claimed in the send-once ledger (for an app outbox). */
    public static function purchaseRequest(TrackingSettings $cfg, TrackingContext $ctx, string $transactionId, float $value, array $items, ?string $userId = null, array $extra = []): ?OutgoingRequest
    {
        return self::build($cfg, $ctx, 'purchase', $transactionId, $transactionId, $value, $items, $userId, $extra);
    }

    /**
     * GA4 refund. Full refund: $value null and no items. Partial (a sale return): the returned value and/or items, plus
     * $extra['refund_ref'] (e.g. the return Ref) so each partial refund is sent once. Ledger key: "refund:{tx}[:{ref}]".
     *
     * @param  list<array{item_id:string,item_name?:string,price?:float,quantity:int}>  $items
     */
    public static function refund(TrackingSettings $cfg, TrackingContext $ctx, string $transactionId, ?float $value = null, array $items = [], ?string $userId = null, array $extra = []): ?array
    {
        $request = self::refundRequest($cfg, $ctx, $transactionId, $value, $items, $userId, $extra);

        return $request && Delivery::send($request) ? $request->body : null;
    }

    /** Built refund request, NOT dispatched and NOT claimed in the send-once ledger (for an app outbox). */
    public static function refundRequest(TrackingSettings $cfg, TrackingContext $ctx, string $transactionId, ?float $value = null, array $items = [], ?string $userId = null, array $extra = []): ?OutgoingRequest
    {
        $ref = isset($extra['refund_ref']) && (string) $extra['refund_ref'] !== '' ? ':' . $extra['refund_ref'] : '';

        return self::build($cfg, $ctx, 'refund', $transactionId, 'refund:' . $transactionId . $ref, $value, $items, $userId, $extra);
    }

    private static function build(TrackingSettings $cfg, TrackingContext $ctx, string $event, string $transactionId, string $eventId, ?float $value, array $items, ?string $userId, array $extra): ?OutgoingRequest
    {
        $mid = $cfg->str('GA_MEASUREMENT_ID');
        $secret = $cfg->ga4ApiSecret();
        if ($transactionId === '' || !$cfg->flag('TRACKING_ENABLED', true) || $mid === '' || $secret === '' || !$ctx->analytics) {
            return null;
        }
        $sessionId = isset($extra['ga_session_id']) && preg_match('/^\d{1,20}$/', (string) $extra['ga_session_id']) ? (string) $extra['ga_session_id'] : null;
        $body = [
            'client_id' => $ctx->gaClientId ?: ('srv.' . crc32($transactionId) . '.' . time()),
            'non_personalized_ads' => !$ctx->marketing,
            'events' => [[
                'name' => $event,
                'params' => array_filter([
                    'transaction_id' => $transactionId,
                    'value' => $value !== null ? round($value, 2) : null,
                    'currency' => $cfg->currency(),
                    'shipping' => $extra['shipping'] ?? null,
                    'tax' => $extra['tax'] ?? null,
                    'payment_type' => $extra['payment_type'] ?? null,
                    'items' => ($event === 'purchase' || $items) ? $items : null,
                    'session_id' => $sessionId,
                    'engagement_time_msec' => 1,
                ], fn ($v) => $v !== null && $v !== ''),
            ]],
        ];
        $micros = isset($extra['timestamp_micros']) ? (int) $extra['timestamp_micros'] : EventTime::micros($extra['event_time'] ?? null);
        if ($micros) {
            $body['timestamp_micros'] = $micros;
        }
        if ($userId) {
            $body['user_id'] = $userId;
        }
        $url = 'https://www.google-analytics.com/mp/collect?measurement_id=' . rawurlencode($mid) . '&api_secret=' . rawurlencode($secret);

        return new OutgoingRequest('ga4', $event, $eventId, $url, [], $body, ['value' => $value, 'currency' => $cfg->currency(), 'sale_order_id' => $extra['sale_order_id'] ?? null]);
    }
}
