<?php

namespace MyPinacle\Tracking;

use Illuminate\Support\Facades\Log;
use MyPinacle\Tracking\Contracts\EventLedger;
use MyPinacle\Tracking\Contracts\TrackingTransport;
use MyPinacle\Tracking\Http\OutgoingRequest;

/** Hands built requests to the app transport, at most once per (channel, event id). */
final class Delivery
{
    /** True when dispatched; false when this event was already sent on this channel (retry, re-render, second app). */
    public static function send(OutgoingRequest $request): bool
    {
        try {
            if (!app(EventLedger::class)->claim($request->channel, $request->eventId)) {
                Log::debug("[Tracking] {$request->channel} {$request->eventName} already sent", ['event_id' => $request->eventId]);

                return false;
            }
            app(TrackingTransport::class)->dispatch($request);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[Tracking] dispatch failed', ['channel' => $request->channel, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public static function skipped(string $channel, string $eventName, string $eventId, string $reason, array $meta = []): void
    {
        try {
            app(TrackingTransport::class)->skipped($channel, $eventName, $eventId, $reason, $meta);
        } catch (\Throwable $e) {
        }
    }
}
