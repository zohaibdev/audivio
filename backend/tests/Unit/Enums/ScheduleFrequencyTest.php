<?php

namespace Tests\Unit\Enums;

use App\Enums\ScheduleFrequency;
use Carbon\Carbon;
use Tests\TestCase;

class ScheduleFrequencyTest extends TestCase
{
    public function test_daily_advances_by_one_day(): void
    {
        $from = Carbon::parse('2026-01-01 00:00:00');

        $this->assertTrue(ScheduleFrequency::Daily->nextRunFrom($from)->equalTo('2026-01-02 00:00:00'));
    }

    public function test_weekly_advances_by_one_week(): void
    {
        $from = Carbon::parse('2026-01-01 00:00:00');

        $this->assertTrue(ScheduleFrequency::Weekly->nextRunFrom($from)->equalTo('2026-01-08 00:00:00'));
    }

    public function test_monthly_advances_by_one_month(): void
    {
        $from = Carbon::parse('2026-01-01 00:00:00');

        $this->assertTrue(ScheduleFrequency::Monthly->nextRunFrom($from)->equalTo('2026-02-01 00:00:00'));
    }

    public function test_does_not_mutate_the_given_instant(): void
    {
        $from = Carbon::parse('2026-01-01 00:00:00');

        ScheduleFrequency::Daily->nextRunFrom($from);

        $this->assertTrue($from->equalTo('2026-01-01 00:00:00'));
    }
}
