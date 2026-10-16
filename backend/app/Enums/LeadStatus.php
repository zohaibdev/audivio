<?php

namespace App\Enums;

/**
 * Where a consultation request sits in the sales pipeline (§55).
 *
 * Every case names a *stage the lead is in*, which is why `converted`
 * became `won`: it was the one case named after a metric someone wanted
 * to count rather than a place on the board, and it read oddly beside
 * `lost`.
 */
enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Proposal = 'proposal';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Qualified => 'Qualified',
            self::Proposal => 'Proposal',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    /**
     * Pipeline order, for boards and funnel counts. `Lost` sits at the
     * end because it is an exit, not a step.
     *
     * @return self[]
     */
    public static function pipeline(): array
    {
        return [self::New, self::Contacted, self::Qualified, self::Proposal, self::Won, self::Lost];
    }

    /** No further work is expected on a lead in a closed stage. */
    public function isClosed(): bool
    {
        return $this === self::Won || $this === self::Lost;
    }
}
