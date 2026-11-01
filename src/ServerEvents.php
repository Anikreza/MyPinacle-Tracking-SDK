<?php

namespace MyPinacle\Tracking;

use MyPinacle\Tracking\Contracts\EventLedger;
use MyPinacle\Tracking\Contracts\TrackingSettings;
use MyPinacle\Tracking\Http\OutgoingRequest;
use MyPinacle\Tracking\Platforms\Ga4MeasurementProtocol;
use MyPinacle\Tracking\Platforms\MetaConversionsApi;
use MyPinacle\Tracking\Platforms\TikTokEventsApi;

/**
 * One server-side event to every configured platform with the SAME server-issued event_id the browser pixels use, so each
 * platform deduplicates its browser/server pair. Properties are TikTok-shaped (contents[content_id, content_name, quantity,
 * price]); Meta's shape is derived here.
 */
final class ServerEvents
{
    /** Events a browser may ask the server to issue + send (TikTok name = Meta standard name). */
    public const BROWSER_EVENTS = ['ViewContent', 'AddToCart', 'InitiateCheckout', 'AddPaymentInfo', 'Search', 'CompleteRegistration', 'AddToWishlist'];

    /** Short id prefix per event (readable in Events Manager and marketing_events). */
    public const PREFIX = [
        'ViewContent' => 'vc', 'AddToCart' => 'atc', 'InitiateCheckout' => 'ic', 'AddPaymentInfo' => 'api', 'Search' => 'srch',
        'CompleteRegistration' => 'reg', 'AddToWishlist' => 'wish', 'Purchase' => 'order', 'OrderPlaced' => 'placed',
    ];

    /** Order placed but not yet confirmed (confirmed-order purchase tracking): Meta custom event / TikTok standard event. */
    public const META_ORDER_PLACED = 'OrderPlaced';

    public const TIKTOK_ORDER_PLACED = 'PlaceAnOrder';

    /**
     * The event id for one browser action, always minted by the server: a valid id the server issued earlier (rendered
     * into the page, or returned to an earlier attempt) is kept; otherwise a nonce from the browser maps to one stable id
     * per session (retries reuse it); otherwise a fresh id. A browser can never choose the id.
     */
    public static function issueId(string $event, ?string $presented = null, ?string $nonce = null, string $scope = ''): string
    {
        $ids = app(Support\EventIds::class);
        $prefix = self::PREFIX[$event] ?? 'ev';
        if ($presented !== null && $ids->verify($presented, $prefix)) {
            return $presented;
        }
        $nonce = is_string($nonce) ? trim($nonce) : '';
        if ($nonce !== '' && strlen($nonce) <= 100) {
            return $ids->forNonce($prefix, $nonce, $scope);
        }

        return $ids->make($prefix);
    }

    /**
     * @param  array<string, mixed>  $user  email, phone, external_id, first_name, last_name, city, country, sale_order_id
     * @param  array<string, mixed>  $properties  contents, value, currency, query
     */
    public static function send(TrackingSettings $cfg, TrackingContext $ctx, string $event, ?string $eventId, array $user = [], array $properties = []): void
    {
        if (!$eventId) {
            return;
        }
        $properties += ['currency' => $cfg->currency()];
        TikTokEventsApi::send($cfg, $ctx, $event, $eventId, $user, $properties);

        if (in_array($event, self::BROWSER_EVENTS, true)) {
            $contents = array_values((array) ($properties['contents'] ?? []));
            MetaConversionsApi::send($cfg, $ctx, $event, self::userFields($user) + [
                'event_id' => $eventId,
                'value' => $properties['value'] ?? null,
                'currency' => $properties['currency'],
                'content_ids' => $contents ? array_map(fn ($c) => (string) ($c['content_id'] ?? ''), $contents) : null,
                'content_name' => $contents[0]['content_name'] ?? null,
                'content_type' => $properties['content_type'] ?? null, // e.g. product_group for a ViewContent of a product with variants
                'contents' => $contents ? self::metaContents($contents) : null,
                'num_items' => $contents ? array_sum(array_map(fn ($c) => (int) ($c['quantity'] ?? 1), $contents)) : null,
                'search_string' => $properties['query'] ?? null,
                'session_id' => $ctx->sessionId,
            ]);
        }
    }

