<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Audit Report</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin-top: 24px; margin-bottom: 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
        .meta { color: #555; margin-bottom: 16px; }
        .score-overall { font-size: 32px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #e0e0e0; vertical-align: top; }
        th { background: #f2f2f2; }
        .severity-critical { color: #b30000; font-weight: bold; }
        .severity-high { color: #cc4b00; font-weight: bold; }
        .severity-medium { color: #a68b00; }
        .severity-low { color: #4a7a00; }
        .ai-block { background: #f7f7f7; padding: 8px; margin-bottom: 10px; white-space: pre-wrap; }
        .brand { border-bottom: 2px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 14px; }
        .brand-logo { max-height: 48px; }
        .brand-name { font-size: 15px; font-weight: bold; }
        .brand-contact { color: #64748b; font-size: 10px; }
        .brand-footer { border-top: 1px solid #e2e8f0; margin-top: 24px; padding-top: 8px; color: #64748b; font-size: 10px; }
    </style>
</head>
<body>
{{-- White-label header (§48). Falls back to the product's own name, so
     there is no state in which a report renders with no identity on it.
     The logo is a base64 data URI: dompdf cannot fetch a remote asset,
     and the file sits on a private disk behind a signed route it has no
     session for. --}}
<div class="brand">
    @if ($branding['logo_data_uri'])
        <img src="{{ $branding['logo_data_uri'] }}" alt="{{ $branding['brand_name'] }}" class="brand-logo">
    @else
        <div class="brand-name">{{ $branding['brand_name'] }}</div>
    @endif
    @php($contact = array_filter([
        $branding['contact_email'],
        $branding['contact_phone'],
        $branding['contact_website'],
    ]))
    @if (count($contact) > 0)
        <div class="brand-contact">{{ implode(' · ', $contact) }}</div>
    @endif
</div>

    <h1>Website Audit Report</h1>
    <div class="meta">
        {{ $content['overview']['website_url'] }}
        @if ($content['overview']['audit_date'])
            &mdash; generated {{ \Illuminate\Support\Carbon::parse($content['overview']['audit_date'])->toFormattedDateString() }}
        @endif
        &mdash; {{ $content['overview']['pages_crawled'] }} page(s) crawled
    </div>

    <div class="score-overall">{{ $content['overview']['overall_score'] ?? 'N/A' }} / 100</div>

    <h2>Category Scores</h2>
    <table>
        <thead>
            <tr><th>Category</th><th>Score</th></tr>
        </thead>
        <tbody>
            @foreach ($content['overview']['category_scores'] as $category => $score)
                <tr><td>{{ ucfirst(str_replace('_', ' ', $category)) }}</td><td>{{ $score }} / 100</td></tr>
            @endforeach
        </tbody>
    </table>

    {{-- §43: the accessibility number in the table above is an automated
         result, and this PDF is the artifact most likely to be forwarded
         as evidence. The caveat travels with it. --}}
    @if (! empty($content['disclaimers']['accessibility']))
        <p style="margin:8px 0 0;font-size:11px">
            <strong>{{ $content['disclaimers']['accessibility']['title'] }}.</strong>
            {{ $content['disclaimers']['accessibility']['summary'] }}
            {{ $content['disclaimers']['accessibility']['detail'] }}
        </p>
    @endif

    <h2>Issues ({{ count($content['issues']) }})</h2>
    <table>
        <thead>
            <tr><th>Category</th><th>Severity</th><th>Message</th><th>Page</th></tr>
        </thead>
        <tbody>
            @foreach ($content['issues'] as $issue)
                <tr>
                    <td>{{ ucfirst(str_replace('_', ' ', $issue['category'])) }}</td>
                    <td class="severity-{{ $issue['severity'] }}">{{ ucfirst($issue['severity']) }}</td>
                    <td>{{ $issue['message'] }}</td>
                    <td>{{ $issue['page_url'] ?? 'Site-wide' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if (($content['hidden_issue_count'] ?? 0) > 0)
        {{-- Says what is missing rather than quietly shipping a shorter
             report: the reader should know this is a partial view of
             their own audit, and why. --}}
        <p class="meta">
            {{ $content['hidden_issue_count'] }} further issue(s) are not shown on your current plan.
        </p>
    @endif

    <h2>Recommendations ({{ count($content['recommendations']) }})</h2>
    <table>
        <thead>
            <tr><th>Category</th><th>Priority</th><th>Recommendation</th></tr>
        </thead>
        <tbody>
            @foreach ($content['recommendations'] as $recommendation)
                <tr>
                    <td>{{ ucfirst(str_replace('_', ' ', $recommendation['category'])) }}</td>
                    <td>{{ ucfirst($recommendation['priority']) }}</td>
                    <td>{{ $recommendation['recommendation'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if (! empty($content['ai_analysis']))
        <h2>AI Analysis</h2>
        @foreach ($content['ai_analysis'] as $category => $analysis)
            <strong>{{ ucfirst(str_replace('_', ' ', $category)) }}</strong>
            <div class="ai-block">{{ $analysis }}</div>
        @endforeach
    @endif

    {{-- The restricted payload has a different shape from the full one:
         page findings are full-access content, so `pages` is absent
         rather than empty (§48). Reading it unguarded crashed the export
         the moment PDFs started honouring plan visibility. --}}
    @if (! empty($content['pages']))
    <h2>Pages Crawled</h2>
    <table>
        <thead>
            <tr><th>URL</th><th>HTTP</th><th>Title</th><th>Issues</th></tr>
        </thead>
        <tbody>
            @foreach ($content['pages'] as $page)
                <tr>
                    <td>{{ $page['url'] }}</td>
                    <td>{{ $page['http_status'] }}</td>
                    <td>{{ $page['title'] }}</td>
                    <td>{{ $page['issue_count'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

{{-- Footer text is the agency's own wording; the attribution line is
     shown only when the report is not white-labelled. --}}
@if ($branding['footer_text'])
    <div class="brand-footer">{{ $branding['footer_text'] }}</div>
@elseif (! $branding['is_white_label'])
    <div class="brand-footer">Generated by {{ $branding['brand_name'] }}.</div>
@endif

</body>
</html>
