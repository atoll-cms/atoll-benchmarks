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

$runAt = (string) ($decoded['generated_at'] ?? '');
$configuredRounds = (int) ($decoded['rounds'] ?? 1);
$maxErrorRate = (float) ($decoded['max_error_rate'] ?? 5.0);
$rawResults = $decoded['results'] ?? [];
if (!is_array($rawResults)) {
    $rawResults = [];
}

$summary = [];
$systems = [];
$pageTypes = [];

foreach ($rawResults as $row) {
    if (!is_array($row)) {
        continue;
    }

    $scenario = (string) ($row['id'] ?? ($row['scenario']['id'] ?? ''));
    $label = (string) ($row['label'] ?? ($row['scenario']['label'] ?? $scenario));
    $system = (string) ($row['system'] ?? ($row['scenario']['system'] ?? ''));
    $url = (string) ($row['url'] ?? ($row['scenario']['url'] ?? ''));
    $status = (string) ($row['status'] ?? 'unknown');
    $metrics = is_array($row['summary'] ?? null) ? $row['summary'] : [];
    $rounds = is_array($row['rounds'] ?? null) ? $row['rounds'] : [];

    $roundP95 = [];
    $roundRps = [];
    foreach ($rounds as $round) {
        if (!is_array($round)) {
            continue;
        }
        $roundP95[] = (float) ($round['p95_ms'] ?? 0.0);
        $roundRps[] = (float) ($round['rps'] ?? 0.0);
    }

    $pageType = detectPageType($scenario, $url);
    $systems[$system] = true;
    $pageTypes[$pageType] = true;

    $summary[] = [
        'scenario' => $scenario,
        'label' => $label,
        'system' => $system,
        'url' => $url,
        'status' => $status,
        'page_type' => $pageType,
        'rps' => (float) ($metrics['rps'] ?? 0.0),
        'p95_ms' => (float) ($metrics['p95_ms'] ?? 0.0),
        'error_rate' => (float) ($metrics['error_rate'] ?? 100.0),
        'avg_ms' => (float) ($metrics['avg_ms'] ?? 0.0),
        'round_count' => (int) ($metrics['round_count'] ?? count($roundP95)),
        'round_p95_min' => $roundP95 !== [] ? min($roundP95) : 0.0,
        'round_p95_max' => $roundP95 !== [] ? max($roundP95) : 0.0,
        'round_rps_min' => $roundRps !== [] ? min($roundRps) : 0.0,
        'round_rps_max' => $roundRps !== [] ? max($roundRps) : 0.0,
        'round_p95_values' => $roundP95,
        'round_rps_values' => $roundRps,
        'status_counts' => is_array($row['status_counts'] ?? null) ? $row['status_counts'] : [],
    ];
}

usort($summary, static function (array $a, array $b): int {
    $pageCmp = strcmp((string) $a['page_type'], (string) $b['page_type']);
    if ($pageCmp !== 0) {
        return $pageCmp;
    }
    $sysCmp = strcmp((string) $a['system'], (string) $b['system']);
    if ($sysCmp !== 0) {
        return $sysCmp;
    }
    return strcmp((string) $a['scenario'], (string) $b['scenario']);
});

$pageTypeRows = [];
foreach ($summary as $row) {
    $type = (string) $row['page_type'];
    $pageTypeRows[$type] ??= [];
    $pageTypeRows[$type][] = $row;
}
ksort($pageTypeRows);

$systemRows = [];
foreach (array_keys($systems) as $system) {
    $rows = array_values(array_filter($summary, static fn (array $row): bool => $row['system'] === $system));
    if ($rows === []) {
        continue;
    }

    $okRows = array_values(array_filter($rows, static fn (array $row): bool => $row['status'] === 'ok'));
    $baseRows = $okRows !== [] ? $okRows : $rows;
    $avgP95 = avg(array_column($baseRows, 'p95_ms'));
    $avgRps = avg(array_column($baseRows, 'rps'));
    $avgError = avg(array_column($rows, 'error_rate'));

    $systemRows[$system] = [
        'system' => $system,
        'scenarios' => count($rows),
        'ok_scenarios' => count($okRows),
        'avg_p95_ms' => $avgP95,
        'avg_rps' => $avgRps,
        'avg_error_rate' => $avgError,
        'wins_p95' => 0,
        'wins_rps' => 0,
    ];
}

