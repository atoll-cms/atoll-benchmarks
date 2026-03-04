#!/usr/bin/env php
<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Missing vendor autoload. Run: composer install\n");
    exit(1);
}
require $autoload;

use Symfony\Component\Yaml\Yaml;

if (!extension_loaded('curl')) {
    fwrite(STDERR, "The curl extension is required for benchmark:run.\n");
    exit(1);
}

$options = parseOptions($argv);
$root = resolveRoot($options['root'] ?? null);
$configPath = resolvePath($root, (string) ($options['config'] ?? 'benchmarks/targets.yaml'));
$outPath = resolvePath($root, (string) ($options['out'] ?? ('benchmarks/results/' . date('Ymd-His') . '.json')));
$rounds = max(1, (int) ($options['rounds'] ?? 3));
$maxErrorRate = max(0.0, min(100.0, (float) ($options['max-error-rate'] ?? 5.0)));
$only = parseCsv((string) ($options['only'] ?? ''));

if (!is_file($configPath)) {
    fwrite(STDERR, "Benchmark config not found: {$configPath}\n");
    exit(1);
}

$config = loadConfigFile($configPath);
$defaults = normalizeDefaults($config['defaults'] ?? []);
$scenarios = normalizeScenarios($config['scenarios'] ?? []);
if ($scenarios === []) {
    fwrite(STDERR, "No benchmark scenarios configured.\n");
    exit(1);
}

if ($only !== []) {
    $onlyIndex = array_fill_keys($only, true);
    $scenarios = array_values(array_filter($scenarios, static fn (array $scenario): bool => isset($onlyIndex[$scenario['id']])));
    if ($scenarios === []) {
        fwrite(STDERR, "No matching scenarios found for --only.\n");
        exit(1);
    }
}

$run = [
    'generated_at' => date('c'),
    'runner' => [
        'php' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'os' => PHP_OS_FAMILY,
        'host' => gethostname() ?: 'unknown',
    ],
    'config_path' => $configPath,
    'rounds' => $rounds,
    'max_error_rate' => $maxErrorRate,
    'defaults' => $defaults,
    'results' => [],
];

echo "Running benchmark with {$rounds} round(s)...\n";

$okCount = 0;
foreach ($scenarios as $scenario) {
    $effective = mergeScenarioDefaults($scenario, $defaults);
    $result = [
        'id' => $scenario['id'],
        'label' => $scenario['label'],
        'system' => $scenario['system'],
        'url' => $scenario['url'],
        'effective' => $effective,
        'rounds' => [],
    ];

    echo sprintf(
        "- %s (%s): %s\n",
        $scenario['id'],
        $scenario['system'],
        $scenario['url']
    );

    $scenarioError = null;
    $preflight = preflightRequest(
        $scenario['url'],
        (int) $effective['timeout_ms'],
        $effective['headers']
    );
    $result['preflight'] = $preflight;
    if (($preflight['ok'] ?? false) !== true) {
        $scenarioError = sprintf(
            'Preflight failed (status=%d errno=%d%s)',
            (int) ($preflight['status'] ?? 0),
            (int) ($preflight['errno'] ?? 0),
            (($preflight['error'] ?? '') !== '' ? ', error=' . (string) $preflight['error'] : '')
        );
        echo "  preflight: failed ({$scenarioError})\n";
    } else {
        echo sprintf(
            "  preflight: ok (status=%d)\n",
            (int) ($preflight['status'] ?? 0)
        );
    }

    for ($round = 1; $round <= $rounds; $round++) {
        if ($scenarioError !== null) {
            break;
        }
        try {
            runWarmup(
                $scenario['url'],
                (int) $effective['warmup_requests'],
                (int) $effective['timeout_ms'],
                $effective['headers']
            );
            $metrics = runRound(
                $scenario['url'],
                (int) $effective['requests'],
                (int) $effective['concurrency'],
                (int) $effective['timeout_ms'],
                $effective['headers']
            );
            $metrics['round'] = $round;
            $result['rounds'][] = $metrics;

            echo sprintf(
                "  round %d: rps=%.2f p95=%.1fms error=%.2f%%\n",
                $round,
                (float) $metrics['rps'],
                (float) $metrics['p95_ms'],
                (float) $metrics['error_rate']
            );
        } catch (RuntimeException $e) {
            $scenarioError = $e->getMessage();
            echo "  round {$round}: failed ({$scenarioError})\n";
            break;
        }
    }

    if ($scenarioError !== null || $result['rounds'] === []) {
        $result['status'] = 'error';
        $result['error'] = $scenarioError ?? 'No data';
    } else {
        $statusCounts = aggregateStatusCounts($result['rounds']);
        $result['status_counts'] = $statusCounts;
        $summary = summarizeRounds($result['rounds']);
        $result['summary'] = $summary;
        $errorRate = (float) ($summary['error_rate'] ?? 100.0);
        $success = (float) ($summary['success'] ?? 0.0);
        $status429 = (int) ($statusCounts['status_429'] ?? 0);

        if ($success <= 0.0) {
            $result['status'] = 'error';
            $result['error'] = 'No successful responses captured. Check host/port binding and target URLs.';
        } elseif ($status429 > 0) {
            $result['status'] = 'error';
            $result['error'] = sprintf(
                'Detected %d HTTP 429 responses. Rate limiting is active and invalidates this benchmark run.',
                $status429
            );
        } elseif ($errorRate > $maxErrorRate) {
            $result['status'] = 'error';
            $result['error'] = sprintf(
                'Error rate %.2f%% exceeds threshold %.2f%%.',
                $errorRate,
                $maxErrorRate
            );
        } else {
            $result['status'] = 'ok';
            $okCount++;
        }
    }

    $run['results'][] = $result;
}

