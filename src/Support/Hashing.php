<?php

namespace MyPinacle\Tracking\Support;

/**
 * Normalisation + SHA-256 for every platform's user matching (Meta customer information parameters, TikTok, Google Ads
 * Enhanced Conversions). Phone numbers without a country code are Bangladeshi (01XXXXXXXXX), as in both storefronts.
 */
final class Hashing
{
    public static function sha(string $value): string
    {
        return hash('sha256', $value);
    }

    /** Lowercased, trimmed email; null for empty and the @nomail.com placeholders guest checkout creates. */
    public static function email(?string $email): ?string
    {
        $e = strtolower(trim((string) $email));

        return ($e === '' || str_ends_with($e, '@nomail.com')) ? null : $e;
    }

    /** 01XXXXXXXXX -> 8801XXXXXXXXX (Meta: country code + number, digits only). */
    public static function metaPhone(?string $phone): ?string
    {
        $d = (string) preg_replace('/\D+/', '', (string) $phone);
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (str_starts_with($d, '0')) {
            $d = '880' . substr($d, 1);
        } elseif (str_starts_with($d, '1') && strlen($d) === 10) {
            $d = '880' . $d;
        }

        return $d === '' ? null : $d;
    }

    /** 01XXXXXXXXX -> +8801XXXXXXXXX (TikTok, Google Ads Enhanced Conversions). */
    public static function e164(?string $phone): ?string
    {
        $c = (string) preg_replace('/[^\d+]/', '', (string) $phone);
        if ($c === '' || $c === '+') {
            return null;
        }
        if (str_starts_with($c, '00')) {
            $c = '+' . substr($c, 2);
        }
        if (str_starts_with($c, '0')) {
            $c = '+880' . substr($c, 1);
        } elseif (!str_starts_with($c, '+')) {
            $c = (strlen($c) === 10 && str_starts_with($c, '1') ? '+880' : '+') . $c;
        }

        return $c;
    }

    /** Meta fn / ln: lowercase, no surrounding spaces (letters of any script kept). */
    public static function name(?string $name): ?string
    {
        $n = mb_strtolower(trim((string) $name));

        return $n === '' ? null : $n;
    }

    /** Meta ct: lowercase, letters only, no spaces or punctuation (Bangla letters kept, so a Bangla city is not hashed as ''). */
    public static function city(?string $city): ?string
    {
        $c = (string) preg_replace('/[^\p{L}\p{M}]+/u', '', mb_strtolower(trim((string) $city)));

        return $c === '' ? null : $c;
    }

    public static function externalId(mixed $id): ?string
    {
        $v = strtolower(trim((string) $id));

        return $v === '' ? null : $v;
    }

    /** Hashed email / phone for the browser's Google Ads Enhanced Conversions (never raw values). */
    public static function enhancedConversions(?string $email, ?string $phone): array
    {
        $e = self::email($email);
        $p = self::e164($phone);

        return [
            'em_sha256' => $e !== null ? self::sha($e) : null,
            'ph_sha256' => $p !== null ? self::sha($p) : null,
        ];
    }
}