foreach ($pageTypeRows as $typeRows) {
    $okRows = array_values(array_filter($typeRows, static fn (array $row): bool => $row['status'] === 'ok'));
    if ($okRows === []) {
        continue;
    }

    usort($okRows, static function (array $a, array $b): int {
        if ($a['p95_ms'] < $b['p95_ms']) {
            return -1;
        }
        if ($a['p95_ms'] > $b['p95_ms']) {
            return 1;
        }
        return strcmp((string) $a['system'], (string) $b['system']);
    });
    $bestP95System = (string) $okRows[0]['system'];
    if (isset($systemRows[$bestP95System])) {
        $systemRows[$bestP95System]['wins_p95']++;
    }

    usort($okRows, static function (array $a, array $b): int {
        if ($a['rps'] > $b['rps']) {
            return -1;
        }
        if ($a['rps'] < $b['rps']) {
            return 1;
        }
        return strcmp((string) $a['system'], (string) $b['system']);
    });
    $bestRpsSystem = (string) $okRows[0]['system'];
    if (isset($systemRows[$bestRpsSystem])) {
        $systemRows[$bestRpsSystem]['wins_rps']++;
    }
}

usort($systemRows, static function (array $a, array $b): int {
    if ($a['avg_p95_ms'] < $b['avg_p95_ms']) {
        return -1;
    }
    if ($a['avg_p95_ms'] > $b['avg_p95_ms']) {
        return 1;
    }
    if ($a['avg_rps'] > $b['avg_rps']) {
        return -1;
    }
    if ($a['avg_rps'] < $b['avg_rps']) {
        return 1;
    }
    return strcmp((string) $a['system'], (string) $b['system']);
});

$baseline = null;
foreach ($systemRows as $row) {
    if ($row['system'] === 'atoll') {
        $baseline = $row;
        break;
    }
}

$summaryCount = count($summary);
$okCount = count(array_filter($summary, static fn (array $row): bool => $row['status'] === 'ok'));
$errorCount = $summaryCount - $okCount;
$bestByP95 = null;
$bestByRps = null;
foreach ($summary as $row) {
    if ($row['status'] !== 'ok') {
        continue;
    }
    if ($bestByP95 === null || $row['p95_ms'] < $bestByP95['p95_ms']) {
        $bestByP95 = $row;
    }
    if ($bestByRps === null || $row['rps'] > $bestByRps['rps']) {
        $bestByRps = $row;
    }
}

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

$systemCardsHtml = '';
foreach ($systemRows as $row) {
    $ratioP95 = $baseline !== null && (float) $baseline['avg_p95_ms'] > 0.0
        ? (float) $row['avg_p95_ms'] / (float) $baseline['avg_p95_ms']
        : 1.0;
    $ratioRps = $baseline !== null && (float) $baseline['avg_rps'] > 0.0
        ? (float) $row['avg_rps'] / (float) $baseline['avg_rps']
        : 1.0;

    $systemCardsHtml .= '<article class="system-card">'
        . '<div class="system-card-head"><h3>' . h((string) $row['system']) . '</h3><span class="chip">'
        . (int) $row['ok_scenarios'] . '/' . (int) $row['scenarios'] . ' ok</span></div>'
        . '<div class="system-metrics">'
        . '<div><span class="label">Avg p95</span><strong>' . fmt((float) $row['avg_p95_ms'], 2) . ' ms</strong></div>'
        . '<div><span class="label">Avg RPS</span><strong>' . fmt((float) $row['avg_rps'], 2) . '</strong></div>'
        . '<div><span class="label">Wins p95</span><strong>' . (int) $row['wins_p95'] . '</strong></div>'
        . '<div><span class="label">Wins RPS</span><strong>' . (int) $row['wins_rps'] . '</strong></div>'
        . '</div>'
        . '<p class="small muted">vs atoll: p95 x' . fmt($ratioP95, 2) . ' | rps x' . fmt($ratioRps, 2) . '</p>'
        . '</article>';
}

