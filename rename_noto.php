<?php

declare(strict_types=1);

const EMOJI_VS16 = 'fe0f';

function stdout(string $message): void
{
    echo $message;

    if (PHP_SAPI === 'cli') {
        flush();
    }
}

function fail(string $message, int $code = 1): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

function parseOptions(): array
{
    $options = array(
        'source' => __DIR__ . DIRECTORY_SEPARATOR . 'png',
        'dest' => __DIR__ . DIRECTORY_SEPARATOR . 'png-noto',
        'mode' => 'copy',
        'prefix' => 'emoji_u',
        'keep_vs' => false,
        'overwrite' => false,
        'dry_run' => false,
    );

    if (PHP_SAPI !== 'cli') {
        fail('This script is intended to run from the CLI.');
    }

    foreach (array_slice($_SERVER['argv'] ?? array(), 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            stdout(
                "Usage: php rename_noto.php [--source=png] [--dest=png-noto] [--mode=copy|rename] " .
                "[--prefix=emoji_u] [--keep-vs] [--overwrite] [--dry-run]\n"
            );
            exit(0);
        }

        if ($arg === '--keep-vs') {
            $options['keep_vs'] = true;
            continue;
        }

        if ($arg === '--overwrite') {
            $options['overwrite'] = true;
            continue;
        }

        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }

        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }

        list($key, $value) = explode('=', substr($arg, 2), 2);
        switch ($key) {
            case 'source':
                if ($value !== '') {
                    $options['source'] = $value;
                }
                break;

            case 'dest':
                if ($value !== '') {
                    $options['dest'] = $value;
                }
                break;

            case 'mode':
                if (!in_array($value, array('copy', 'rename'), true)) {
                    fail('Invalid mode "' . $value . '". Expected copy or rename.');
                }
                $options['mode'] = $value;
                break;

            case 'prefix':
                $options['prefix'] = $value;
                break;
        }
    }

    $options['source'] = normalizePath($options['source']);
    $options['dest'] = normalizePath($options['dest']);

    return $options;
}

function normalizePath(string $path): string
{
    if ($path === '') {
        return $path;
    }

    if (preg_match('/^(?:[A-Za-z]:[\\\\\\/]|\\\\\\\\|\/)/', $path) === 1) {
        return $path;
    }

    return __DIR__ . DIRECTORY_SEPARATOR . $path;
}

function ensureDirectory(string $path, bool $dryRun): void
{
    if (is_dir($path)) {
        return;
    }

    if ($dryRun) {
        return;
    }

    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        fail('Unable to create directory: ' . $path);
    }
}

function sourceToSequence(string $basename, bool $keepVs): array
{
    $parts = explode('_', strtolower($basename));
    $normalized = array();

    foreach ($parts as $part) {
        if ($part === '') {
            fail('Invalid filename segment in ' . $basename);
        }

        if (!ctype_xdigit($part)) {
            fail('Non-hex filename segment "' . $part . '" in ' . $basename);
        }

        $part = ltrim($part, '0');
        if ($part === '') {
            $part = '0';
        }

        $part = str_pad($part, max(4, strlen($part)), '0', STR_PAD_LEFT);
        if (!$keepVs && $part === EMOJI_VS16) {
            continue;
        }

        $normalized[] = $part;
    }

    if ($normalized === array()) {
        fail('No codepoints left after normalization for ' . $basename);
    }

    return $normalized;
}

function buildTargetBasename(string $sourceBasename, string $prefix, bool $keepVs): string
{
    $sequence = sourceToSequence($sourceBasename, $keepVs);
    return $prefix . implode('_', $sequence);
}

function fileHash(string $path): string
{
    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        fail('Unable to hash file: ' . $path);
    }

    return $hash;
}

function compareCandidates(array $a, array $b): int
{
    if ($a['has_vs'] !== $b['has_vs']) {
        return $a['has_vs'] ? 1 : -1;
    }

    $lengthComparison = strlen($a['basename']) <=> strlen($b['basename']);
    if ($lengthComparison !== 0) {
        return $lengthComparison;
    }

    return strcmp($a['basename'], $b['basename']);
}

