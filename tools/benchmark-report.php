#!/usr/bin/env php
<?php

declare(strict_types=1);

$options = parseOptions($argv);
$root = resolveRoot($options['root'] ?? null);
$input = resolveInputPath($root, $options['input'] ?? null);
$out = isset($options['out']) ? resolvePath($root, (string) $options['out']) : null;

if (!is_file($input)) {
    fwrite(STDERR, "Benchmark input file not found: {$input}\n");
    exit(1);
}

$raw = (string) file_get_contents($input);
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "Benchmark input JSON is invalid: {$input}\n");
    exit(1);
}

$markdown = renderReport($decoded, $input);

if ($out !== null) {
    if (!is_dir(dirname($out))) {
        mkdir(dirname($out), 0775, true);
    }
    file_put_contents($out, $markdown);
    echo "Benchmark markdown report written: {$out}\n";
} else {
    echo $markdown;
}

exit(0);

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function parseOptions(array $argv): array
{
    $options = [];
    foreach ($argv as $index => $arg) {
        if ($index === 0 || !str_starts_with($arg, '--')) {
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

function resolveInputPath(string $root, ?string $input): string
{
    if ($input !== null && trim($input) !== '') {
        return resolvePath($root, trim($input));
    }

    $glob = glob($root . '/benchmarks/results/*.json') ?: [];
    if ($glob === []) {
        fwrite(STDERR, "No benchmark JSON files found in {$root}/benchmarks/results\n");
        exit(1);
    }
    sort($glob, SORT_STRING);
    return $glob[count($glob) - 1];
}

/**
 * @param array<string, mixed> $payload
 */
function renderReport(array $payload, string $inputPath): string
{
    $generatedAt = (string) ($payload['generated_at'] ?? '');
    $rounds = (int) ($payload['rounds'] ?? 1);
    $maxErrorRate = (float) ($payload['max_error_rate'] ?? 5.0);
    $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];

    $ok = [];
    $failed = [];

    foreach ($results as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (($row['status'] ?? 'error') === 'ok') {
            $ok[] = $row;
        } else {
            $failed[] = $row;
        }
    }

    usort($ok, static function (array $a, array $b): int {
        $aP95 = (float) (($a['summary']['p95_ms'] ?? 999999));
        $bP95 = (float) (($b['summary']['p95_ms'] ?? 999999));
        if ($aP95 < $bP95) {
            return -1;
        }
        if ($aP95 > $bP95) {
            return 1;
        }
        $aRps = (float) (($a['summary']['rps'] ?? 0));
        $bRps = (float) (($b['summary']['rps'] ?? 0));
        return $aRps < $bRps ? 1 : -1;
    });

    $md = '';
    $md .= "# Benchmark Report\n\n";
    $md .= "- Generated: " . ($generatedAt !== '' ? $generatedAt : date('c')) . "\n";
    $md .= "- Input: `{$inputPath}`\n";
    $md .= "- Rounds per scenario: {$rounds}\n";
    $md .= "- Max error rate threshold: " . number_format($maxErrorRate, 2) . "%\n\n";

    if ($ok !== []) {
        $md .= "## Ranking (lower p95 is better)\n\n";
        $md .= "| Rank | Scenario | System | RPS | Avg ms | p95 ms | Error % |\n";
        $md .= "|---:|---|---|---:|---:|---:|---:|\n";
        foreach ($ok as $index => $row) {
            $summary = is_array($row['summary'] ?? null) ? $row['summary'] : [];
            $md .= sprintf(
                "| %d | %s | %s | %.2f | %.2f | %.2f | %.2f |\n",
                $index + 1,
                escapeMd((string) ($row['id'] ?? '')),
                escapeMd((string) ($row['system'] ?? '')),
                (float) ($summary['rps'] ?? 0),
                (float) ($summary['avg_ms'] ?? 0),
                (float) ($summary['p95_ms'] ?? 0),
                (float) ($summary['error_rate'] ?? 0)
            );
        }
        $md .= "\n";
    } else {
        $md .= "## Ranking\n\nNo successful scenarios.\n\n";
    }

    if ($failed !== []) {
        $md .= "## Failed scenarios\n\n";
        foreach ($failed as $row) {
            $md .= "- `" . escapeMd((string) ($row['id'] ?? 'unknown')) . "`: "
                . escapeMd((string) ($row['error'] ?? 'Unknown error')) . "\n";
        }
        $md .= "\n";
    }

    $md .= "## Method notes\n\n";
    $md .= "- Median values are used for summary across rounds.\n";
    $md .= "- For fair comparison, use equivalent page types and warm caches for all systems.\n";
    $md .= "- Run benchmarks on the same machine/network conditions.\n";

    return $md;
}

function escapeMd(string $value): string
{
    return str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], $value);
}