if (!is_dir(dirname($outPath))) {
    mkdir(dirname($outPath), 0775, true);
}

file_put_contents(
    $outPath,
    json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

echo "\nBenchmark summary:\n";
printSummary($run['results']);
echo "\nSaved JSON report: {$outPath}\n";

exit($okCount > 0 ? 0 : 1);

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function parseOptions(array $argv): array
{
    $options = [];
    foreach ($argv as $index => $arg) {
        if ($index === 0) {
            continue;
        }
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $parts = explode('=', $arg, 2);
        $key = substr($parts[0], 2);
        $value = $parts[1] ?? '1';
        $options[$key] = $value;
    }

    return $options;
}

function resolveRoot(?string $root): string
{
    $candidate = $root;
    if ($candidate === null || trim($candidate) === '') {
        $candidate = dirname(__DIR__);
    }

    $resolved = realpath($candidate);
    if ($resolved === false) {
        fwrite(STDERR, "Could not resolve project root: {$candidate}\n");
        exit(1);
    }

    return $resolved;
}

function resolvePath(string $root, string $path): string
{
    if ($path === '') {
        return $root;
    }

    if (str_starts_with($path, '/')) {
        return $path;
    }

    return $root . '/' . $path;
}

/**
 * @return array<string, mixed>
 */
function loadConfigFile(string $path): array
{
    $raw = (string) file_get_contents($path);
    if ($raw === '') {
        return [];
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'json') {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    $parsed = Yaml::parse($raw);
    return is_array($parsed) ? $parsed : [];
}

/**
 * @param mixed $raw
 * @return array<string, mixed>
 */
function normalizeDefaults(mixed $raw): array
{
    $data = is_array($raw) ? $raw : [];
    $headers = [];
    if (is_array($data['headers'] ?? null)) {
        foreach ($data['headers'] as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $headers[$key] = (string) $value;
        }
    }

    return [
        'warmup_requests' => max(0, (int) ($data['warmup_requests'] ?? 20)),
        'requests' => max(1, (int) ($data['requests'] ?? 200)),
        'concurrency' => max(1, (int) ($data['concurrency'] ?? 10)),
        'timeout_ms' => max(100, (int) ($data['timeout_ms'] ?? 8000)),
        'headers' => $headers,
    ];
}

/**
 * @param mixed $raw
 * @return array<int, array{id:string,label:string,system:string,url:string,warmup_requests?:int,requests?:int,concurrency?:int,timeout_ms?:int,headers?:array<string,string>}>
 */
function normalizeScenarios(mixed $raw): array
{
    $rows = is_array($raw) ? $raw : [];
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        $url = trim((string) ($row['url'] ?? ''));
        if ($id === '' || $url === '') {
            continue;
        }

        $headers = [];
        if (is_array($row['headers'] ?? null)) {
            foreach ($row['headers'] as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                $headers[$key] = (string) $value;
            }
        }

        $entry = [
            'id' => $id,
            'label' => trim((string) ($row['label'] ?? $id)),
            'system' => trim((string) ($row['system'] ?? $id)),
            'url' => $url,
        ];

        foreach (['warmup_requests', 'requests', 'concurrency', 'timeout_ms'] as $field) {
            if (isset($row[$field])) {
                $entry[$field] = max($field === 'warmup_requests' ? 0 : 1, (int) $row[$field]);
            }
        }
        if ($headers !== []) {
            $entry['headers'] = $headers;
        }

        $out[] = $entry;
    }

    return $out;
}

/**
 * @param array<string, mixed> $scenario
 * @param array<string, mixed> $defaults
 * @return array<string, mixed>
 */
function mergeScenarioDefaults(array $scenario, array $defaults): array
{
    $effective = $defaults;
    foreach (['warmup_requests', 'requests', 'concurrency', 'timeout_ms'] as $field) {
        if (isset($scenario[$field])) {
            $effective[$field] = (int) $scenario[$field];
        }
    }

    $scenarioHeaders = is_array($scenario['headers'] ?? null) ? $scenario['headers'] : [];
    $effective['headers'] = array_merge(
        is_array($defaults['headers'] ?? null) ? $defaults['headers'] : [],
        $scenarioHeaders
    );

    return $effective;
}

/**
 * @param array<string, string> $headers
 */
function runWarmup(string $url, int $requests, int $timeoutMs, array $headers): void
{
    if ($requests <= 0) {
        return;
    }

    for ($i = 0; $i < $requests; $i++) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed during warmup.');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutMs);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, min($timeoutMs, 3000));
        curl_setopt($ch, CURLOPT_HTTPHEADER, formatHeaders($headers));
        curl_exec($ch);
        $ch = null;
    }
}