function buildPlan(array $options): array
{
    if (!is_dir($options['source'])) {
        fail('Source directory does not exist: ' . $options['source']);
    }

    $files = glob($options['source'] . DIRECTORY_SEPARATOR . '*.png');
    if ($files === false) {
        fail('Unable to enumerate source PNG files.');
    }

    sort($files, SORT_STRING);

    $groups = array();
    foreach ($files as $file) {
        $basename = pathinfo($file, PATHINFO_FILENAME);
        $targetBasename = buildTargetBasename($basename, $options['prefix'], $options['keep_vs']);
        $groups[$targetBasename][] = array(
            'source' => $file,
            'basename' => $basename,
            'has_vs' => str_contains(strtolower($basename), EMOJI_VS16),
        );
    }

    $plan = array();
    $conflicts = array();
    $skippedIdentical = 0;

    foreach ($groups as $targetBasename => $candidates) {
        usort($candidates, 'compareCandidates');
        $winner = $candidates[0];
        $winner['target'] = $options['dest'] . DIRECTORY_SEPARATOR . $targetBasename . '.png';
        $plan[] = $winner;

        if (count($candidates) === 1) {
            continue;
        }

        $winnerHash = fileHash($winner['source']);
        for ($i = 1; $i < count($candidates); $i++) {
            $candidate = $candidates[$i];
            $candidateHash = fileHash($candidate['source']);
            if (hash_equals($winnerHash, $candidateHash)) {
                $skippedIdentical++;
                continue;
            }

            $conflicts[] = array(
                'target' => $targetBasename . '.png',
                'winner' => basename($winner['source']),
                'skipped' => basename($candidate['source']),
            );
        }
    }

    return array($plan, $conflicts, $skippedIdentical, count($files));
}

function sameDirectory(string $a, string $b): bool
{
    $left = rtrim(str_replace('/', DIRECTORY_SEPARATOR, $a), DIRECTORY_SEPARATOR);
    $right = rtrim(str_replace('/', DIRECTORY_SEPARATOR, $b), DIRECTORY_SEPARATOR);

    return strcasecmp($left, $right) === 0;
}

function executePlan(array $plan, array $options): array
{
    ensureDirectory($options['dest'], $options['dry_run']);

    if ($options['mode'] === 'rename' && sameDirectory($options['source'], $options['dest'])) {
        fail('In-place rename is not supported. Use a different --dest or run in --mode=copy.');
    }

    $written = 0;
    $unchanged = 0;

    foreach ($plan as $item) {
        $target = $item['target'];
        $action = $options['mode'];

        if (is_file($target)) {
            $sourceHash = fileHash($item['source']);
            $targetHash = fileHash($target);

            if (hash_equals($sourceHash, $targetHash)) {
                $unchanged++;
                stdout('Unchanged ' . basename($target) . PHP_EOL);
                continue;
            }

            if (!$options['overwrite']) {
                fail('Target already exists with different contents: ' . $target . '. Re-run with --overwrite.');
            }
        }

        stdout(strtoupper($action) . ' ' . basename($item['source']) . ' -> ' . basename($target) . PHP_EOL);
        if ($options['dry_run']) {
            $written++;
            continue;
        }

        if ($options['mode'] === 'copy') {
            if (!copy($item['source'], $target)) {
                fail('Unable to copy ' . $item['source'] . ' to ' . $target);
            }
        } else {
            if (is_file($target) && $options['overwrite'] && !unlink($target)) {
                fail('Unable to remove existing target: ' . $target);
            }

            if (!rename($item['source'], $target)) {
                fail('Unable to rename ' . $item['source'] . ' to ' . $target);
            }
        }

        $written++;
    }

    return array($written, $unchanged);
}

$options = parseOptions();
list($plan, $conflicts, $skippedIdentical, $sourceCount) = buildPlan($options);

stdout('Source PNGs: ' . $sourceCount . PHP_EOL);
stdout('Unique Noto names: ' . count($plan) . PHP_EOL);
if ($options['keep_vs']) {
    stdout("Mode: preserving fe0f in target filenames.\n");
} else {
    stdout("Mode: stripping fe0f to match Noto image filenames.\n");
}

if ($conflicts !== array()) {
    stdout('Conflicts after Noto normalization: ' . count($conflicts) . PHP_EOL);
    foreach ($conflicts as $conflict) {
        stdout(
            'Skipping ' . $conflict['skipped'] .
            ' because it collides with ' . $conflict['winner'] .
            ' at ' . $conflict['target'] . PHP_EOL
        );
    }
}

if ($skippedIdentical > 0) {
    stdout('Identical duplicates merged: ' . $skippedIdentical . PHP_EOL);
}

list($written, $unchanged) = executePlan($plan, $options);
stdout('Written: ' . $written . PHP_EOL);
stdout('Unchanged: ' . $unchanged . PHP_EOL);
stdout("Complete!\n");
