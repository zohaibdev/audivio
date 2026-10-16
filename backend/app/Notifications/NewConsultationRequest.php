<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Services\Report\IssueTaxonomy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells sales that someone asked to talk (§55).
 *
 * Queued, so a slow or unreachable mail transport cannot hold up the
 * visitor's request — they see "we'll be in touch" as soon as the lead is
 * written, which is the part that must not fail.
 *
 * The body carries what a salesperson needs before the first call:
 * which site, which audit, what they saw, and what they asked for.
 */
class NewConsultationRequest extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Lead $lead) {}

    /**
     * @return string[]
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lead = $this->lead->loadMissing(['website', 'audit']);

        $mail = (new MailMessage)
            ->subject("Consultation request from {$lead->name}")
            ->line("**{$lead->name}** ({$lead->email}) asked to talk.");

        if ($lead->phone) {
            $mail->line("Phone: {$lead->phone}");
        }

        $site = $lead->website?->primary_url ?? $lead->website_url;

        if ($site) {
            $mail->line("Website: {$site}");
        }

        if ($lead->audit_id) {
            $mail->line("Audit: #{$lead->audit_id}");
        }

        if ($lead->requested_services) {
            $taxonomy = app(IssueTaxonomy::class);
            $names = array_map(fn (string $key) => $taxonomy->serviceName($key), $lead->requested_services);
            $mail->line('Wants help with: '.implode(', ', $names));
        }

        if ($lead->issue_codes) {
            // Counted rather than listed in full: a long list buries the
            // rest of the message, and the admin screen has the detail.
            $count = count($lead->issue_codes);
            $mail->line("Referencing {$count} finding(s) from the report.");
        }

        if ($lead->message) {
            $mail->line('---')->line($lead->message);
        }

        return $mail->action('Open in admin', url("/admin/leads?lead={$lead->id}"));
    }
}