/**
 * @param array<string, string> $headers
 * @return array{ok:bool,status:int,errno:int,error:string}
 */
function preflightRequest(string $url, int $timeoutMs, array $headers): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed during preflight.');
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutMs);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, min($timeoutMs, 3000));
    curl_setopt($ch, CURLOPT_HTTPHEADER, formatHeaders($headers));
    curl_exec($ch);

    $errno = curl_errno($ch);
    $error = trim((string) curl_error($ch));
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ch = null;

    return [
        'ok' => $errno === 0 && $status >= 200 && $status < 300,
        'status' => $status,
        'errno' => $errno,
        'error' => $error,
    ];
}

/**
 * @param array<string, string> $headers
 * @return array<string, mixed>
 */
function runRound(string $url, int $requests, int $concurrency, int $timeoutMs, array $headers): array
{
    $mh = curl_multi_init();
    if ($mh === false) {
        throw new RuntimeException('curl_multi_init failed.');
    }

    $inFlight = [];
    $latencies = [];
    $errors = 0;
    $success = 0;
    $bytes = 0;
    $httpStatus = [];
    $errorTypes = [];
    $scheduled = 0;
    $completed = 0;
    $wallStart = microtime(true);

    while ($completed < $requests) {
        while ($scheduled < $requests && count($inFlight) < $concurrency) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('curl_init failed.');
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutMs);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, min($timeoutMs, 3000));
            curl_setopt($ch, CURLOPT_HTTPHEADER, formatHeaders($headers));

            $handleId = (string) spl_object_id($ch);
            $inFlight[$handleId] = [
                'handle' => $ch,
                'start' => microtime(true),
            ];

            curl_multi_add_handle($mh, $ch);
            $scheduled++;
        }

        do {
            $multiExec = curl_multi_exec($mh, $running);
        } while ($multiExec === CURLM_CALL_MULTI_PERFORM);

        if ($running > 0) {
            $selected = curl_multi_select($mh, 1.0);
            if ($selected === -1) {
                usleep(1000);
            }
        }

        while (is_array($info = curl_multi_info_read($mh)) && isset($info['handle'])) {
            $ch = $info['handle'];
            $handleId = (string) spl_object_id($ch);
            $meta = $inFlight[$handleId] ?? null;
            unset($inFlight[$handleId]);

            $latency = (microtime(true) - (float) ($meta['start'] ?? microtime(true))) * 1000;
            $latencies[] = $latency;

            $errno = curl_errno($ch);
            $error = trim((string) curl_error($ch));
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $content = (string) curl_multi_getcontent($ch);
            $bytes += strlen($content);

            $statusKey = 'status_' . ($status > 0 ? (string) $status : '0');
            $httpStatus[$statusKey] = ($httpStatus[$statusKey] ?? 0) + 1;

            if ($errno !== 0 || $status < 200 || $status >= 300) {
                $errors++;
                $label = $errno !== 0 ? "curl:{$errno}" : "http:{$status}";
                if ($error !== '' && $errno !== 0) {
                    $label .= ':' . $error;
                }
                $errorTypes[$label] = ($errorTypes[$label] ?? 0) + 1;
            } else {
                $success++;
            }

            curl_multi_remove_handle($mh, $ch);
            $completed++;
        }
    }

    curl_multi_close($mh);

    $wallSeconds = max(0.0001, microtime(true) - $wallStart);
    sort($latencies, SORT_NUMERIC);
    $total = count($latencies);

    return [
        'requests' => $requests,
        'completed' => $completed,
        'success' => $success,
        'errors' => $errors,
        'success_rate' => round(($success / max(1, $completed)) * 100, 4),
        'error_rate' => round(($errors / max(1, $completed)) * 100, 4),
        'rps' => round($completed / $wallSeconds, 4),
        'duration_seconds' => round($wallSeconds, 4),
        'bytes' => $bytes,
        'avg_ms' => round($total > 0 ? array_sum($latencies) / $total : 0.0, 4),
        'min_ms' => round($total > 0 ? $latencies[0] : 0.0, 4),
        'max_ms' => round($total > 0 ? $latencies[$total - 1] : 0.0, 4),
        'p50_ms' => round(percentile($latencies, 0.50), 4),
        'p90_ms' => round(percentile($latencies, 0.90), 4),
        'p95_ms' => round(percentile($latencies, 0.95), 4),
        'p99_ms' => round(percentile($latencies, 0.99), 4),
        'http_status' => $httpStatus,
        'error_types' => $errorTypes,
    ];
}