    /**
     * Purchase on every platform: TikTok CompletePayment, Meta Purchase (value, currency, contents, order_id) and - for
     * orders confirmed by a payment gateway ($gateway !== null) - GA4 Measurement Protocol, since that shopper may never
     * reach the thank-you page where the browser fires GA4.
     *
     * @param  array<string, mixed>  $p  event_id, transaction_id, value, currency, shipping, contents (TikTok shape), num_items,
     *                                   optional event_time (unix / DateTimeInterface), ga_session_id, timestamp_micros
     * @param  array<string, mixed>  $user  userFields() keys + sale_order_id, utm_source/medium/campaign
     */
    public static function purchase(TrackingSettings $cfg, TrackingContext $ctx, array $p, array $user, ?string $gateway = null): void
    {
        $eventId = (string) ($p['event_id'] ?? '');
        if ($eventId === '') {
            return;
        }
        // One Purchase per order, whichever path (checkout, gateway callback, retry) reports it first.
        $tx = (string) ($p['transaction_id'] ?? '');
        if ($tx !== '' && ! app(EventLedger::class)->claim('purchase', 'tx:' . $tx)) {
            return;
        }
        $contents = array_values((array) ($p['contents'] ?? []));
        $currency = (string) ($p['currency'] ?? $cfg->currency());

        TikTokEventsApi::send($cfg, $ctx, 'CompletePayment', $eventId, $user, self::tikTokProperties($p, $contents, $currency), self::timeOption($p));
        MetaConversionsApi::send($cfg, $ctx, 'Purchase', self::metaOrderPayload($ctx, $p, $user, $contents, $currency));

        if ($gateway !== null) {
            Ga4MeasurementProtocol::purchase($cfg, $ctx, $tx, (float) $p['value'], self::ga4Items($contents), self::gaUserId($user), self::ga4Extra($p, $user, $gateway !== '' ? $gateway : null));
        }
    }

    /**
     * Purchase requests for an app outbox: built, NOT dispatched, NOT claimed in any ledger (the outbox owns idempotency and
     * retries; the same event_id / transaction_id on every retry lets each platform deduplicate too). Channels with no
     * keys / consent are absent from the result.
     *
     * @param  array<string, mixed>  $p  as purchase(); event_time = when the order was placed (or confirmed), payment_type optional
     * @param  list<'meta'|'tiktok'|'ga4'>  $channels
     * @return array<'meta'|'tiktok'|'ga4', OutgoingRequest>
     */
    public static function purchaseRequests(TrackingSettings $cfg, TrackingContext $ctx, array $p, array $user, array $channels = ['meta', 'tiktok', 'ga4']): array
    {
        $eventId = (string) ($p['event_id'] ?? '');
        $tx = (string) ($p['transaction_id'] ?? '');
        if ($eventId === '') {
            return [];
        }
        $contents = array_values((array) ($p['contents'] ?? []));
        $currency = (string) ($p['currency'] ?? $cfg->currency());
        $out = [];
        if (in_array('meta', $channels, true)) {
            $out['meta'] = MetaConversionsApi::request($cfg, $ctx, 'Purchase', self::metaOrderPayload($ctx, $p, $user, $contents, $currency));
        }
        if (in_array('tiktok', $channels, true)) {
            $out['tiktok'] = TikTokEventsApi::request($cfg, $ctx, 'CompletePayment', $eventId, $user, self::tikTokProperties($p, $contents, $currency), self::timeOption($p));
        }
        if (in_array('ga4', $channels, true) && $tx !== '') {
            $out['ga4'] = Ga4MeasurementProtocol::purchaseRequest($cfg, $ctx, $tx, (float) ($p['value'] ?? 0), self::ga4Items($contents), self::gaUserId($user), self::ga4Extra($p, $user, $p['payment_type'] ?? null));
        }

        return array_filter($out);
    }

    /**
     * GA4 refund request for an app outbox (built, not dispatched). Full refund: $value null and no contents.
     *
     * @param  array<string, mixed>  $p  transaction_id, value (null = full), contents (TikTok shape, returned lines), refund_ref,
     *                                   event_time / timestamp_micros, ga_session_id
     */
    public static function refundRequest(TrackingSettings $cfg, TrackingContext $ctx, array $p, array $user = []): ?OutgoingRequest
    {
        $contents = array_values((array) ($p['contents'] ?? []));

        return Ga4MeasurementProtocol::refundRequest($cfg, $ctx, (string) ($p['transaction_id'] ?? ''), isset($p['value']) ? (float) $p['value'] : null,
            self::ga4Items($contents), self::gaUserId($user), self::ga4Extra($p, $user, null) + ['refund_ref' => $p['refund_ref'] ?? null]);
    }

