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
    </style>
</head>
<body>
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
</body>
</html>
