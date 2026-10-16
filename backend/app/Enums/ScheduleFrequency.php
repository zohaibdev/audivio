<?php

namespace App\Enums;

use Carbon\Carbon;

enum ScheduleFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function nextRunFrom(Carbon $from): Carbon
    {
        return match ($this) {
            self::Daily => $from->copy()->addDay(),
            self::Weekly => $from->copy()->addWeek(),
            self::Monthly => $from->copy()->addMonth(),
        };
    }

    /**
     * Nominal days between runs, used to compare frequencies against a
     * plan's ceiling (§40). Comparing the *interval* rather than ordering
     * the cases means adding a new frequency slots into the ordering
     * automatically instead of needing a separate rank table kept in sync.
     */
    public function intervalDays(): int
    {
        return match ($this) {
            self::Daily => 1,
            self::Weekly => 7,
            self::Monthly => 30,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
        };
    }
}
