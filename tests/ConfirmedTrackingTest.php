<?php

namespace MyPinacle\Tracking\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MyPinacle\Tracking\Http\OutgoingRequest;
use MyPinacle\Tracking\Platforms\Ga4MeasurementProtocol;
use MyPinacle\Tracking\Platforms\MetaConversionsApi;
use MyPinacle\Tracking\Platforms\TikTokEventsApi;
use MyPinacle\Tracking\ServerEvents;
use MyPinacle\Tracking\Support\EventTime;

/** v1.2.0: event_time, GA4 timestamp_micros + session_id, refund, outbox request builders, orderPlaced. */
final class ConfirmedTrackingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1]),
            'business-api.tiktok.com/*' => Http::response(['code' => 0]),
            'www.google-analytics.com/*' => Http::response('', 204),
        ]);
    }

    private function order(array $over = []): array
    {
        return $over + [
            'event_id' => 'order_SO-1_1700000000', 'transaction_id' => 'SO-1', 'value' => 900, 'currency' => 'BDT', 'shipping' => 60,
            'contents' => [['content_id' => '7', 'content_name' => 'Honey', 'quantity' => 2, 'price' => 420]],
        ];
    }

    private function recorded(string $host): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), $host))->values()->all();
    }

    public function test_event_time_helper(): void
    {
        $this->assertSame(1700000000, EventTime::unix(1700000000));
        $this->assertSame(1700000000, EventTime::unix(1700000000123));
        $this->assertSame(1700000000, EventTime::unix(new \DateTimeImmutable('@1700000000')));
        $this->assertSame(1700000000, EventTime::unix('2023-11-14T22:13:20Z'));
        $this->assertEqualsWithDelta(time(), EventTime::unix(null), 2);
        $this->assertNull(EventTime::micros(null));
        $this->assertSame(1700000000000000, EventTime::micros(1700000000));
    }

    public function test_meta_and_tiktok_use_given_event_time_and_default_to_now(): void
    {
        $t = time() - 3600;
        $meta = MetaConversionsApi::buildEvent($this->settings(), $this->ctx(), 'Purchase', 'x', ['event_time' => $t]);
        $this->assertSame($t, $meta['event_time']);
        $this->assertEqualsWithDelta(time(), MetaConversionsApi::buildEvent($this->settings(), $this->ctx(), 'Purchase', 'x', [])['event_time'], 2);

        $tt = TikTokEventsApi::request($this->settings(), $this->ctx(), 'CompletePayment', 'e1', [], [], ['event_time' => $t]);
        $this->assertSame($t, $tt->body['data'][0]['event_time']);
    }

    public function test_request_builders_do_not_dispatch_or_claim(): void
    {
        $reqs = ServerEvents::purchaseRequests($this->settings(['GA_MEASUREMENT_ID' => 'G-TEST'], 'Bangladesh', 'sec'), $this->ctx(),
            $this->order(['event_time' => time() - 600, 'ga_session_id' => '1700000123']), ['email' => 'a@b.com', 'external_id' => 5, 'sale_order_id' => 1]);

        $this->assertSame(['meta', 'tiktok', 'ga4'], array_keys($reqs));
        $this->assertContainsOnlyInstancesOf(OutgoingRequest::class, $reqs);
        Http::assertNothingSent();
        $this->assertSame([], $this->transport->sent);
        $this->assertDatabaseCount('tracking_event_claims', 0);

        $this->assertSame('Purchase', $reqs['meta']->body['data'][0]['event_name']);
        $this->assertSame('SO-1', $reqs['meta']->body['data'][0]['custom_data']['order_id']);
        $this->assertSame('CompletePayment', $reqs['tiktok']->body['data'][0]['event']);
        $ga = $reqs['ga4']->body;
        $this->assertSame('purchase', $ga['events'][0]['name']);
        $this->assertSame('1700000123', $ga['events'][0]['params']['session_id']);
        $this->assertSame((time() - 600) * 1000000, $ga['timestamp_micros']);

        // Rebuilding (a retry) gives the same ids, and a request survives a queue round trip.
        $again = ServerEvents::purchaseRequests($this->settings(), $this->ctx(), $this->order(), []);
        $this->assertSame($reqs['meta']->eventId, $again['meta']->eventId);
        $this->assertEquals($reqs['ga4'], OutgoingRequest::fromArray($reqs['ga4']->toArray()));
    }

    public function test_builders_respect_consent_keys_and_channel_filter(): void
    {
        $this->assertSame([], ServerEvents::purchaseRequests($this->settings(), $this->ctx(['marketing' => false, 'analytics' => false]), $this->order(), []));
        $this->assertSame(['ga4'], array_keys(ServerEvents::purchaseRequests($this->settings(['GA_MEASUREMENT_ID' => 'G-TEST'], 'Bangladesh', 'sec'), $this->ctx(['marketing' => false]), $this->order(), [])));
        $this->assertSame(['tiktok'], array_keys(ServerEvents::purchaseRequests($this->settings(), $this->ctx(), $this->order(), [], ['tiktok'])));
        $this->assertSame([], $this->transport->skipped);
    }

    public function test_ga4_refund_full_and_partial(): void
    {
        $cfg = $this->settings(['GA_MEASUREMENT_ID' => 'G-TEST'], 'Bangladesh', 'sec');
        $full = Ga4MeasurementProtocol::refundRequest($cfg, $this->ctx(), 'SO-1');
        $this->assertSame('refund', $full->body['events'][0]['name']);
        $this->assertSame('refund:SO-1', $full->eventId);
        $this->assertArrayNotHasKey('value', $full->body['events'][0]['params']);
        $this->assertArrayNotHasKey('items', $full->body['events'][0]['params']);

        $part = ServerEvents::refundRequest($cfg, $this->ctx(), ['transaction_id' => 'SO-1', 'value' => 420, 'refund_ref' => 'RT-9',
            'contents' => [['content_id' => '7', 'content_name' => 'Honey', 'quantity' => 1, 'price' => 420]]]);
        $this->assertSame('refund:SO-1:RT-9', $part->eventId);
        $this->assertSame(420.0, $part->body['events'][0]['params']['value']);
        $this->assertSame('7', $part->body['events'][0]['params']['items'][0]['item_id']);

        Ga4MeasurementProtocol::refund($cfg, $this->ctx(), 'SO-1');
        Ga4MeasurementProtocol::refund($cfg, $this->ctx(), 'SO-1');
        $this->assertCount(1, $this->recorded('google-analytics.com'));
    }

    public function test_order_placed_sends_meta_custom_and_tiktok_place_an_order_once(): void
    {
        $p = $this->order(['event_id' => 'placed_SO-1_x']);
        ServerEvents::orderPlaced($this->settings(['GA_MEASUREMENT_ID' => 'G-TEST'], 'Bangladesh', 'sec'), $this->ctx(), $p, ['phone' => '01711000000']);
        ServerEvents::orderPlaced($this->settings(), $this->ctx(), $p, []);

        $meta = $this->recorded('graph.facebook.com');
        $tt = $this->recorded('business-api.tiktok.com');
        $this->assertCount(1, $meta);
        $this->assertCount(1, $tt);
        $this->assertSame('OrderPlaced', $meta[0][0]->data()['data'][0]['event_name']);
        $this->assertSame('placed_SO-1_x', $meta[0][0]->data()['data'][0]['event_id']);
        $this->assertSame('PlaceAnOrder', $tt[0][0]->data()['data'][0]['event']);
        $this->assertSame([], $this->recorded('google-analytics.com'));

        // The later confirmed Purchase is not blocked by the OrderPlaced send.
        ServerEvents::purchase($this->settings(), $this->ctx(), $this->order(), []);
        $this->assertCount(2, $this->recorded('graph.facebook.com'));
    }

    public function test_legacy_purchase_unchanged_without_new_keys(): void
    {
        ServerEvents::purchase($this->settings(['GA_MEASUREMENT_ID' => 'G-TEST'], 'Bangladesh', 'sec'), $this->ctx(), $this->order(), [], 'bkash');
        [$ga] = $this->recorded('google-analytics.com')[0];
        $this->assertArrayNotHasKey('timestamp_micros', $ga->data());
        $this->assertArrayNotHasKey('session_id', $ga->data()['events'][0]['params']);
        $this->assertSame('bkash', $ga->data()['events'][0]['params']['payment_type']);
        $this->assertEqualsWithDelta(time(), $this->recorded('graph.facebook.com')[0][0]->data()['data'][0]['event_time'], 2);
    }
}
