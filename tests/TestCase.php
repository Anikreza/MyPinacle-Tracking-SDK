<?php

namespace MyPinacle\Tracking\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MyPinacle\Tracking\Contracts\TrackingTransport;
use MyPinacle\Tracking\Tests\Fakes\FakeSettings;
use MyPinacle\Tracking\Tests\Fakes\InlineTransport;
use MyPinacle\Tracking\TrackingContext;
use MyPinacle\Tracking\TrackingServiceProvider;
use Orchestra\Testbench\TestCase as Base;

abstract class TestCase extends Base
{
    protected InlineTransport $transport;

    protected function getPackageProviders($app): array
    {
        return [TrackingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('tracking-sdk.http.retries', 1);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('tracking_event_claims', function (Blueprint $t) {
            $t->id();
            $t->string('channel', 20);
            $t->string('event_id', 120);
            $t->timestamp('created_at')->nullable();
            $t->unique(['channel', 'event_id']);
        });
        $this->transport = new InlineTransport();
        $this->app->instance(TrackingTransport::class, $this->transport);
    }

    protected function settings(array $over = [], ?string $country = 'Bangladesh', string $gaSecret = ''): FakeSettings
    {
        return new FakeSettings($over + [
            'TRACKING_ENABLED' => '1',
            'FACEBOOK_PIXEL_ID' => '111222333',
            'FACEBOOK_CAPI_TOKEN' => 'EAAtoken',
            'TIKTOK_PIXEL_ID' => 'TTPIX',
            'TIKTOK_ACCESS_TOKEN' => 'tttoken',
        ], $country, $gaSecret);
    }

    protected function ctx(array $over = []): TrackingContext
    {
        return TrackingContext::fromArray($over + [
            'analytics' => true, 'marketing' => true, 'ip' => '203.0.113.9', 'userAgent' => 'UA/1.0',
            'fbp' => 'fb.1.1700000000000.123', 'fbc' => 'fb.1.1700000000000.click', 'ttp' => 'ttp1',
            'gaClientId' => '123.456', 'pageUrl' => 'https://shop.test/products/x', 'sessionId' => 'sess1',
        ]);
    }
}
