<?php

declare(strict_types=1);

$options = getopt('', [
    'root::',
    'input::',
    'markdown::',
    'out::',
]);

$root = rtrim((string) ($options['root'] ?? dirname(__DIR__)), '/');
$outDir = (string) ($options['out'] ?? ($root . '/public'));
$input = (string) ($options['input'] ?? '');
$markdown = (string) ($options['markdown'] ?? '');

if ($input === '') {
    $reports = glob($root . '/benchmarks/results/*.json') ?: [];
    sort($reports, SORT_STRING);
    if ($reports === []) {
        fwrite(STDERR, "No JSON benchmark reports found.\n");
        exit(1);
    }
    $input = (string) end($reports);
}

$inputPath = realpath($input) ?: $input;
if (!is_file($inputPath)) {
    fwrite(STDERR, "Input report not found: {$input}\n");
    exit(1);
}

if ($markdown === '') {
    $guess = preg_replace('/\.json$/', '.md', $inputPath) ?: '';
    $markdown = is_string($guess) ? $guess : '';
}

$decoded = json_decode((string) file_get_contents($inputPath), true);
if (!is_array($decoded)) {
    fwrite(STDERR, "Invalid JSON report: {$inputPath}\n");
    exit(1);
}

$runAt = (string) ($decoded['run_at'] ?? ($decoded['generated_at'] ?? ''));
$results = $decoded['results'] ?? [];
if (!is_array($results)) {
    $results = [];
}

$summary = [];
foreach ($results as $row) {
    if (!is_array($row)) {
        continue;
    }

    $scenario = (string) ($row['id'] ?? ($row['scenario']['id'] ?? ''));
    $label = (string) ($row['label'] ?? ($row['scenario']['label'] ?? $scenario));
    $system = (string) ($row['system'] ?? ($row['scenario']['system'] ?? ''));
    $url = (string) ($row['url'] ?? ($row['scenario']['url'] ?? ''));
    $status = (string) ($row['status'] ?? 'unknown');
    $metrics = is_array($row['summary'] ?? null) ? $row['summary'] : [];

    $summary[] = [
        'scenario' => $scenario,
        'label' => $label,
        'system' => $system,
        'url' => $url,
        'status' => $status,
        'rps' => (float) ($metrics['rps'] ?? 0.0),
        'p95_ms' => (float) ($metrics['p95_ms'] ?? 0.0),
        'error_rate' => (float) ($metrics['error_rate'] ?? 100.0),
    ];
}

usort($summary, static function (array $a, array $b): int {
    return strcmp($a['scenario'], $b['scenario']);
});

if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Failed to create output directory: {$outDir}\n");
    exit(1);
}

$jsonOut = [
    'generated_at' => gmdate('c'),
    'source_report' => basename($inputPath),
    'run_at' => $runAt,
    'summary' => $summary,
];
file_put_contents($outDir . '/latest.json', json_encode($jsonOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

if (is_file($markdown)) {
    copy($markdown, $outDir . '/latest.md');
}
copy($inputPath, $outDir . '/latest.raw.json');

$rows = '';
foreach ($summary as $row) {
    $rows .= '<tr>'
        . '<td><strong>' . h($row['scenario']) . '</strong><br><span class="muted">' . h($row['label']) . '</span></td>'
        . '<td>' . h($row['system']) . '</td>'
        . '<td><span class="status status-' . h($row['status']) . '">' . h($row['status']) . '</span></td>'
        . '<td class="num">' . number_format((float) $row['rps'], 2, '.', ',') . '</td>'
        . '<td class="num">' . number_format((float) $row['p95_ms'], 2, '.', ',') . '</td>'
        . '<td class="num">' . number_format((float) $row['error_rate'], 2, '.', ',') . '</td>'
        . '<td><a href="' . h($row['url']) . '">' . h($row['url']) . '</a></td>'
        . '</tr>';
}

$runAtText = $runAt !== '' ? h($runAt) : 'n/a';
$sourceText = h(basename($inputPath));
$latestMd = is_file($outDir . '/latest.md') ? '<a href="latest.md">latest.md</a> · ' : '';

$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>atoll Benchmark Dashboard</title>
  <style>
    :root { color-scheme: light dark; }
    body { font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; max-width: 1000px; margin: 2rem auto; padding: 0 1rem; }
    h1 { margin: 0 0 .25rem; }
    .muted { color: #6b7280; margin: 0 0 1.5rem; }
    .meta { margin: 0 0 1rem; font-size: .95rem; }
    table { width: 100%; border-collapse: collapse; margin: 1rem 0 1.25rem; }
    th, td { border: 1px solid #d1d5db; padding: .55rem .6rem; text-align: left; vertical-align: top; }
    th { background: #f3f4f6; color: #111827; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .status { display: inline-block; padding: .1rem .45rem; border-radius: 999px; font-size: .8rem; }
    .status-ok { background: #dcfce7; color: #166534; }
    .status-error { background: #fee2e2; color: #991b1b; }
    .links { margin: .75rem 0 1.5rem; }
    .links a { margin-right: .5rem; }
  </style>
</head>
<body>
  <h1>atoll Benchmark Dashboard</h1>
  <p class="muted">Cross-CMS benchmark snapshot from GitHub Actions.</p>
  <p class="meta"><strong>Run at:</strong> {$runAtText}<br><strong>Source:</strong> {$sourceText}</p>
  <p class="links"><a href="latest.json">latest.json</a> · {$latestMd}<a href="latest.raw.json">latest.raw.json</a></p>
  <table>
    <thead>
      <tr>
        <th>Scenario</th>
        <th>System</th>
        <th>Status</th>
        <th>RPS</th>
        <th>P95 (ms)</th>
        <th>Error %</th>
        <th>URL</th>
      </tr>
    </thead>
    <tbody>
      {$rows}
    </tbody>
  </table>
</body>
</html>
HTML;

file_put_contents($outDir . '/index.html', $html);
echo "Benchmark pages written to: {$outDir}\n";

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