$pageSectionsHtml = '';
foreach ($pageTypeRows as $type => $rows) {
    $okRows = array_values(array_filter($rows, static fn (array $row): bool => $row['status'] === 'ok'));
    $maxRps = $okRows !== [] ? max(array_column($okRows, 'rps')) : 0.0;
    $minP95 = $okRows !== [] ? min(array_column($okRows, 'p95_ms')) : 0.0;

    usort($rows, static function (array $a, array $b): int {
        if ($a['status'] === 'ok' && $b['status'] !== 'ok') {
            return -1;
        }
        if ($a['status'] !== 'ok' && $b['status'] === 'ok') {
            return 1;
        }
        if ($a['p95_ms'] < $b['p95_ms']) {
            return -1;
        }
        if ($a['p95_ms'] > $b['p95_ms']) {
            return 1;
        }
        return strcmp((string) $a['system'], (string) $b['system']);
    });

    $cards = '';
    foreach ($rows as $row) {
        $isOk = $row['status'] === 'ok';
        $p95Score = ($isOk && $minP95 > 0.0 && (float) $row['p95_ms'] > 0.0)
            ? min(100.0, max(0.0, ($minP95 / (float) $row['p95_ms']) * 100.0))
            : 0.0;
        $rpsScore = ($isOk && $maxRps > 0.0)
            ? min(100.0, max(0.0, ((float) $row['rps'] / $maxRps) * 100.0))
            : 0.0;
        $deltaP95 = ($isOk && $minP95 > 0.0) ? ((float) $row['p95_ms'] - $minP95) : 0.0;
        $deltaRps = ($isOk && $maxRps > 0.0) ? ($maxRps - (float) $row['rps']) : 0.0;
        $sparkline = sparkline((array) $row['round_p95_values']);

        $cards .= '<article class="scenario-card ' . ($isOk ? 'is-ok' : 'is-error') . '">'
            . '<div class="scenario-head"><h4>' . h((string) $row['system']) . '</h4>'
            . '<span class="status status-' . h((string) $row['status']) . '">' . h((string) $row['status']) . '</span></div>'
            . '<p class="scenario-label">' . h((string) $row['label']) . '</p>'
            . '<div class="metric-grid">'
            . '<div><span class="label">p95</span><strong>' . fmt((float) $row['p95_ms'], 2) . ' ms</strong></div>'
            . '<div><span class="label">RPS</span><strong>' . fmt((float) $row['rps'], 2) . '</strong></div>'
            . '<div><span class="label">Err</span><strong>' . fmt((float) $row['error_rate'], 2) . '%</strong></div>'
            . '<div><span class="label">Rounds</span><strong>' . (int) $row['round_count'] . '</strong></div>'
            . '</div>'
            . '<div class="bars">'
            . '<div class="bar-row"><span>p95 score</span><div class="bar"><i style="width:' . fmt($p95Score, 2) . '%"></i></div><em>+' . fmt($deltaP95, 2) . ' ms</em></div>'
            . '<div class="bar-row"><span>RPS score</span><div class="bar"><i style="width:' . fmt($rpsScore, 2) . '%"></i></div><em>-' . fmt($deltaRps, 2) . '</em></div>'
            . '</div>'
            . '<div class="sparkline"><span>p95 trend</span>' . $sparkline . '</div>'
            . '</article>';
    }

    $pageSectionsHtml .= '<section class="section">'
        . '<div class="section-head"><h2>' . h(strtoupper($type)) . '</h2>'
        . '<p>' . count($rows) . ' scenarios | best p95: ' . fmt($minP95, 2) . ' ms | best rps: ' . fmt($maxRps, 2) . '</p></div>'
        . '<div class="scenario-grid">' . $cards . '</div>'
        . '</section>';
}

$rawRows = '';
foreach ($summary as $row) {
    $rawRows .= '<tr>'
        . '<td>' . h((string) $row['page_type']) . '</td>'
        . '<td><strong>' . h((string) $row['scenario']) . '</strong><br><span class="muted">' . h((string) $row['label']) . '</span></td>'
        . '<td>' . h((string) $row['system']) . '</td>'
        . '<td><span class="status status-' . h((string) $row['status']) . '">' . h((string) $row['status']) . '</span></td>'
        . '<td class="num">' . fmt((float) $row['rps'], 2) . '</td>'
        . '<td class="num">' . fmt((float) $row['p95_ms'], 2) . '</td>'
        . '<td class="num">' . fmt((float) $row['error_rate'], 2) . '</td>'
        . '<td class="num">' . fmt((float) $row['round_p95_min'], 2) . ' - ' . fmt((float) $row['round_p95_max'], 2) . '</td>'
        . '<td><a href="' . h((string) $row['url']) . '">' . h((string) $row['url']) . '</a></td>'
        . '</tr>';
}

