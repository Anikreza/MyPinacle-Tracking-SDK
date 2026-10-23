<?php

namespace MyPinacle\Tracking\Platforms;

use MyPinacle\Tracking\Contracts\TrackingSettings;
use MyPinacle\Tracking\Delivery;
use MyPinacle\Tracking\Http\OutgoingRequest;
use MyPinacle\Tracking\TrackingContext;

/**
 * GA4 Measurement Protocol purchase for gateway payments, where the confirming request can be a webhook and the shopper may
 * never reach the thank-you page. GA4 deduplicates purchases by transaction_id, so the browser purchase is not counted
 * twice. Keys: GA_MEASUREMENT_ID + the GA4 API secret, from the app's database settings.
 */
final class Ga4MeasurementProtocol
{
    /**
     * @param  list<array{item_id:string,item_name:string,price:float,quantity:int}>  $items
     */
    public static function purchase(TrackingSettings $cfg, TrackingContext $ctx, string $transactionId, float $value, array $items, ?string $userId = null, array $extra = []): ?array
    {
        $mid = $cfg->str('GA_MEASUREMENT_ID');
        $secret = $cfg->ga4ApiSecret();
        if ($transactionId === '' || !$cfg->flag('TRACKING_ENABLED', true) || $mid === '' || $secret === '' || !$ctx->analytics) {
            return null;
        }
        $body = [
            'client_id' => $ctx->gaClientId ?: ('srv.' . crc32($transactionId) . '.' . time()),
            'non_personalized_ads' => !$ctx->marketing,
            'events' => [[
                'name' => 'purchase',
                'params' => array_filter([
                    'transaction_id' => $transactionId,
                    'value' => round($value, 2),
                    'currency' => $cfg->currency(),
                    'shipping' => $extra['shipping'] ?? null,
                    'tax' => $extra['tax'] ?? null,
                    'payment_type' => $extra['payment_type'] ?? null,
                    'items' => $items,
                    'engagement_time_msec' => 1,
                ], fn ($v) => $v !== null && $v !== ''),
            ]],
        ];
        if ($userId) {
            $body['user_id'] = $userId;
        }
        $url = 'https://www.google-analytics.com/mp/collect?measurement_id=' . rawurlencode($mid) . '&api_secret=' . rawurlencode($secret);

        return Delivery::send(new OutgoingRequest('ga4', 'purchase', $transactionId, $url, [], $body, ['value' => $value, 'currency' => $cfg->currency(), 'sale_order_id' => $extra['sale_order_id'] ?? null])) ? $body : null;
    }
}
