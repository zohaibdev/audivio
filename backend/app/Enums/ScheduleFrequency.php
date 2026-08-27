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
}