    /**
     * Order placed, not yet confirmed (confirmed-order purchase tracking): Meta custom event OrderPlaced + TikTok
     * PlaceAnOrder, with the same event id the browser pixels use. Sent once per transaction_id. Use an event id DIFFERENT
     * from the order's Purchase event id (prefix "placed"), so the later Purchase is never deduplicated against it.
     *
     * @param  array<string, mixed>  $p  event_id, transaction_id, value, currency, contents (TikTok shape), num_items, event_time
     */
    public static function orderPlaced(TrackingSettings $cfg, TrackingContext $ctx, array $p, array $user): void
    {
        $eventId = (string) ($p['event_id'] ?? '');
        if ($eventId === '') {
            return;
        }
        $tx = (string) ($p['transaction_id'] ?? '');
        if ($tx !== '' && ! app(EventLedger::class)->claim('order_placed', 'tx:' . $tx)) {
            return;
        }
        $contents = array_values((array) ($p['contents'] ?? []));
        $currency = (string) ($p['currency'] ?? $cfg->currency());

        TikTokEventsApi::send($cfg, $ctx, self::TIKTOK_ORDER_PLACED, $eventId, $user, self::tikTokProperties($p, $contents, $currency), self::timeOption($p));
        MetaConversionsApi::send($cfg, $ctx, self::META_ORDER_PLACED, self::metaOrderPayload($ctx, $p, $user, $contents, $currency));
    }

    /**
     * Browser copy of a purchase payload: same event id, transaction id, value and lines the server sent, plus the hashed
     * email / phone for Google Ads Enhanced Conversions.
     */
    public static function browserPurchase(array $p, ?string $email, ?string $phone): array
    {
        $contents = array_values((array) ($p['contents'] ?? []));

        return [
            'event_id' => (string) $p['event_id'],
            'transaction_id' => (string) $p['transaction_id'],
            'value' => round((float) $p['value'], 2),
            'currency' => (string) $p['currency'],
            'shipping' => round((float) ($p['shipping'] ?? 0), 2),
            'product_ids' => array_column($contents, 'content_id'),
            'product_names' => array_column($contents, 'content_name'),
            'product_prices' => array_column($contents, 'price'),
            'quantities' => array_column($contents, 'quantity'),
            'num_items' => (int) ($p['num_items'] ?? array_sum(array_column($contents, 'quantity'))),
            'contents' => $contents,
        ] + Support\Hashing::enhancedConversions($email, $phone);
    }

    private static function userFields(array $u): array
    {
        return array_intersect_key($u, array_flip(['email', 'phone', 'external_id', 'first_name', 'last_name', 'city', 'country']));
    }

    private static function metaOrderPayload(TrackingContext $ctx, array $p, array $user, array $contents, string $currency): array
    {
        return self::userFields($user) + [
            'event_id' => (string) $p['event_id'],
            'event_time' => $p['event_time'] ?? null,
            'value' => (float) ($p['value'] ?? 0),
            'currency' => $currency,
            'content_ids' => array_map(fn ($c) => (string) ($c['content_id'] ?? ''), $contents),
            'contents' => self::metaContents($contents),
            'num_items' => $p['num_items'] ?? array_sum(array_map(fn ($c) => (int) ($c['quantity'] ?? 1), $contents)),
            'order_id' => (string) ($p['transaction_id'] ?? ''),
            'sale_order_id' => $user['sale_order_id'] ?? null,
            'utm_source' => $user['utm_source'] ?? null,
            'utm_medium' => $user['utm_medium'] ?? null,
            'utm_campaign' => $user['utm_campaign'] ?? null,
            'session_id' => $ctx->sessionId,
        ];
    }

    private static function tikTokProperties(array $p, array $contents, string $currency): array
    {
        return ['contents' => $contents, 'value' => (float) ($p['value'] ?? 0), 'currency' => $currency];
    }

    private static function timeOption(array $p): array
    {
        return isset($p['event_time']) ? ['event_time' => $p['event_time']] : [];
    }

    private static function ga4Items(array $contents): array
    {
        return array_map(fn ($c) => [
            'item_id' => (string) ($c['content_id'] ?? ''),
            'item_name' => (string) ($c['content_name'] ?? 'Product'),
            'price' => (float) ($c['price'] ?? 0),
            'quantity' => (int) ($c['quantity'] ?? 1),
        ], $contents);
    }

    private static function ga4Extra(array $p, array $user, ?string $paymentType): array
    {
        return array_filter([
            'shipping' => $p['shipping'] ?? null,
            'payment_type' => $paymentType,
            'sale_order_id' => $user['sale_order_id'] ?? null,
            'event_time' => $p['event_time'] ?? null,
            'timestamp_micros' => $p['timestamp_micros'] ?? null,
            'ga_session_id' => $p['ga_session_id'] ?? null,
        ], fn ($v) => $v !== null);
    }

    private static function gaUserId(array $user): ?string
    {
        return !empty($user['external_id']) ? (string) $user['external_id'] : null;
    }

    /** TikTok contents -> Meta contents [id, quantity, item_price]. */
    private static function metaContents(array $contents): array
    {
        return array_map(fn ($c) => [
            'id' => (string) ($c['content_id'] ?? ''),
            'quantity' => (int) ($c['quantity'] ?? 1),
            'item_price' => round((float) ($c['price'] ?? 0), 2),
        ], $contents);
    }
}
