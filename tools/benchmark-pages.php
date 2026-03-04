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

// --- Excluded profiles (atoll variants that clutter the comparison) ---
$excludedSystems = ['atoll-minimal', 'atoll-static'];

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

// --- Filter to main systems only ---
$displaySummary = array_values(array_filter($summary, static fn (array $row): bool => !in_array($row['system'], $excludedSystems, true)));

// --- Per-system aggregation ---
$systemRows = [];
foreach (array_keys($systems) as $system) {
    if (in_array($system, $excludedSystems, true)) {
        continue;
    }
    $rows = array_values(array_filter($displaySummary, static fn (array $row): bool => $row['system'] === $system));
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

// --- Page-type grouping (display systems only) ---
$pageTypeRows = [];
foreach ($displaySummary as $row) {
    $type = (string) $row['page_type'];
    $pageTypeRows[$type] ??= [];
    $pageTypeRows[$type][] = $row;
}
ksort($pageTypeRows);

// --- Count wins ---
foreach ($pageTypeRows as $typeRows) {
    $okRows = array_values(array_filter($typeRows, static fn (array $row): bool => $row['status'] === 'ok'));
    if ($okRows === []) {
        continue;
    }

    usort($okRows, static fn (array $a, array $b): int => $a['p95_ms'] <=> $b['p95_ms'] ?: strcmp($a['system'], $b['system']));
    $best = (string) $okRows[0]['system'];
    if (isset($systemRows[$best])) {
        $systemRows[$best]['wins_p95']++;
    }

    usort($okRows, static fn (array $a, array $b): int => $b['rps'] <=> $a['rps'] ?: strcmp($a['system'], $b['system']));
    $best = (string) $okRows[0]['system'];
    if (isset($systemRows[$best])) {
        $systemRows[$best]['wins_rps']++;
    }
}

// --- atoll baseline (before usort destroys string keys) ---
$atollRow = $systemRows['atoll'] ?? null;

// Sort systems: atoll first, then by avg p95
usort($systemRows, static function (array $a, array $b): int {
    if ($a['system'] === 'atoll') {
        return -1;
    }
    if ($b['system'] === 'atoll') {
        return 1;
    }
    return $a['avg_p95_ms'] <=> $b['avg_p95_ms'] ?: $b['avg_rps'] <=> $a['avg_rps'] ?: strcmp($a['system'], $b['system']);
});
$atollP95 = $atollRow !== null ? (float) $atollRow['avg_p95_ms'] : 1.0;
$atollRps = $atollRow !== null ? (float) $atollRow['avg_rps'] : 1.0;

$displayCount = count($displaySummary);

// --- JSON export (keep existing format) ---
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

// ============================================================
// HTML Generation
// ============================================================

// --- Build system comparison rows ---
$allSystemRows = array_values($systemRows);
$maxP95InGroup = max(array_column($allSystemRows, 'avg_p95_ms'));
$maxRpsInGroup = max(array_column($allSystemRows, 'avg_rps'));

$systemsHtml = '';
foreach ($allSystemRows as $row) {
    $isAtoll = $row['system'] === 'atoll';
    $ratioP95 = $atollP95 > 0.0 ? (float) $row['avg_p95_ms'] / $atollP95 : 1.0;

    $p95Bar = $maxP95InGroup > 0.0 ? min(100.0, ((float) $row['avg_p95_ms'] / $maxP95InGroup) * 100.0) : 0.0;
    $rpsBar = $maxRpsInGroup > 0.0 ? min(100.0, ((float) $row['avg_rps'] / $maxRpsInGroup) * 100.0) : 0.0;

    $cls = $isAtoll ? ' is-atoll' : '';
    $badge = '';
    if (!$isAtoll) {
        $badge = '<span class="ratio-badge">' . fmt($ratioP95, 1) . 'x latency</span>';
    }

    $systemsHtml .= '<div class="sys-row' . $cls . '">'
        . '<div class="sys-name"><strong>' . h((string) $row['system']) . '</strong>' . $badge . '</div>'
        . '<div class="sys-stats">'
        . '<div class="sys-stat"><span>p95</span><strong>' . fmt((float) $row['avg_p95_ms'], 2) . ' ms</strong></div>'
        . '<div class="sys-stat"><span>RPS</span><strong>' . fmt((float) $row['avg_rps'], 0) . '</strong></div>'
        . '<div class="sys-stat"><span>Wins p95</span><strong>' . (int) $row['wins_p95'] . '/' . count($pageTypes) . '</strong></div>'
        . '<div class="sys-stat"><span>Wins RPS</span><strong>' . (int) $row['wins_rps'] . '/' . count($pageTypes) . '</strong></div>'
        . '</div>'
        . '<div class="sys-bars">'
        . '<div class="sys-bar-row"><span class="bar-label">Latency</span><div class="bar bar-p95"><i style="width:' . fmt($p95Bar, 1) . '%"></i></div></div>'
        . '<div class="sys-bar-row"><span class="bar-label">Throughput</span><div class="bar bar-rps"><i style="width:' . fmt($rpsBar, 1) . '%"></i></div></div>'
        . '</div>'
        . '</div>';
}

// --- Build per-page-type comparison sections ---
$pageSectionsHtml = '';
foreach ($pageTypeRows as $type => $rows) {
    $okRows = array_values(array_filter($rows, static fn (array $row): bool => $row['status'] === 'ok'));
    if ($okRows === []) {
        continue;
    }

    usort($okRows, static fn (array $a, array $b): int => $a['p95_ms'] <=> $b['p95_ms']);
    $maxRps = max(array_column($okRows, 'rps'));
    $maxP95 = max(array_column($okRows, 'p95_ms'));

    $barRows = '';
    foreach ($okRows as $row) {
        $isAtoll = $row['system'] === 'atoll';
        $p95Pct = $maxP95 > 0.0 ? min(100.0, ((float) $row['p95_ms'] / $maxP95) * 100.0) : 0.0;
        $rpsPct = $maxRps > 0.0 ? min(100.0, ((float) $row['rps'] / $maxRps) * 100.0) : 0.0;
        $sparkSvg = sparkline((array) $row['round_p95_values']);

        $cls = $isAtoll ? ' is-atoll' : '';
        $barRows .= '<div class="cmp-row' . $cls . '">'
            . '<div class="cmp-system">' . h((string) $row['system']) . '</div>'
            . '<div class="cmp-bar-cell"><div class="bar bar-rps"><i style="width:' . fmt($rpsPct, 1) . '%"></i></div></div>'
            . '<div class="cmp-val">' . fmt((float) $row['rps'], 0) . ' <small>rps</small></div>'
            . '<div class="cmp-bar-cell"><div class="bar bar-p95-inv"><i style="width:' . fmt($p95Pct, 1) . '%"></i></div></div>'
            . '<div class="cmp-val">' . fmt((float) $row['p95_ms'], 2) . ' <small>ms</small></div>'
            . '<div class="cmp-spark">' . $sparkSvg . '</div>'
            . '</div>';
    }

    $pageSectionsHtml .= '<section class="page-section">'
        . '<h2>' . h(ucfirst($type)) . '</h2>'
        . '<div class="cmp-header">'
        . '<div class="cmp-system"></div>'
        . '<div class="cmp-bar-cell"><span>Throughput (RPS)</span></div>'
        . '<div class="cmp-val"></div>'
        . '<div class="cmp-bar-cell"><span>Latency (p95)</span></div>'
        . '<div class="cmp-val"></div>'
        . '<div class="cmp-spark"><span>Trend</span></div>'
        . '</div>'
        . $barRows
        . '</section>';
}

// --- Raw table ---
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

// --- Template variables ---
$runAtText = $runAt !== '' ? h($runAt) : 'n/a';
$sourceText = h(basename($inputPath));
$latestMd = is_file($outDir . '/latest.md') ? '<a href="latest.md">Markdown</a> | ' : '';
$atollP95Text = $atollRow !== null ? fmt((float) $atollRow['avg_p95_ms'], 2) : 'n/a';
$atollRpsText = $atollRow !== null ? fmt((float) $atollRow['avg_rps'], 0) : 'n/a';
$atollWinsP95 = $atollRow !== null ? (int) $atollRow['wins_p95'] : 0;
$pageTypeCount = count($pageTypes);
$systemCount = count($systemRows);

$html = <<<'CSSBLOCK'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>atoll Benchmark Dashboard</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap');

    :root {
      --bg: #f4f2ee;
      --panel: #ffffff;
      --line: #ddd9d0;
      --text: #1a1a18;
      --muted: #6b6860;
      --accent: #1a5c3a;
      --accent-soft: #e3f0e8;
      --accent-glow: #2d8a56;
      --gold: #b8860b;
      --gold-soft: #fdf6e3;
      --warn: #b44f1c;
      --warn-soft: #fce9df;
      --ok: #1f7a49;
      --ok-soft: #dff4e8;
      --hero-from: #0a1f14;
      --hero-to: #163828;
      --shadow: 0 2px 12px rgba(0,0,0,.06);
      --radius: 10px;
    }

    * { box-sizing: border-box; margin: 0; }

    body {
      color: var(--text);
      font: 400 15px/1.5 "DM Sans", system-ui, sans-serif;
      background: var(--bg);
    }

    .hero {
      background: linear-gradient(135deg, var(--hero-from), var(--hero-to));
      color: #e8efe9;
      padding: 2.5rem 1.5rem 2rem;
    }

    .hero-inner {
      max-width: 1120px;
      margin: 0 auto;
    }

    .hero h1 {
      font: 400 2.4rem/1.1 "DM Serif Display", Georgia, serif;
      color: #fff;
      margin-bottom: .3rem;
      letter-spacing: -.01em;
    }

    .hero h1 em {
      font-style: italic;
      color: #7dcea0;
    }

    .hero-sub {
      font-size: .95rem;
      color: #a3bfac;
      margin-bottom: 1.6rem;
    }

    .hero-stats {
      display: flex;
      gap: 2.5rem;
      flex-wrap: wrap;
    }

    .hero-stat {
      display: flex;
      flex-direction: column;
    }

    .hero-stat .val {
      font: 400 2rem/1 "DM Serif Display", Georgia, serif;
      color: #fff;
      letter-spacing: -.02em;
    }

    .hero-stat .lbl {
      font-size: .72rem;
      text-transform: uppercase;
      letter-spacing: .08em;
      color: #7dcea0;
      margin-top: .2rem;
    }

    .wrap {
      max-width: 1120px;
      margin: 0 auto;
      padding: 1.5rem 1.5rem 3rem;
    }

    .meta-bar {
      display: flex;
      flex-wrap: wrap;
      gap: .5rem 1.5rem;
      font-size: .78rem;
      color: var(--muted);
      margin-bottom: 1.5rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid var(--line);
    }

    .meta-bar a {
      color: var(--accent);
      text-decoration: none;
      font-weight: 600;
    }

    .meta-bar a:hover { text-decoration: underline; }

    /* --- System comparison --- */
    .section-title {
      font: 400 1.5rem/1.2 "DM Serif Display", Georgia, serif;
      margin: 2rem 0 .3rem;
      letter-spacing: -.01em;
    }

    .section-sub {
      color: var(--muted);
      font-size: .82rem;
      margin-bottom: .8rem;
    }

    .sys-row {
      display: grid;
      grid-template-columns: 180px 1fr 1fr;
      gap: .5rem 1.2rem;
      align-items: center;
      padding: .8rem 1rem;
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      margin-bottom: .45rem;
      box-shadow: var(--shadow);
    }

    .sys-row.is-atoll {
      border-left: 3px solid var(--gold);
      background: var(--gold-soft);
    }

    .sys-name {
      display: flex;
      flex-direction: column;
      gap: .2rem;
    }

    .sys-name strong {
      font: 700 1rem/1 "DM Sans", sans-serif;
      text-transform: lowercase;
    }

    .ratio-badge {
      display: inline-block;
      font-size: .68rem;
      font-weight: 600;
      color: var(--muted);
      background: #eee;
      border-radius: 999px;
      padding: .1rem .45rem;
      width: fit-content;
    }

    .sys-stats {
      display: flex;
      gap: 1.2rem;
    }

    .sys-stat span {
      display: block;
      font-size: .65rem;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: var(--muted);
    }

    .sys-stat strong {
      font: 700 .95rem/1.2 "DM Sans", sans-serif;
    }

    .sys-bars {
      display: grid;
      gap: .3rem;
    }

    .sys-bar-row {
      display: grid;
      grid-template-columns: 72px 1fr;
      align-items: center;
      gap: .4rem;
    }

    .bar-label {
      font-size: .65rem;
      text-transform: uppercase;
      letter-spacing: .05em;
      color: var(--muted);
    }

    .bar {
      height: 7px;
      background: #eae7e0;
      border-radius: 999px;
      overflow: hidden;
    }

    .bar i {
      display: block;
      height: 100%;
      border-radius: inherit;
    }

    .bar-rps i { background: linear-gradient(90deg, #1a5c3a, #2d8a56); }
    .bar-p95 i { background: linear-gradient(90deg, #c4883a, #e0a84c); }
    .bar-p95-inv i { background: linear-gradient(90deg, #c4883a, #e0a84c); }

    /* --- Page-type comparison --- */
    .page-section {
      margin: 2rem 0;
    }

    .page-section h2 {
      font: 400 1.3rem/1.2 "DM Serif Display", Georgia, serif;
      margin-bottom: .6rem;
      padding-bottom: .4rem;
      border-bottom: 1px solid var(--line);
    }

    .cmp-header, .cmp-row {
      display: grid;
      grid-template-columns: 120px 1fr 80px 1fr 80px 80px;
      gap: .4rem;
      align-items: center;
      padding: .35rem .6rem;
    }

    .cmp-header {
      font-size: .65rem;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: var(--muted);
      border-bottom: 1px solid var(--line);
    }

    .cmp-row {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 8px;
      margin-bottom: .3rem;
      box-shadow: var(--shadow);
    }

    .cmp-row.is-atoll {
      border-left: 3px solid var(--gold);
      background: var(--gold-soft);
    }

    .cmp-system {
      font-weight: 600;
      font-size: .88rem;
      text-transform: lowercase;
    }

    .cmp-val {
      font: 700 .88rem/1 "DM Sans", sans-serif;
      text-align: right;
      font-variant-numeric: tabular-nums;
    }

    .cmp-val small {
      font-weight: 400;
      font-size: .7rem;
      color: var(--muted);
    }

    .cmp-bar-cell { min-width: 0; }

    .cmp-spark {
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .cmp-spark svg {
      width: 60px;
      height: 22px;
      display: block;
    }

    /* --- Raw table --- */
    details {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow: hidden;
      margin-top: 2rem;
    }

    summary {
      cursor: pointer;
      list-style: none;
      padding: .8rem 1rem;
      font: 600 .85rem/1 "DM Sans", sans-serif;
      border-bottom: 1px solid var(--line);
    }

    summary::-webkit-details-marker { display: none; }

    .table-wrap { overflow-x: auto; }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: .8rem;
    }

    th, td {
      border-bottom: 1px solid var(--line);
      padding: .45rem .55rem;
      text-align: left;
      vertical-align: top;
    }

    th {
      background: #f0ede7;
      color: #40403c;
      position: sticky;
      top: 0;
      z-index: 1;
      font-weight: 600;
    }

    .num {
      text-align: right;
      font-variant-numeric: tabular-nums;
    }

    .muted { color: var(--muted); }

    .status {
      border-radius: 999px;
      padding: .12rem .4rem;
      font-size: .7rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .status-ok { background: var(--ok-soft); color: var(--ok); }
    .status-error { background: var(--warn-soft); color: var(--warn); }

    .footer {
      margin-top: 2.5rem;
      padding-top: 1rem;
      border-top: 1px solid var(--line);
      font-size: .75rem;
      color: var(--muted);
    }

    @media (max-width: 900px) {
      .sys-row {
        grid-template-columns: 1fr;
      }
      .hero-stats { gap: 1.5rem; }
      .cmp-header, .cmp-row {
        grid-template-columns: 90px 1fr 65px 1fr 65px 60px;
        font-size: .78rem;
      }
    }

    @media (max-width: 600px) {
      .hero h1 { font-size: 1.6rem; }
      .hero-stat .val { font-size: 1.4rem; }
      .sys-stats { flex-wrap: wrap; gap: .6rem; }
      .cmp-header, .cmp-row {
        grid-template-columns: 80px 1fr 60px;
      }
      .cmp-row .cmp-bar-cell:nth-child(4),
      .cmp-row .cmp-val:nth-child(5),
      .cmp-row .cmp-spark,
      .cmp-header .cmp-bar-cell:nth-child(4),
      .cmp-header .cmp-val:nth-child(5),
      .cmp-header .cmp-spark { display: none; }
    }
  </style>
</head>
CSSBLOCK;

$html .= <<<HTML
<body>
  <header class="hero">
    <div class="hero-inner">
      <h1>atoll <em>Performance</em></h1>
      <p class="hero-sub">Flat-file CMS read-path latency and throughput, measured head-to-head.</p>
      <div class="hero-stats">
        <div class="hero-stat"><span class="val">{$atollP95Text} ms</span><span class="lbl">atoll avg p95 latency</span></div>
        <div class="hero-stat"><span class="val">{$atollRpsText}</span><span class="lbl">atoll avg requests / sec</span></div>
        <div class="hero-stat"><span class="val">{$atollWinsP95}/{$pageTypeCount}</span><span class="lbl">page types won (p95)</span></div>
        <div class="hero-stat"><span class="val">{$systemCount}</span><span class="lbl">CMS systems compared</span></div>
      </div>
    </div>
  </header>

  <div class="wrap">
    <div class="meta-bar">
      <span>Run: {$runAtText}</span>
      <span>Rounds: {$configuredRounds}</span>
      <span>Source: {$sourceText}</span>
      <span>{$latestMd}<a href="latest.json">JSON</a> | <a href="latest.raw.json">Raw JSON</a></span>
    </div>

    <h2 class="section-title">System Comparison</h2>
    <p class="section-sub">Flat-file CMS systems with routing and admin capabilities. Sorted by average p95 latency.</p>
    {$systemsHtml}

HTML;

$html .= <<<HTML

    <h2 class="section-title">Per-Page Breakdown</h2>
    <p class="section-sub">Performance by page type, separated by system category.</p>
    {$pageSectionsHtml}

    <details>
      <summary>Raw Scenario Data</summary>
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

    <div class="footer">
      Methodology: Each scenario runs {$configuredRounds} rounds of concurrent HTTP requests. Metrics use the median across rounds. Error threshold: {$maxErrorRate}%.
    </div>
  </div>
</body>
</html>
HTML;

file_put_contents($outDir . '/index.html', $html);
echo "Benchmark pages written to: {$outDir}\n";

// ============================================================
// Helper functions
// ============================================================

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

    $width = 60.0;
    $height = 22.0;
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
    return '<svg viewBox="0 0 60 22" preserveAspectRatio="none" role="img" aria-label="p95 trend">'
        . '<polyline points="' . h($polyline) . '" fill="none" stroke="#1a5c3a" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></polyline>'
        . '</svg>';
}
