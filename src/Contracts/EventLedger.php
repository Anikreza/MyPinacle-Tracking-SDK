<?php

namespace MyPinacle\Tracking\Contracts;

/** Send-once guard: the first claim of (channel, event id) wins; every later claim returns false. */
interface EventLedger
{
    public function claim(string $channel, string $eventId): bool;
}
