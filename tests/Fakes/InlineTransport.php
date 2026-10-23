<?php

namespace MyPinacle\Tracking\Tests\Fakes;

use MyPinacle\Tracking\Contracts\TrackingTransport;
use MyPinacle\Tracking\Http\OutgoingRequest;

/** Sends at once (what a sync queue does after the response) and remembers what happened. */
final class InlineTransport implements TrackingTransport
{
    public array $sent = [];

    public array $skipped = [];

    public function dispatch(OutgoingRequest $request): void
    {
        $this->sent[] = $request->perform() + ['request' => $request];
    }

    public function skipped(string $channel, string $eventName, string $eventId, string $reason, array $meta = []): void
    {
        $this->skipped[] = compact('channel', 'eventName', 'eventId', 'reason');
    }
}