/**
 * @param array<int, float> $values
 */
function percentile(array $values, float $percentile): float
{
    if ($values === []) {
        return 0.0;
    }

    $index = (int) floor((count($values) - 1) * $percentile);
    $index = max(0, min(count($values) - 1, $index));
    return (float) $values[$index];
}

/**
 * @param array<int, array<string, mixed>> $rounds
 * @return array<string, mixed>
 */
function summarizeRounds(array $rounds): array
{
    $keys = [
        'requests',
        'completed',
        'success',
        'errors',
        'success_rate',
        'error_rate',
        'rps',
        'duration_seconds',
        'bytes',
        'avg_ms',
        'min_ms',
        'max_ms',
        'p50_ms',
        'p90_ms',
        'p95_ms',
        'p99_ms',
    ];

    $summary = [];
    foreach ($keys as $key) {
        $series = [];
        foreach ($rounds as $round) {
            if (isset($round[$key]) && is_numeric($round[$key])) {
                $series[] = (float) $round[$key];
            }
        }
        if ($series === []) {
            continue;
        }
        sort($series, SORT_NUMERIC);
        $summary[$key] = round($series[(int) floor((count($series) - 1) / 2)], 4);
    }

    $summary['round_count'] = count($rounds);
    return $summary;
}

/**
 * @param array<int, array<string, mixed>> $rounds
 * @return array<string, int>
 */
function aggregateStatusCounts(array $rounds): array
{
    $counts = [];
    foreach ($rounds as $round) {
        $statusMap = is_array($round['http_status'] ?? null) ? $round['http_status'] : [];
        foreach ($statusMap as $status => $value) {
            if (!is_string($status) || !is_numeric($value)) {
                continue;
            }
            $counts[$status] = ($counts[$status] ?? 0) + (int) $value;
        }
    }
    ksort($counts, SORT_STRING);
    return $counts;
}

/**
 * @param array<int, array<string, mixed>> $results
 */
function printSummary(array $results): void
{
    echo str_pad('Scenario', 22)
        . str_pad('System', 14)
        . str_pad('Status', 10)
        . str_pad('RPS', 12)
        . str_pad('P95 (ms)', 12)
        . str_pad('Err %', 10)
        . "\n";
    echo str_repeat('-', 80) . "\n";

    foreach ($results as $result) {
        $status = (string) ($result['status'] ?? 'error');
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];

        echo str_pad((string) ($result['id'] ?? ''), 22)
            . str_pad((string) ($result['system'] ?? ''), 14)
            . str_pad($status, 10)
            . str_pad($summary !== [] ? number_format((float) ($summary['rps'] ?? 0), 2) : '-', 12)
            . str_pad($summary !== [] ? number_format((float) ($summary['p95_ms'] ?? 0), 1) : '-', 12)
            . str_pad($summary !== [] ? number_format((float) ($summary['error_rate'] ?? 0), 2) : '-', 10)
            . "\n";

        if ($status !== 'ok') {
            $error = (string) ($result['error'] ?? 'Unknown error');
            echo "  error: {$error}\n";
        }
    }
}

/**
 * @param array<string, string> $headers
 * @return array<int, string>
 */
function formatHeaders(array $headers): array
{
    $out = [];
    foreach ($headers as $key => $value) {
        $out[] = $key . ': ' . $value;
    }
    return $out;
}

/**
 * @return array<int, string>
 */
function parseCsv(string $value): array
{
    if (trim($value) === '') {
        return [];
    }

    $parts = array_map('trim', explode(',', $value));
    $parts = array_values(array_filter($parts, static fn (string $v): bool => $v !== ''));
    return array_values(array_unique($parts));
}
