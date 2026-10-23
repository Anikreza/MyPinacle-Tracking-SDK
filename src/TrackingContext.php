<?php

namespace MyPinacle\Tracking;

use Illuminate\Http\Request;

/**
 * The shopper's browser details and consent for one server-side event. Serialisable, so a gateway webhook (no shopper, no
 * cookies) can use the context captured when the order was placed.
 */
final class TrackingContext
{
    public function __construct(
        public readonly bool $analytics,
        public readonly bool $marketing,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $fbp = null,
        public readonly ?string $fbc = null,
        public readonly ?string $ttp = null,
        public readonly ?string $ttclid = null,
        public readonly ?string $gaClientId = null,
        public readonly ?string $pageUrl = null,
        public readonly ?string $referrer = null,
        public readonly ?string $sessionId = null,
    ) {
    }

    /**
     * Same-origin storefront (Ecom): first-party cookies are on the request. Click ids fall back to the URL, then to the
     * first-party attribution cookie (mp_attr JSON: {first:{fbclid,ttclid}, last:{...}}).
     */
    public static function fromCookies(Request $request, bool $analytics, bool $marketing, ?string $sessionId = null): self
    {
        $attr = json_decode((string) $request->cookie('mp_attr'), true);
        $click = function (string $k) use ($request, $attr): ?string {
            $v = $request->query($k);
            if (!$v && is_array($attr)) {
                $v = $attr['last'][$k] ?? $attr['first'][$k] ?? $attr[$k] ?? null;
            }

            return is_string($v) && $v !== '' ? mb_substr($v, 0, 500) : null;
        };
        $fbc = self::str($request->cookie('_fbc')) ?? (($id = $click('fbclid')) ? self::fbcFromClickId($id) : null);

        return new self(
            analytics: $analytics,
            marketing: $marketing,
            ip: $request->ip(),
            userAgent: self::str($request->userAgent()),
            fbp: self::str($request->cookie('_fbp')),
            fbc: $fbc,
            ttp: self::str($request->cookie('_ttp')),
            ttclid: $click('ttclid'),
            gaClientId: self::gaClientId(self::str($request->cookie('_ga'))),
            pageUrl: self::str($request->headers->get('referer')),
            referrer: null,
            sessionId: $sessionId,
        );
    }

    /** Validation rules for the headless storefront's `tracking` body field. */
    public static function bodyRules(string $prefix = 'tracking'): array
    {
        $r = [$prefix => 'nullable|array'];
        foreach (['fbp', 'fbc', 'ttp', 'ttclid', 'fbclid', 'ga', 'page_url', 'referrer', 'session_id'] as $k) {
            $r["$prefix.$k"] = 'nullable|string|max:1000';
        }
        $r["$prefix.consent"] = 'nullable|array';
        $r["$prefix.consent.analytics"] = 'nullable|boolean';
        $r["$prefix.consent.marketing"] = 'nullable|boolean';

        return $r;
    }

    /**
     * Headless storefront (Epetzo -> Inventory API, cross-origin): its first-party cookies and consent arrive in the
     * `tracking` body field; IP and user agent are the shopper's own because the browser calls the API directly.
     *
     * @param  array<string, mixed>|null  $t
     */
    public static function fromBody(Request $request, ?array $t, bool $consentRequired): self
    {
        $t = is_array($t) ? $t : [];
        $s = fn (string $k, int $max = 500) => isset($t[$k]) && is_string($t[$k]) && trim($t[$k]) !== '' ? mb_substr(trim($t[$k]), 0, $max) : null;
        $consent = is_array($t['consent'] ?? null) ? $t['consent'] : [];
        $fbc = $s('fbc') ?? (($click = $s('fbclid')) ? self::fbcFromClickId($click) : null);

        return new self(
            analytics: !$consentRequired || ($consent['analytics'] ?? false) === true,
            marketing: !$consentRequired || ($consent['marketing'] ?? false) === true,
            ip: $request->ip(),
            userAgent: self::str($request->userAgent()),
            fbp: $s('fbp'),
            fbc: $fbc,
            ttp: $s('ttp'),
            ttclid: $s('ttclid'),
            gaClientId: self::gaClientId($s('ga')),
            pageUrl: $s('page_url', 1000) ?? self::str($request->headers->get('referer')),
            referrer: $s('referrer', 1000),
            sessionId: $s('session_id', 100),
        );
    }

    /** No shopper and nothing captured (webhook): only allowed to send while no consent is required. */
    public static function none(bool $consentRequired): self
    {
        return new self(!$consentRequired, !$consentRequired);
    }

    /** "GA1.1.123456789.1700000000" -> "123456789.1700000000" */
    public static function gaClientId(?string $ga): ?string
    {
        if (!$ga) {
            return null;
        }
        $p = explode('.', $ga);

        return count($p) >= 4 ? $p[count($p) - 2] . '.' . $p[count($p) - 1] : null;
    }

    public static function fbcFromClickId(string $fbclid): string
    {
        return 'fb.1.' . (int) (microtime(true) * 1000) . '.' . $fbclid;
    }

    public function with(array $overrides): self
    {
        return self::fromArray($overrides + $this->toArray());
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (bool) ($a['analytics'] ?? false),
            (bool) ($a['marketing'] ?? false),
            $a['ip'] ?? null,
            $a['userAgent'] ?? null,
            $a['fbp'] ?? null,
            $a['fbc'] ?? null,
            $a['ttp'] ?? null,
            $a['ttclid'] ?? null,
            $a['gaClientId'] ?? null,
            $a['pageUrl'] ?? null,
            $a['referrer'] ?? null,
            $a['sessionId'] ?? null,
        );
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, 500) : null;
    }
}
