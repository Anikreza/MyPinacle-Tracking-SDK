<?php

namespace MyPinacle\Tracking\Support;

/**
 * Server-minted event ids. Every browser pixel call uses an id issued here, and the server copy (Conversions API, Events
 * API) carries the same one, so each platform deduplicates the pair. Ids are signed: a browser can hand an id back to the
 * server (beacon, retry) but cannot invent one.
 *
 * Format: {prefix}_{body}_{sig}  e.g. atc_lq2x9c7k3m4p_5f1a09c2be
 */
final class EventIds
{
    public function __construct(private readonly string $secret)
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('Tracking event id secret is empty.');
        }
    }

    /** Fresh random id. */
    public function make(string $prefix): string
    {
        return $this->sign(self::prefix($prefix), base_convert((string) time(), 10, 36) . bin2hex(random_bytes(4)));
    }

    /**
     * Deterministic id for one browser action: the same nonce in the same scope (session) always gives the same id, so an
     * AJAX retry or a double-sent beacon maps to the event already sent instead of a second one.
     */
    public function forNonce(string $prefix, string $nonce, string $scope = ''): string
    {
        $body = substr(hash_hmac('sha256', 'nonce|' . $scope . '|' . $nonce, $this->secret), 0, 16);

        return $this->sign(self::prefix($prefix), $body);
    }

    /** True when $id was minted by this app (optionally with the given prefix). */
    public function verify(?string $id, ?string $prefix = null): bool
    {
        if (!is_string($id) || !preg_match('/^([a-z0-9]{1,12})_([a-z0-9]{6,32})_([a-f0-9]{10})$/', $id, $m)) {
            return false;
        }
        if ($prefix !== null && $m[1] !== self::prefix($prefix)) {
            return false;
        }

        return hash_equals($this->signature($m[1], $m[2]), $m[3]);
    }

    private function sign(string $prefix, string $body): string
    {
        return $prefix . '_' . $body . '_' . $this->signature($prefix, $body);
    }

    private function signature(string $prefix, string $body): string
    {
        return substr(hash_hmac('sha256', $prefix . '_' . $body, $this->secret), 0, 10);
    }

    private static function prefix(string $prefix): string
    {
        $p = substr((string) preg_replace('/[^a-z0-9]/', '', strtolower($prefix)), 0, 12);

        return $p === '' ? 'ev' : $p;
    }
}
