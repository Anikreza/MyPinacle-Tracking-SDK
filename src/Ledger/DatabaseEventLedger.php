<?php

namespace MyPinacle\Tracking\Ledger;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use MyPinacle\Tracking\Contracts\EventLedger;

/**
 * Send-once claims in the shared database: a unique (channel, event_id) row per sent event, so the first request wins even
 * when two PHP workers or both apps race. Falls back to the cache ledger while the table does not exist yet.
 */
final class DatabaseEventLedger implements EventLedger
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly string $table,
        private readonly int $ttlDays,
        private readonly EventLedger $fallback,
    ) {
    }

    public function claim(string $channel, string $eventId): bool
    {
        try {
            $won = $this->db->table($this->table)->insertOrIgnore([
                'channel' => mb_substr($channel, 0, 20),
                'event_id' => mb_substr($eventId, 0, 120),
                'created_at' => now(),
            ]) === 1;
            if ($won && random_int(1, 500) === 1) {
                $this->prune();
            }

            return $won;
        } catch (\Throwable $e) {
            Log::debug('[Tracking] ledger table unavailable, using cache', ['error' => $e->getMessage()]);

            return $this->fallback->claim($channel, $eventId);
        }
    }

    public function prune(): int
    {
        return $this->db->table($this->table)->where('created_at', '<', now()->subDays($this->ttlDays))->delete();
    }
}
