<?php

namespace MyPinacle\Tracking\Http;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One fully built server-side tracking request. Plain data (serialisable into a queued job); perform() does the HTTP call
 * with short timeouts and never throws.
 */
final class OutgoingRequest
{
    /**
     * @param  array<string, string>  $headers  secrets (Access-Token) live here or in the URL, never in the logged body
     * @param  array<string, mixed>  $log  first-party log fields: sale_order_id, session_id, utm_*, value, currency
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $eventName,
        public readonly string $eventId,
        public readonly string $url,
        public readonly array $headers,
        public readonly array $body,
        public readonly array $log = [],
    ) {
    }

    /** @return array{status: 'sent'|'failed', http_status?: int, response?: mixed, error?: string} */
    public function perform(): array
    {
        $cfg = (array) config('tracking-sdk.http', []);
        try {
            $res = Http::timeout((int) ($cfg['timeout'] ?? 8))
                ->connectTimeout((int) ($cfg['connect_timeout'] ?? 4))
                ->retry((int) ($cfg['retries'] ?? 2), (int) ($cfg['retry_sleep_ms'] ?? 500), null, false)
                ->withHeaders($this->headers)->asJson()->post($this->url, $this->body);
            // TikTok answers HTTP 200 with a non-zero `code` on errors.
            $ok = $res->successful() && !($this->channel === 'tiktok' && (int) ($res->json('code') ?? 0) !== 0);
            $out = ['status' => $ok ? 'sent' : 'failed', 'http_status' => $res->status(), 'response' => is_array($res->json()) ? $res->json() : mb_substr((string) $res->body(), 0, 300)];
            if (!$ok) {
                Log::warning("[Tracking] {$this->channel} {$this->eventName} failed", ['event_id' => $this->eventId, 'status' => $res->status()]);
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning("[Tracking] {$this->channel} {$this->eventName} exception", ['event_id' => $this->eventId, 'error' => $e->getMessage()]);

            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    /** Body for the log: no tokens. */
    public function redactedBody(): array
    {
        $b = $this->body;
        unset($b['access_token']);

        return $b;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $a): self
    {
        return new self((string) $a['channel'], (string) $a['eventName'], (string) $a['eventId'], (string) $a['url'], (array) ($a['headers'] ?? []), (array) ($a['body'] ?? []), (array) ($a['log'] ?? []));
    }
}
