<?php

namespace MyPinacle\Tracking\Ledger;

use Illuminate\Contracts\Cache\Repository;
use MyPinacle\Tracking\Contracts\EventLedger;

/** Send-once claims in the cache (Cache::add is atomic on redis / database / memcached; best effort on the file driver). */
final class CacheEventLedger implements EventLedger
{
    public function __construct(private readonly Repository $cache, private readonly int $ttlDays)
    {
    }

    public function claim(string $channel, string $eventId): bool
    {
        try {
            return $this->cache->add('trk-claim:' . $channel . ':' . sha1($eventId), 1, now()->addDays($this->ttlDays));
        } catch (\Throwable $e) {
            return true; // no ledger available: sending beats silently dropping the event
        }
    }
}
