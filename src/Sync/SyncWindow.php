<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Sync;

/** Half-open UTC day window; all-day dates stay in the calendar-date domain. */
final readonly class SyncWindow
{
    public function __construct(public \DateTimeImmutable $from, public \DateTimeImmutable $to)
    {
        if ($from >= $to) {
            throw new \InvalidArgumentException('Invalid synchronization window.');
        }
    }

    public static function at(\DateTimeImmutable $now, int $pastMonths, int $futureMonths): self
    {
        if ($pastMonths < 0 || $pastMonths > 120 || $futureMonths < 1 || $futureMonths > 120) {
            throw new \InvalidArgumentException('Unsupported synchronization month range.');
        }
        $day = $now->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        // Clamp the day to the target month instead of PHP's end-of-month overflow.
        $shift = static function (int $months) use ($day): \DateTimeImmutable {
            $month = $day->modify('first day of this month')->modify(sprintf('%+d months', $months));

            return $month->setDate((int) $month->format('Y'), (int) $month->format('m'), min((int) $day->format('d'), (int) $month->format('t')));
        };

        return new self($shift(-$pastMonths), $shift($futureMonths));
    }

    /** Null means overlap; otherwise the local cleanup category (never source absence). */
    public function outside(array $fields): ?string
    {
        if ($fields['allDay']) {
            if ($fields['endDate'] < $this->from->format('Y-m-d')) {
                return 'retention';
            }

            return $fields['startDate'] >= $this->to->format('Y-m-d') ? 'window' : null;
        }
        $start = new \DateTimeImmutable($fields['sourceStart']);
        $end = new \DateTimeImmutable($fields['sourceEnd']);
        if ($end < $this->from || ($end == $this->from && $start < $end)) {
            return 'retention';
        }

        return $start >= $this->to ? 'window' : null;
    }
}