$runAtText = $runAt !== '' ? h($runAt) : 'n/a';
$sourceText = h(basename($inputPath));
$latestMd = is_file($outDir . '/latest.md') ? '<a href="latest.md">latest.md</a> | ' : '';
$bestP95Text = $bestByP95 !== null ? h((string) $bestByP95['system']) . ' (' . fmt((float) $bestByP95['p95_ms'], 2) . ' ms)' : 'n/a';
$bestRpsText = $bestByRps !== null ? h((string) $bestByRps['system']) . ' (' . fmt((float) $bestByRps['rps'], 2) . ')' : 'n/a';
$systemCount = count($systems);
$pageTypeCount = count($pageTypes);

$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>atoll Benchmark Dashboard</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Chivo:wght@400;500;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap');
    :root {
      --bg: #f6f7f3;
      --panel: #ffffff;
      --line: #d8dfd2;
      --line-strong: #b8c7ad;
      --text: #182017;
      --muted: #5f6f61;
      --accent: #2a6f57;
      --accent-soft: #d4e8dc;
      --warn: #b44f1c;
      --warn-soft: #fce9df;
      --ok: #1f7a49;
      --ok-soft: #dff4e8;
      --shadow: 0 10px 24px rgba(17, 34, 20, 0.08);
      --radius: 14px;
    }

    * { box-sizing: border-box; }
    body {
      margin: 0;
      color: var(--text);
      font-family: "IBM Plex Sans", "Segoe UI", sans-serif;
      background:
        radial-gradient(1200px 500px at 10% -15%, #dbead7 0%, transparent 60%),
        radial-gradient(900px 360px at 95% -25%, #ece4cb 0%, transparent 60%),
        var(--bg);
      line-height: 1.4;
    }

    .wrap {
      max-width: 1280px;
      margin: 0 auto;
      padding: 2rem 1.25rem 3rem;
    }

    .top {
      display: grid;
      gap: 1rem;
      grid-template-columns: 2fr 1fr;
      margin-bottom: 1rem;
    }

    .hero {
      background: linear-gradient(115deg, #143d2f, #2b5d47);
      color: #eef7f1;
      border-radius: var(--radius);
      padding: 1.35rem 1.4rem 1.45rem;
      box-shadow: var(--shadow);
    }

    .hero h1 {
      margin: 0 0 .25rem;
      font: 700 2rem/1 "Chivo", "IBM Plex Sans", sans-serif;
      letter-spacing: -0.02em;
    }

    .hero p {
      margin: 0;
      color: #d7eadc;
      font-size: .98rem;
    }

    .meta {
      margin-top: .9rem;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: .55rem;
      font-size: .88rem;
      color: #d7eadc;
    }

    .links {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 1rem 1.1rem;
      box-shadow: var(--shadow);
    }

    .links h2 {
      margin: 0 0 .55rem;
      font: 700 1rem/1 "Chivo", sans-serif;
      color: #2c3f30;
    }

    .links a {
      color: var(--accent);
      text-decoration: none;
      font-weight: 600;
    }

    .links a:hover { text-decoration: underline; }

    .kpis {
      margin: .65rem 0 1.1rem;
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: .65rem;
    }

    .kpi {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: .85rem .9rem;
      box-shadow: var(--shadow);
      min-height: 86px;
    }

    .kpi .label {
      display: block;
      color: var(--muted);
      font-size: .74rem;
      text-transform: uppercase;
      letter-spacing: .06em;
      margin-bottom: .35rem;
    }

    .kpi strong {
      display: block;
      font: 700 1.35rem/1 "Chivo", sans-serif;
      letter-spacing: -0.02em;
      color: #1b291f;
    }

    .section {
      margin: 1rem 0 1.2rem;
    }

    .section-head {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      gap: 1rem;
      margin-bottom: .5rem;
    }

    .section-head h2 {
      margin: 0;
      font: 700 1.16rem/1 "Chivo", sans-serif;
      letter-spacing: 0.02em;
    }

    .section-head p {
      margin: 0;
      color: var(--muted);
      font-size: .82rem;
    }

    .system-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
      gap: .65rem;
    }

    .system-card {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: .85rem;
      box-shadow: var(--shadow);
    }

    .system-card-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: .55rem;
    }

    .system-card h3 {
      margin: 0;
      font: 700 1rem/1 "Chivo", sans-serif;
      text-transform: lowercase;
    }

    .chip {
      border-radius: 999px;
      background: var(--accent-soft);
      color: var(--accent);
      font-size: .73rem;
      font-weight: 700;
      padding: .18rem .5rem;
    }

    .system-metrics {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: .5rem;
    }

    .label {
      color: var(--muted);
      display: block;
      font-size: .72rem;
      text-transform: uppercase;
      letter-spacing: .06em;
      margin-bottom: .2rem;
    }

    .small { font-size: .78rem; }
    .muted { color: var(--muted); }

    .scenario-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: .65rem;
    }

    .scenario-card {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: .85rem;
      box-shadow: var(--shadow);
    }

    .scenario-card.is-error {
      border-color: #efc2af;
      background: #fffaf8;
    }

    .scenario-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: .35rem;
    }

    .scenario-head h4 {
      margin: 0;
      font: 700 .95rem/1 "Chivo", sans-serif;
      text-transform: lowercase;
    }

    .scenario-label {
      margin: 0 0 .55rem;
      font-size: .8rem;
      color: var(--muted);
    }

    .status {
      border-radius: 999px;
      padding: .13rem .44rem;
      font-size: .72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      border: 1px solid transparent;
    }

    .status-ok {
      background: var(--ok-soft);
      color: var(--ok);
      border-color: #b9dfcb;
    }

    .status-error {
      background: var(--warn-soft);
      color: var(--warn);
      border-color: #efc2af;
    }

    .metric-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: .45rem .5rem;
      margin-bottom: .5rem;
    }

    .metric-grid strong {
      font: 700 1rem/1 "Chivo", sans-serif;
    }

    .bars { display: grid; gap: .35rem; }

    .bar-row {
      display: grid;
      grid-template-columns: 66px 1fr 64px;
      align-items: center;
      gap: .45rem;
      font-size: .72rem;
      color: var(--muted);
    }

    .bar {
      height: 8px;
      background: #e8eee6;
      border-radius: 999px;
      overflow: hidden;
      border: 1px solid #dce5d8;
    }

    .bar i {
      display: block;
      height: 100%;
      background: linear-gradient(90deg, #2a6f57, #4da177);
      border-radius: inherit;
    }

    .bar-row em {
      font-style: normal;
      text-align: right;
      color: #4d5f4f;
      font-weight: 600;
    }

    .sparkline {
      margin-top: .4rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .5rem;
      font-size: .72rem;
      color: var(--muted);
    }

    .sparkline svg {
      width: 120px;
      height: 28px;
      display: block;
    }

    details {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 12px;
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    summary {
      cursor: pointer;
      list-style: none;
      padding: .9rem 1rem;
      font: 700 .9rem/1 "Chivo", sans-serif;
      border-bottom: 1px solid var(--line);
    }

    summary::-webkit-details-marker { display: none; }

    .table-wrap { overflow-x: auto; }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: .82rem;
    }

    th, td {
      border-bottom: 1px solid var(--line);
      padding: .5rem .6rem;
      text-align: left;
      vertical-align: top;
    }

    th {
      background: #f0f4ee;
      color: #324235;
      position: sticky;
      top: 0;
      z-index: 1;
    }

    .num {
      text-align: right;
      font-variant-numeric: tabular-nums;
    }

    @media (max-width: 1060px) {
      .top { grid-template-columns: 1fr; }
      .kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }

    @media (max-width: 780px) {
      .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .meta { grid-template-columns: 1fr; }
    }

    @media (max-width: 540px) {
      .kpis { grid-template-columns: 1fr; }
      .bar-row { grid-template-columns: 54px 1fr 56px; }
      .section-head { flex-direction: column; align-items: flex-start; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <section class="top">
      <header class="hero">
        <h1>Benchmark Dashboard</h1>
        <p>Cross-CMS read-path comparison with grouped scenarios and relative performance scores.</p>
        <div class="meta">
          <div><strong>Run at:</strong><br>{$runAtText}</div>
          <div><strong>Source:</strong><br>{$sourceText}</div>
          <div><strong>Rounds:</strong><br>{$configuredRounds}</div>
          <div><strong>Error threshold:</strong><br>{$maxErrorRate}%</div>
        </div>
      </header>
      <aside class="links">
        <h2>Artifacts</h2>
        <p><a href="latest.json">latest.json</a> | {$latestMd}<a href="latest.raw.json">latest.raw.json</a></p>
        <p class="small muted">Use <code>latest.raw.json</code> for auditability. <code>latest.json</code> is compact for embeds.</p>
      </aside>
    </section>

    <section class="kpis">
      <article class="kpi"><span class="label">Scenarios</span><strong>{$summaryCount}</strong></article>
      <article class="kpi"><span class="label">Systems</span><strong>{$systemCount}</strong></article>
      <article class="kpi"><span class="label">Page Types</span><strong>{$pageTypeCount}</strong></article>
      <article class="kpi"><span class="label">Best p95</span><strong>{$bestP95Text}</strong></article>
      <article class="kpi"><span class="label">Best RPS</span><strong>{$bestRpsText}</strong></article>
    </section>

    <section class="section">
      <div class="section-head">
        <h2>System Leaderboard</h2>
        <p>{$okCount} ok | {$errorCount} error scenarios</p>
      </div>
      <div class="system-grid">{$systemCardsHtml}</div>
    </section>

    {$pageSectionsHtml}

    <details class="section">
      <summary>Raw Scenario Table</summary>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Type</th>
              <th>Scenario</th>
              <th>System</th>
              <th>Status</th>
              <th class="num">RPS</th>
              <th class="num">p95 (ms)</th>
              <th class="num">Err %</th>
              <th class="num">p95 range</th>
              <th>URL</th>
            </tr>
          </thead>
          <tbody>
            {$rawRows}
          </tbody>
        </table>
      </div>
    </details>
  </div>
</body>
</html>
HTML;

file_put_contents($outDir . '/index.html', $html);
echo "Benchmark pages written to: {$outDir}\n";

function detectPageType(string $scenario, string $url): string
{
    $parts = array_values(array_filter(explode('-', $scenario), static fn (string $item): bool => $item !== ''));
    if ($parts !== []) {
        $tail = strtolower((string) end($parts));
        if (preg_match('/^[a-z0-9_]+$/', $tail) === 1) {
            return $tail;
        }
    }

    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || trim($path, '/') === '') {
        return 'home';
    }
    $segments = explode('/', trim($path, '/'));
    return strtolower((string) ($segments[0] ?? 'other'));
}

/**
 * @param array<int, float|int> $values
 */
function avg(array $values): float
{
    if ($values === []) {
        return 0.0;
    }
    return array_sum($values) / count($values);
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt(float $value, int $decimals): string
{
    return number_format($value, $decimals, '.', ',');
}

/**
 * @param array<int, float|int> $values
 */
function sparkline(array $values): string
{
    $points = array_values(array_map(static fn ($value): float => (float) $value, $values));
    $count = count($points);
    if ($count < 2) {
        return '<span class="muted">n/a</span>';
    }

    $width = 120.0;
    $height = 28.0;
    $min = min($points);
    $max = max($points);
    $range = max(0.0001, $max - $min);

    $coords = [];
    foreach ($points as $index => $point) {
        $x = ($width / ($count - 1)) * $index;
        $y = $height - (($point - $min) / $range) * $height;
        $coords[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
    }

    $polyline = implode(' ', $coords);
    return '<svg viewBox="0 0 120 28" preserveAspectRatio="none" role="img" aria-label="p95 trend">'
        . '<polyline points="' . h($polyline) . '" fill="none" stroke="#2a6f57" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></polyline>'
        . '</svg>';
}
