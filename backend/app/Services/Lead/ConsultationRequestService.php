<?php

namespace App\Services\Lead;

use App\Enums\LeadStatus;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\Lead;
use App\Notifications\NewConsultationRequest;
use App\Services\Report\IssueTaxonomy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Turns a consultation request into a lead a salesperson can act on (§55).
 *
 * The rule running through this class: **everything that can be derived is
 * derived here, not accepted from the request.** The endpoint is public
 * and unauthenticated, so any association it takes on trust is an
 * association anyone can forge — a lead filed against a competitor's
 * website, or decorated with findings from an audit the sender never saw.
 */
class ConsultationRequestService
{
    public function __construct(private readonly IssueTaxonomy $taxonomy) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): Lead
    {
        $audit = isset($input['audit_id'])
            ? Audit::with('website')->find($input['audit_id'])
            : null;

        $lead = Lead::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'phone' => $input['phone'] ?? null,
            'message' => $input['message'] ?? null,
            'source' => $input['source'],
            'status' => LeadStatus::New,

            'audit_id' => $audit?->id,

            // Taken from the audit, never from the payload. This is the
            // association the spec asks for, and the only trustworthy way
            // to make it on an unauthenticated endpoint.
            'website_id' => $audit?->website_id,

            // The URL still gets stored, because a request with no audit
            // attached (the pricing page, the footer) has nothing else to
            // say which site it is about.
            'website_url' => $input['website_url'] ?? $audit?->website?->primary_url,

            'issue_codes' => $this->issueCodesFor($audit, $input['issue_codes'] ?? null),
            'requested_services' => $this->services($input['requested_services'] ?? null),
        ]);

        $this->notifySales($lead);

        return $lead;
    }

    /**
     * The findings this request is about, filtered to ones that really
     * exist on that audit.
     *
     * A public caller can put any string in this array. Storing it as-is
     * would let someone hand the sales team a fabricated list of problems
     * attributed to a real audit — a lead that opens with findings the
     * report does not contain.
     *
     * @param  string[]|null  $requested
     * @return string[]|null
     */
    private function issueCodesFor(?Audit $audit, ?array $requested): ?array
    {
        if ($audit === null || $requested === null || $requested === []) {
            return null;
        }

        $real = AuditIssue::where('audit_id', $audit->id)
            ->whereIn('code', $requested)
            ->pluck('code')
            ->unique()
            ->values()
            ->all();

        return $real === [] ? null : $real;
    }

    /**
     * @param  string[]|null  $requested
     * @return string[]|null
     */
    private function services(?array $requested): ?array
    {
        if ($requested === null || $requested === []) {
            return null;
        }

        $known = array_keys(IssueTaxonomy::SERVICES);
        $valid = array_values(array_unique(array_intersect($requested, $known)));

        return $valid === [] ? null : $valid;
    }

    /**
     * Tell sales a request came in.
     *
     * Wrapped, and deliberately after the lead is written: a mail
     * transport that is misconfigured, rate-limited or simply down must
     * never lose the lead. Losing the notification costs a delay; losing
     * the row costs the customer.
     */
    private function notifySales(Lead $lead): void
    {
        $recipients = array_filter(array_map('trim', (array) config('leads.sales_recipients', [])));

        if ($recipients === []) {
            return;
        }

        try {
            Notification::route('mail', $recipients)->notify(new NewConsultationRequest($lead));
        } catch (\Throwable $e) {
            Log::warning('Consultation request notification failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
