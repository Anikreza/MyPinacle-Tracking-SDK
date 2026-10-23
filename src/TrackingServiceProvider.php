<?php

namespace MyPinacle\Tracking;

use Illuminate\Support\ServiceProvider;
use MyPinacle\Tracking\Contracts\EventLedger;
use MyPinacle\Tracking\Ledger\CacheEventLedger;
use MyPinacle\Tracking\Ledger\DatabaseEventLedger;
use MyPinacle\Tracking\Support\EventIds;

/**
 * Auto-discovered. Binds the event id minting and the send-once ledger. Each app binds its own TrackingTransport (job +
 * marketing_events) in its AppServiceProvider.
 */
class TrackingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/tracking-sdk.php', 'tracking-sdk');

        $this->app->singleton(EventIds::class, function ($app) {
            $secret = (string) ($app['config']->get('tracking-sdk.event_id_secret') ?: $app['config']->get('app.key'));

            return new EventIds($secret);
        });

        $this->app->singleton(EventLedger::class, function ($app) {
            $ttl = (int) $app['config']->get('tracking-sdk.ledger_ttl_days', 7);
            $cache = new CacheEventLedger($app['cache']->store(), $ttl);

            return new DatabaseEventLedger($app['db']->connection(), (string) $app['config']->get('tracking-sdk.ledger_table', 'tracking_event_claims'), $ttl, $cache);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/tracking-sdk.php' => config_path('tracking-sdk.php')], 'tracking-sdk-config');
        }
    }
}
