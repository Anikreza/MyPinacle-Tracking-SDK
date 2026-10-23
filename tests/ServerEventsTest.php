<?php

namespace MyPinacle\Tracking\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use MyPinacle\Tracking\Platforms\MetaConversionsApi;
use MyPinacle\Tracking\ServerEvents;

final class ServerEventsTest extends TestCase
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

    private function metaRequests(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com'))->values()->all();
    }

    public function test_meta_payload_hashing_country_and_unhashed_browser_fields(): void
    {
        $id = ServerEvents::issueId('ViewContent');
        ServerEvents::send($this->settings(['FACEBOOK_CAPI_TEST_CODE' => 'TEST123']), $this->ctx(), 'ViewContent', $id, [
            'email' => ' Buyer@Example.com ', 'phone' => '01711000000', 'external_id' => 42,
            'first_name' => 'Rahim', 'last_name' => 'Uddin', 'city' => 'Dhaka',
        ], ['contents' => [['content_id' => '7', 'content_name' => 'Honey', 'quantity' => 1, 'price' => 450]], 'value' => 450]);

        [$req] = $this->metaRequests()[0];
        $this->assertStringStartsWith('https://graph.facebook.com/v26.0/111222333/events?access_token=EAAtoken', $req->url());
        $ev = $req->data()['data'][0];
        $this->assertSame('TEST123', $req->data()['test_event_code']);
        $this->assertSame($id, $ev['event_id']);
        $this->assertSame('ViewContent', $ev['event_name']);
        $this->assertSame('website', $ev['action_source']);
        $this->assertSame('https://shop.test/products/x', $ev['event_source_url']);
        $u = $ev['user_data'];
        $this->assertSame([hash('sha256', 'buyer@example.com')], $u['em']);
        $this->assertSame([hash('sha256', '8801711000000')], $u['ph']);
        $this->assertSame([hash('sha256', 'rahim')], $u['fn']);
        $this->assertSame([hash('sha256', 'uddin')], $u['ln']);
        $this->assertSame([hash('sha256', 'dhaka')], $u['ct']);
        $this->assertSame([hash('sha256', 'bd')], $u['country']);
        $this->assertSame([hash('sha256', '42')], $u['external_id']);
        $this->assertSame('203.0.113.9', $u['client_ip_address']);
        $this->assertSame('UA/1.0', $u['client_user_agent']);
        $this->assertSame('fb.1.1700000000000.123', $u['fbp']);
        $this->assertSame('fb.1.1700000000000.click', $u['fbc']);
        $this->assertSame(['7'], $ev['custom_data']['content_ids']);
        $this->assertSame([['id' => '7', 'quantity' => 1, 'item_price' => 450.0]], $ev['custom_data']['contents']);
    }

    public function test_content_type_defaults_to_product_and_can_be_product_group(): void
    {
        $cfg = $this->settings();
        ServerEvents::send($cfg, $this->ctx(), 'ViewContent', ServerEvents::issueId('ViewContent'), [], ['contents' => [['content_id' => '7', 'content_name' => 'Shirt']]]);
        ServerEvents::send($cfg, $this->ctx(), 'ViewContent', ServerEvents::issueId('ViewContent'), [], ['contents' => [['content_id' => '8', 'content_name' => 'Tee']], 'content_type' => 'product_group']);
        $meta = $this->metaRequests();
        $this->assertSame('product', $meta[0][0]->data()['data'][0]['custom_data']['content_type']);
        $this->assertSame('product_group', $meta[1][0]->data()['data'][0]['custom_data']['content_type']);
    }
    public function test_country_prefers_address_then_store_default_and_is_omitted_when_unknown(): void
    {
        $cfg = $this->settings();
        $this->assertSame([hash('sha256', 'in')], MetaConversionsApi::buildEvent($cfg, $this->ctx(), 'AddToCart', 'x', ['country' => 'India'])['user_data']['country']);
        $this->assertSame([hash('sha256', 'bd')], MetaConversionsApi::buildEvent($cfg, $this->ctx(), 'AddToCart', 'x', ['country' => 'Narnia'])['user_data']['country']);
        $none = MetaConversionsApi::buildEvent($this->settings([], null), $this->ctx(), 'AddToCart', 'x', []);
        $this->assertArrayNotHasKey('country', $none['user_data']);
        $this->assertArrayNotHasKey('em', $none['user_data']);
    }

    public function test_purchase_carries_value_currency_contents_order_id_and_shares_the_id(): void
    {
        $p = ['event_id' => 'order_SO-1001_1700000000', 'transaction_id' => 'SO-1001', 'value' => 1250.5, 'currency' => 'BDT', 'shipping' => 60,
            'contents' => [['content_id' => '7', 'content_name' => 'Honey', 'quantity' => 2, 'price' => 595.25]]];
        ServerEvents::purchase($this->settings(['GA_MEASUREMENT_ID' => 'G-TEST'], 'BD', 'ga-secret'), $this->ctx(), $p, ['email' => 'a@b.com', 'country' => 'Bangladesh', 'sale_order_id' => 9], 'bkash');

        $meta = $this->metaRequests()[0][0]->data()['data'][0];
        $this->assertSame('Purchase', $meta['event_name']);
        $this->assertSame('order_SO-1001_1700000000', $meta['event_id']);
        $this->assertSame(['currency' => 'BDT', 'value' => 1250.5, 'content_ids' => ['7'], 'content_type' => 'product', 'order_id' => 'SO-1001',
            'contents' => [['id' => '7', 'quantity' => 2, 'item_price' => 595.25]], 'num_items' => 2], $meta['custom_data']);

        $tt = Http::recorded(fn (Request $r) => str_contains($r->url(), 'tiktok'))->values()->all()[0][0];
        $this->assertSame('CompletePayment', $tt->data()['data'][0]['event']);
        $this->assertSame('order_SO-1001_1700000000', $tt->data()['data'][0]['event_id']);
        $this->assertSame('tttoken', $tt->header('Access-Token')[0]);

        $ga = Http::recorded(fn (Request $r) => str_contains($r->url(), 'google-analytics'))->values()->all();
        $this->assertCount(1, $ga);
        $this->assertSame('SO-1001', $ga[0][0]->data()['events'][0]['params']['transaction_id']);
    }

    public function test_an_order_is_reported_once_even_under_two_event_ids(): void
    {
        $p = ['transaction_id' => 'R-77', 'value' => 50, 'currency' => 'BDT', 'contents' => []];
        ServerEvents::purchase($this->settings(), $this->ctx(), $p + ['event_id' => 'order_R-77_1700000000'], []);
        ServerEvents::purchase($this->settings(), $this->ctx(), $p + ['event_id' => 'order_R-77_1700000099'], []);
        $this->assertCount(1, $this->metaRequests());
    }

    public function test_checkout_purchase_sends_no_ga4_mp(): void
    {
        ServerEvents::purchase($this->settings(['GA_MEASUREMENT_ID' => 'G-TEST'], 'BD', 'ga-secret'), $this->ctx(), ['event_id' => 'order_A_1', 'transaction_id' => 'A', 'value' => 10, 'currency' => 'BDT', 'contents' => []], []);
        $this->assertCount(0, Http::recorded(fn (Request $r) => str_contains($r->url(), 'google-analytics')));
    }

    public function test_same_event_id_is_sent_once_per_channel(): void
    {
        $id = ServerEvents::issueId('AddToCart', null, 'click-1', 'sess1');
        foreach ([1, 2, 3] as $_) {
            ServerEvents::send($this->settings(), $this->ctx(), 'AddToCart', ServerEvents::issueId('AddToCart', null, 'click-1', 'sess1'), [], ['value' => 1]);
        }
        $this->assertCount(1, $this->metaRequests());
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'tiktok')));
        $this->assertSame($id, $this->metaRequests()[0][0]->data()['data'][0]['event_id']);

        ServerEvents::send($this->settings(), $this->ctx(), 'AddToCart', ServerEvents::issueId('AddToCart', null, 'click-2', 'sess1'), [], ['value' => 1]);
        $this->assertCount(2, $this->metaRequests());
    }

    public function test_ledger_falls_back_to_cache_without_the_table(): void
    {
        Schema::drop('tracking_event_claims');
        foreach ([1, 2] as $_) {
            ServerEvents::send($this->settings(), $this->ctx(), 'Search', 'srch_abcdef_0123456789', [], ['query' => 'honey']);
        }
        $this->assertCount(1, $this->metaRequests());
    }

    public function test_issue_id_never_accepts_a_browser_chosen_id(): void
    {
        $this->assertNotSame('vc_browser_0000000000', ServerEvents::issueId('ViewContent', 'vc_browser_0000000000'));
        $server = ServerEvents::issueId('ViewContent');
        $this->assertSame($server, ServerEvents::issueId('ViewContent', $server));
        $this->assertNotSame($server, ServerEvents::issueId('AddToCart', $server), 'prefix must match the event');
    }

    public function test_no_consent_or_disabled_sends_nothing(): void
    {
        ServerEvents::send($this->settings(), $this->ctx(['marketing' => false]), 'ViewContent', ServerEvents::issueId('ViewContent'));
        ServerEvents::send($this->settings(['TRACKING_ENABLED' => '0']), $this->ctx(), 'ViewContent', ServerEvents::issueId('ViewContent'));
        ServerEvents::send($this->settings(), $this->ctx(), 'ViewContent', null);
        Http::assertNothingSent();
        $this->assertSame('no marketing consent', $this->transport->skipped[0]['reason']);
    }

    public function test_http_failure_is_silent(): void
    {
        Http::fake(['*' => fn () => throw new \RuntimeException('network down')]);
        ServerEvents::send($this->settings(), $this->ctx(), 'ViewContent', ServerEvents::issueId('ViewContent'));
        $this->assertSame('failed', $this->transport->sent[0]['status']);
    }
}
