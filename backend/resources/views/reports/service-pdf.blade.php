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
    </style>
</head>
<body>

<h1>{{ $report['name'] }} Report</h1>
<p class="muted">
    {{ $audit->website->url }}<br>
    Audit #{{ $audit->id }} · {{ $audit->completed_at?->format('j M Y, H:i') ?? 'in progress' }}
    · {{ $audit->pages_crawled }} pages analyzed
</p>

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

</body>
</html>
