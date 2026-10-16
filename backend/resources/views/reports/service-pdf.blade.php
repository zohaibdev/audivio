{{-- Per-service PDF (§35). Renders the SAME ServiceReportBuilder array the
     web report uses, so the two cannot drift apart. Self-contained CSS
     only — dompdf does not fetch remote assets. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report['name'] }} Report — {{ $audit->website->domain }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; line-height: 1.5; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 14px; margin: 22px 0 8px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; }
        h3 { font-size: 12px; margin: 14px 0 4px; }
        .muted { color: #64748b; }
        .score-box { border: 1px solid #cbd5e1; padding: 12px; margin: 12px 0; }
        .score { font-size: 30px; font-weight: bold; }
        .notice { border: 1px solid #f59e0b; background: #fffbeb; padding: 8px; margin: 10px 0; }
        .na { border: 1px solid #cbd5e1; background: #f8fafc; padding: 10px; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #e2e8f0; padding: 5px 6px; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; font-size: 10px; text-transform: uppercase; }
        .issue { border: 1px solid #e2e8f0; padding: 8px; margin-bottom: 8px; page-break-inside: avoid; }
        .sev { display: inline-block; padding: 1px 6px; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        .sev-critical { background: #fee2e2; color: #991b1b; }
        .sev-high     { background: #ffedd5; color: #9a3412; }
        .sev-medium   { background: #fef9c3; color: #854d0e; }
        .sev-low      { background: #dbeafe; color: #1e40af; }
        .sev-passed   { background: #dcfce7; color: #166534; }
        .tag { display: inline-block; background: #f1f5f9; padding: 1px 6px; font-size: 9px; }
        .url { font-family: DejaVu Sans Mono, monospace; font-size: 9px; color: #475569; word-break: break-all; }
        .fix { background: #eef2ff; padding: 6px; margin-top: 6px; }
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

<h1>{{ $report['name'] }} Report</h1>
<p class="muted">
    {{ $audit->website->primary_url }}<br>
    Audit #{{ $audit->id }} · {{ $audit->completed_at?->format('j M Y, H:i') ?? 'in progress' }}
    · {{ $audit->pages_crawled }} pages analyzed
</p>

{{-- §43: an accessibility score must never be read as a compliance
     verdict, so the caveat sits above the number rather than in a
     footnote after it. Rendered whether or not the service produced a
     score, because an empty result invites the same misreading. --}}
@if (! empty($report['accessibility']))
    <div class="notice">
        <strong>{{ $report['accessibility']['disclaimer']['title'] }}</strong>
        <p style="margin:4px 0 0">{{ $report['accessibility']['disclaimer']['summary'] }}</p>
        <p style="margin:4px 0 0">{{ $report['accessibility']['disclaimer']['detail'] }}</p>
    </div>
@endif

@if (! $report['is_applicable'])
    <div class="na">
        <strong>Not applicable.</strong>
        <p style="margin:4px 0 0">{{ $report['not_applicable_reason'] }}</p>
    </div>
@else
    <div class="score-box">
        <div class="score">{{ $report['score'] }}<span class="muted" style="font-size:13px">/100</span></div>
        @if ($report['what_this_means'])
            <p style="margin:6px 0 0">{{ $report['what_this_means'] }}</p>
        @endif
        @if (! empty($report['accessibility']))
            <p class="muted" style="margin:6px 0 0">{{ $report['accessibility']['disclaimer']['score_note'] }}</p>
        @endif
    </div>

    @if ($report['coverage_note'])
        <div class="notice">
            <strong>Incomplete assessment.</strong>
            <p style="margin:4px 0 0">{{ $report['coverage_note'] }}</p>
        </div>
    @endif

    <h2>Summary</h2>
    <table>
        <tr>
            <th>Critical</th><th>High</th><th>Medium</th><th>Low</th><th>Total</th>
        </tr>
        <tr>
            <td>{{ $report['severity_summary']['critical'] ?? 0 }}</td>
            <td>{{ $report['severity_summary']['high'] ?? 0 }}</td>
            <td>{{ $report['severity_summary']['medium'] ?? 0 }}</td>
            <td>{{ $report['severity_summary']['low'] ?? 0 }}</td>
            <td>{{ $report['severity_summary']['total'] ?? 0 }}</td>
        </tr>
    </table>

    @if (! empty($report['accessibility']))
        @php($a11y = $report['accessibility'])
        <h2>Accessibility check coverage</h2>
        <p class="muted">
            {{ $a11y['coverage']['pages_scanned'] }} of {{ $a11y['coverage']['pages_crawled'] }} crawled pages were scanned ·
            {{ $a11y['summary']['passed_checks'] }} automated checks passed ·
            {{ $a11y['summary']['affected_elements'] }} failing elements across {{ $a11y['summary']['affected_pages'] }} pages
            @if ($a11y['coverage']['needs_review_count'] > 0)
                · {{ $a11y['coverage']['needs_review_count'] }} checks need manual review
            @endif
        </p>

        <table>
            <tr><th>Check area</th><th>Issues</th><th>Elements</th><th>Result</th></tr>
            @foreach ($a11y['checks'] as $check)
                <tr>
                    <td>{{ $check['label'] }}</td>
                    <td>{{ $check['issue_count'] }}</td>
                    <td>{{ $check['element_count'] }}</td>
                    {{-- Deliberately not "accessible": an automated check
                         finding nothing is not the same as the area being
                         usable. --}}
                    <td>{{ $check['passed'] ? 'No automated issues found' : 'Issues found' }}</td>
                </tr>
            @endforeach
        </table>

        @if (count($a11y['wcag_criteria']) > 0)
            <h2>WCAG success criteria with failures</h2>
            @php($criteria = array_map(
                fn (array $c) => $c['level'] ? "{$c['criterion']} (Level {$c['level']})" : $c['criterion'],
                $a11y['wcag_criteria'],
            ))
            <p class="muted">{{ implode(' · ', $criteria) }}</p>
        @endif
    @endif

    @if (! empty($report['performance']))
        @php($perf = $report['performance'])

        {{-- Mobile first: it is the primary audit priority, and the two
             are never blended (section 45). --}}
        {{-- Mobile is listed first because it is the primary priority.
             The labels carry no parentheses on purpose: Blade matches a
             directive's parentheses naively, so a literal "(" inside an
             inline array literal ends the directive early. --}}
        @foreach (['mobile' => 'Mobile', 'desktop' => 'Desktop'] as $strategyKey => $strategyLabel)
            @php($block = $perf[$strategyKey])
            <h2>{{ $strategyLabel }}@if ($strategyKey === 'mobile') — primary @endif</h2>

            @if (! $block['available'])
                <p class="muted">{{ $block['reason'] }}</p>
            @else
                <p class="muted">
                    Score: {{ $block['score'] !== null ? $block['score'].'/100' : 'not reported' }}
                    @if (! empty($block['potential_savings']['ms']))
                        · potential saving {{ round($block['potential_savings']['ms'] / 1000, 1) }}s
                    @endif
                </p>

                @if (! empty($block['field']) && ! $block['field']['available'])
                    {{-- Says why INP is absent. Dropping it silently would
                         read as "no INP problem here". --}}
                    <p class="muted">{{ $block['field']['reason'] }}</p>
                @elseif (! empty($block['field']['metrics']))
                    <table>
                        <tr><th>Real-user metric</th><th>Value</th></tr>
                        @foreach ($block['field']['metrics'] as $metricKey => $metric)
                            <tr>
                                <td>{{ strtoupper(str_replace('_ms', '', $metricKey)) }}</td>
                                <td>{{ $metric['value'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                    <p class="muted">{{ $block['field']['note'] ?? '' }}</p>
                @endif

                @if (! empty($block['opportunities']))
                    <h3>Opportunities</h3>
                    <table>
                        <tr><th>Finding</th><th>Saving</th><th>Affected resources</th></tr>
                        @foreach ($block['opportunities'] as $opportunity)
                            <tr>
                                <td>{{ $opportunity['title'] }}</td>
                                <td>
                                    @if ($opportunity['savings_ms'] !== null)
                                        {{ round($opportunity['savings_ms'] / 1000, 1) }}s
                                    @endif
                                    @if ($opportunity['savings_bytes'] !== null)
                                        {{ round($opportunity['savings_bytes'] / 1024) }} KB
                                    @endif
                                </td>
                                <td>{{ implode(', ', array_slice(array_column($opportunity['resources'], 'url'), 0, 3)) }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif

                @if (! empty($block['diagnostics']))
                    <h3>Diagnostics</h3>
                    {{-- No savings column: Lighthouse did not quantify
                         these, and a blank figure is better than one we
                         invented. --}}
                    <table>
                        <tr><th>Finding</th><th>Detail</th></tr>
                        @foreach ($block['diagnostics'] as $diagnostic)
                            <tr>
                                <td>{{ $diagnostic['title'] }}</td>
                                <td>{{ $diagnostic['display_value'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            @endif
        @endforeach

        @php($withFindings = array_filter($perf['categories'] ?? [], fn (array $c) => $c['finding_count'] > 0))
        @if (count($withFindings) > 0)
            <h2>Findings by area</h2>
            <table>
                <tr><th>Area</th><th>Findings</th><th>Potential saving</th></tr>
                @foreach ($withFindings as $category)
                    <tr>
                        <td>{{ $category['label'] }}</td>
                        <td>{{ $category['finding_count'] }}</td>
                        <td>{{ $category['savings_ms'] ? round($category['savings_ms'] / 1000, 1).'s' : '' }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        @if (count($perf['pages']) > 0)
            <h2>Page by page</h2>
            <table>
                <tr><th>URL</th><th>Mobile</th><th>Desktop</th><th>LCP</th><th>CLS</th><th>Requests</th><th>Transfer</th></tr>
                @foreach ($perf['pages'] as $row)
                    <tr>
                        <td>{{ $row['url'] }}</td>
                        {{-- A dash, never a zero: a page Lighthouse never
                             measured has no score. --}}
                        <td>{{ $row['mobile_score'] ?? '—' }}</td>
                        <td>{{ $row['desktop_score'] ?? '—' }}</td>
                        <td>{{ $row['lcp_ms'] !== null ? round($row['lcp_ms'] / 1000, 1).'s' : '—' }}</td>
                        <td>{{ $row['cls'] !== null ? number_format($row['cls'], 2) : '—' }}</td>
                        <td>{{ $row['total_requests'] ?? '—' }}</td>
                        <td>{{ $row['transfer_bytes'] !== null ? round($row['transfer_bytes'] / 1024).' KB' : '—' }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    @endif

    @if (! empty($report['seo']))
        @php($seo = $report['seo'])

        <h2>Site files</h2>
        <table>
            <tr><th>robots.txt</th><td>{{ str_replace('_', ' ', $seo['site']['robots_txt']['status']) }}</td></tr>
            <tr><th>XML sitemap</th><td>{{ str_replace('_', ' ', $seo['site']['sitemap']['status']) }}@if ($seo['site']['sitemap']['url_count'] !== null) — {{ $seo['site']['sitemap']['url_count'] }} URLs @endif</td></tr>
            <tr><th>Internal links</th><td>{{ $seo['site']['links']['internal'] }} ({{ $seo['site']['links']['broken_internal'] }} broken)</td></tr>
            <tr><th>Orphan pages</th><td>{{ $seo['site']['links']['orphan_pages'] }}</td></tr>
        </table>

        @if ($seo['redirects']['total'] > 0)
            <h2>Redirect report</h2>
            <p class="muted">
                {{ $seo['redirects']['total'] }} redirected URL(s) ·
                {{ $seo['redirects']['chains'] }} chain(s) ·
                {{ $seo['redirects']['loops'] }} loop(s) ·
                {{ $seo['redirects']['temporary'] }} temporary.
                {{ $seo['redirects']['scope_note'] }}
            </p>
            <table>
                <tr><th>Final URL</th><th>Hops</th><th>Path</th></tr>
                @foreach ($seo['redirects']['entries'] as $entry)
                    @php($path = array_map(
                        fn (array $h) => $h['url'].' ['.($h['status'] ?? '?').']',
                        $entry['hops'],
                    ))
                    <tr>
                        <td>{{ $entry['final_url'] }}</td>
                        <td>{{ $entry['hop_count'] }}</td>
                        <td>{{ implode(' -> ', $path) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        <h2>Structured data</h2>
        <p class="muted">{{ $seo['schema']['note'] }}</p>
        <table>
            <tr><th>Type</th><th>Found on</th></tr>
            @foreach ($seo['schema']['types'] as $type)
                <tr>
                    <td>{{ $type['label'] }}</td>
                    <td>{{ $type['present'] ? $type['page_count'].' page(s)' : 'Not found' }}</td>
                </tr>
            @endforeach
        </table>

        <h2>Page by page</h2>
        <table>
            <tr><th>URL</th><th>Status</th><th>Title</th><th>Desc</th><th>H1</th><th>Links in/out</th><th>Indexable</th></tr>
            @foreach ($seo['pages'] as $row)
                <tr>
                    <td>{{ $row['url'] }}</td>
                    <td>{{ $row['http_status'] }}</td>
                    <td>{{ $row['title_length'] ?? '—' }}</td>
                    <td>{{ $row['meta_description_length'] ?? '—' }}</td>
                    <td>{{ $row['h1_count'] }}</td>
                    <td>{{ $row['internal_links_in'] }} / {{ $row['internal_links_out'] }}</td>
                    <td>{{ $row['is_indexable'] ? 'Yes' : 'No' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (! empty($report['backlinks']))
        <h2>Backlinks</h2>
        @if ($report['backlinks']['status'] === 'available')
            <p class="muted">
                Source: {{ $report['backlinks']['provider'] }}, retrieved {{ $report['backlinks']['data']['fetched_at'] }}.
            </p>
            <table>
                <tr><th>Total backlinks</th><td>{{ $report['backlinks']['data']['total_backlinks'] ?? '—' }}</td></tr>
                <tr><th>Referring domains</th><td>{{ $report['backlinks']['data']['referring_domains'] ?? '—' }}</td></tr>
            </table>
        @else
            {{-- No figures at all rather than a modelled one: an invented
                 backlink count is indistinguishable from a measured one. --}}
            <p class="muted">{{ $report['backlinks']['message'] }}</p>
        @endif
    @endif

    @if (count($report['categories']) > 0)
        <h2>Category breakdown</h2>
        <table>
            <tr><th>Category</th><th>Issues</th><th>Critical</th><th>High</th></tr>
            @foreach ($report['categories'] as $category)
                <tr>
                    <td>{{ $category['name'] }}</td>
                    <td>{{ $category['issue_count'] }}</td>
                    <td>{{ $category['severity_summary']['critical'] ?? 0 }}</td>
                    <td>{{ $category['severity_summary']['high'] ?? 0 }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    {{-- Issues, grouped by category so the PDF reads the same way the web
         report does rather than as one undifferentiated list. --}}
    @foreach ($report['categories'] as $category)
        <h2>{{ $category['name'] }}</h2>
        @foreach ($category['issues'] ?? [] as $issue)
            <div class="issue">
                <span class="sev sev-{{ $issue['severity'] }}">{{ $issue['severity'] }}</span>
                @if ($issue['detected_by'] === 'ai')<span class="tag">AI-assisted</span>@endif
                @if ($issue['is_site_wide'])<span class="tag">Site-wide</span>@endif
                <h3>{{ $issue['title'] }}</h3>
                <p style="margin:0">{{ $issue['message'] }}</p>

                @if (! empty($issue['recommendation']))
                    <div class="fix"><strong>How to fix:</strong> {{ $issue['recommendation'] }}</div>
                @endif

                <p class="muted" style="margin:6px 0 2px">
                    Affects {{ $issue['affected_page_count'] }} page(s)
                </p>
                @foreach (array_slice($issue['affected_pages'] ?? [], 0, 10) as $url)
                    <div class="url">{{ $url }}</div>
                @endforeach
            </div>
        @endforeach
    @endforeach

    @if (count($report['recommendations']) > 0)
        <h2>Recommendations</h2>
        <table>
            <tr><th>Priority</th><th>Effort</th><th>Recommendation</th><th>Pages</th></tr>
            @foreach ($report['recommendations'] as $rec)
                <tr>
                    <td>{{ $rec['priority'] }}</td>
                    <td>{{ $rec['effort'] }}</td>
                    <td>
                        <strong>{{ $rec['title'] }}</strong><br>
                        {{ $rec['recommended_action'] }}
                    </td>
                    <td>{{ $rec['affected_page_count'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (count($report['pages']) > 0)
        <h2>Page-by-page findings</h2>
        <table>
            <tr><th>Page</th><th>Critical</th><th>High</th><th>Total</th></tr>
            @foreach ($report['pages'] as $page)
                <tr>
                    <td>
                        {{ $page['title'] ?? '—' }}<br>
                        <span class="url">{{ $page['url'] }}</span>
                    </td>
                    <td>{{ $page['severity_summary']['critical'] ?? 0 }}</td>
                    <td>{{ $page['severity_summary']['high'] ?? 0 }}</td>
                    <td>{{ $page['issue_count'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($report['ai']['used'])
        <h2>AI analysis</h2>
        @if ($report['ai']['available'] === false)
            <p class="muted">AI analysis was not available for this audit{{ $report['ai']['error'] ? ': '.$report['ai']['error'] : '.' }}</p>
        @elseif ($report['ai']['analysis'])
            <p>{{ $report['ai']['analysis'] }}</p>
        @endif
    @endif
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
