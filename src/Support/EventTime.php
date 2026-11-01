<?php

namespace MyPinacle\Tracking\Support;

/**
 * Event times for server events sent later than the action (outbox, retries). Accepts unix seconds, a DateTimeInterface
 * or a parseable date string; anything else (or null) means "now".
 */
final class EventTime
{
    /** Unix seconds (Meta CAPI / TikTok event_time). */
    public static function unix(mixed $at): int
    {
        if ($at instanceof \DateTimeInterface) {
            return $at->getTimestamp();
        }
        if (is_int($at) || (is_string($at) && ctype_digit($at))) {
            $t = (int) $at;
            // Milliseconds / microseconds given by mistake.
            while ($t > 99999999999) {
                $t = intdiv($t, 1000);
            }

            return $t > 0 ? $t : time();
        }
        if (is_float($at) && $at > 0) {
            return (int) $at;
        }
        if (is_string($at) && trim($at) !== '' && ($t = strtotime($at)) !== false) {
            return $t;
        }

        return time();
    }

    /** Microseconds (GA4 Measurement Protocol timestamp_micros); null when nothing was given (GA4 then uses receipt time). */
    public static function micros(mixed $at): ?int
    {
        if ($at === null || $at === '') {
            return null;
        }
        if ($at instanceof \DateTimeInterface) {
            return (int) $at->format('U') * 1000000 + (int) $at->format('u');
        }

        return self::unix($at) * 1000000;
    }
}
