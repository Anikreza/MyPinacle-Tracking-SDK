<?php

namespace MyPinacle\Tracking\Contracts;

use MyPinacle\Tracking\Http\OutgoingRequest;

/**
 * App glue for delivery and the first-party log: queue the request (or run it after the response) and write
 * marketing_events rows.
 */
interface TrackingTransport
{
    /** Deliver later (queue / after response). Must never throw. */
    public function dispatch(OutgoingRequest $request): void;

    /** Record an event that was deliberately not sent (no consent, ...). Must never throw. */
    public function skipped(string $channel, string $eventName, string $eventId, string $reason, array $meta = []): void;
}
