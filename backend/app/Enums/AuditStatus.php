<?php

namespace App\Enums;

/**
 * The audit lifecycle.
 *
 * The split that matters is active vs terminal: an audit occupying one of
 * its owner's concurrent-audit slots is exactly one that is *not* in a
 * terminal state. Slot release is therefore not a separate bookkeeping
 * step that could be forgotten on some failure path — it is a consequence
 * of the status itself (see App\Services\Audit\ActiveAuditLimiter).
 */
enum AuditStatus: string
{
    /** Accepted and waiting for a worker. */
    case Queued = 'queued';

    /** A worker has it and is re-checking the URL before touching the network. */
    case Validating = 'validating';

    case Crawling = 'crawling';

    case Analyzing = 'analyzing';

    /** Scored; assembling the report and the immutable snapshot. */
    case GeneratingReport = 'generating_report';

    case Completed = 'completed';

    case Failed = 'failed';

    case Cancelled = 'cancelled';

    /**
     * Statuses that hold one of the owner's concurrent-audit slots.
     *
     * @return self[]
     */
    public static function active(): array
    {
        return [
            self::Queued,
            self::Validating,
            self::Crawling,
            self::Analyzing,
            self::GeneratingReport,
        ];
    }

    /**
     * @return string[]
     */
    public static function activeValues(): array
    {
        return array_map(fn (self $status) => $status->value, self::active());
    }

    /**
     * @return self[]
     */
    public static function terminal(): array
    {
        return [self::Completed, self::Failed, self::Cancelled];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }

    /**
     * Whether an audit in this state can still be cancelled. Terminal
     * audits cannot: there is nothing left to stop, and re-marking a
     * completed audit as cancelled would destroy a real result.
     */
    public function isCancellable(): bool
    {
        return $this->isActive();
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Validating => 'Validating',
            self::Crawling => 'Crawling',
            self::Analyzing => 'Analyzing',
            self::GeneratingReport => 'Generating report',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }
}
