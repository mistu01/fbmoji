<?php

declare(strict_types=1);

function checksum(string $subpath): string
{
    $base = 317426846;
    $length = strlen($subpath);

    for ($i = 0; $i < $length; $i++) {
        $base = ($base << 5) - $base + ord($subpath[$i]);
        $base &= 0xFFFFFFFF;
    }

    return strtolower(base_convert((string) ($base & 255), 10, 16));
}

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
        'version' => 'latest',
        'concurrency' => 24,
        'timeout' => 20,
        'retries' => 2,
        'limit' => 0,
    );

    if (PHP_SAPI !== 'cli') {
        if (array_key_exists('v', $_GET)) {
            $sanitized = preg_replace('/[^0-9\.]/', '', (string) $_GET['v']);
            if ($sanitized !== '') {
                $options['version'] = $sanitized;
            }
        }

        return $options;
    }

    $argv = $_SERVER['argv'] ?? array();
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            stdout("Usage: php pull.php [--version=latest] [--concurrency=24] [--timeout=20] [--retries=2] [--limit=0]\n");
            exit(0);
        }

        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }

        list($key, $value) = explode('=', substr($arg, 2), 2);
        switch ($key) {
            case 'version':
                $sanitized = preg_replace('/[^0-9\.]/', '', $value);
                if ($sanitized !== '') {
                    $options['version'] = $sanitized;
                }
                break;

            case 'concurrency':
                $options['concurrency'] = max(1, (int) $value);
                break;

            case 'timeout':
                $options['timeout'] = max(5, (int) $value);
                break;

            case 'retries':
                $options['retries'] = max(0, (int) $value);
                break;

            case 'limit':
                $options['limit'] = max(0, (int) $value);
                break;
        }
    }

    return $options;
}

function fetchOptional(string $url, int $timeout, int $retries)
{
    $lastError = 'Unknown error';

    if (function_exists('curl_init')) {
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'fbmoji/2.0',
                CURLOPT_FAILONERROR => false,
            ));

            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($body !== false && $httpCode >= 200 && $httpCode < 300) {
                return $body;
            }

            $lastError = $curlError !== '' ? $curlError : ('HTTP ' . $httpCode);
        }

        return false;
    }

    $context = stream_context_create(array(
        'http' => array(
            'method' => 'GET',
            'timeout' => $timeout,
            'header' => "User-Agent: fbmoji/2.0\r\n",
            'ignore_errors' => true,
        ),
    ));

    for ($attempt = 0; $attempt <= $retries; $attempt++) {
        $body = @file_get_contents($url, false, $context);
        if ($body !== false) {
            return $body;
        }

        $lastError = error_get_last()['message'] ?? 'Unable to fetch resource';
    }

    return false;
}

function fetchText(string $url, int $timeout, int $retries): string
{
    $body = fetchOptional($url, $timeout, $retries);
    if ($body !== false) {
        return $body;
    }

    fail('Unable to fetch ' . $url);
}

function buildDatabase(string $data): array
{
    $stats = array(
        'unqualified' => -1,
        'minimally-qualified' => 0,
        'fully-qualified' => 1,
    );
    $db = array();
    $lines = explode("\n", $data);

    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }

        if ($line[0] === '#') {
            continue;
        }

        if (!str_contains($line, ';') || !str_contains($line, '#')) {
            continue;
        }

        $code = explode(' ', trim(substr($line, 0, strpos($line, ';'))));
        $status = trim(substr($line, strpos($line, ';') + 1, strpos($line, '#') - strpos($line, ';') - 1));
        if (!array_key_exists($status, $stats)) {
            continue;
        }

        $details = explode(' ', trim(substr($line, strpos($line, '#') + 1)), 3);
        if (count($details) !== 3 || $details[1] === '' || $details[1][0] !== 'E') {
            continue;
        }

        $version = substr($details[1], 1);
        $name = $details[2];
        $codeStd = strtolower(implode('_', $code));

        if ($stats[$status] < 1) {
            $db[$codeStd] = array(
                'target' => $name,
                'status' => $status,
            );
            continue;
        }

        $db[$codeStd] = array(
            'name' => $name,
            'ver' => $version,
        );
    }

    foreach ($db as $key => $value) {
        if (!array_key_exists('target', $value)) {
            continue;
        }

        $found = false;
        foreach ($db as $candidateKey => $candidateValue) {
            if (!array_key_exists('name', $candidateValue)) {
                continue;
            }

            if ($candidateValue['name'] !== $value['target']) {
                continue;
            }

            $db[$key]['target'] = $candidateKey;
            if (!array_key_exists('aliases', $db[$candidateKey])) {
                $db[$candidateKey]['aliases'] = array();
            }
            $db[$candidateKey]['aliases'][] = $key;
            $found = true;
            break;
        }

        if (!$found) {
            unset($db[$key]);
        }
    }

    return $db;
}

function buildJobs(array $db, int $limit): array
{
    $jobs = array();
    $total = count($db);
    $index = 0;

    foreach ($db as $key => $value) {
        $index++;
        if (!array_key_exists('name', $value)) {
            continue;
        }

        $variants = array();
        $variants[] = ltrim((string) $key, '0');

        if (array_key_exists('aliases', $value)) {
            foreach ($value['aliases'] as $alias) {
                $variants[] = ltrim((string) $alias, '0');
            }
        }

        $variants = array_values(array_unique(array_filter($variants, static function ($candidate) {
            return $candidate !== '';
        })));

        $jobs[] = array(
            'name' => $value['name'],
            'ver' => $value['ver'],
            'pct' => (int) floor(($index / $total) * 100),
            'candidates' => $variants,
            'candidateIndex' => 0,
            'retryIndex' => 0,
            'started' => false,
            'done' => false,
            'success' => false,
        );

        if ($limit > 0 && count($jobs) >= $limit) {
            break;
        }
    }

    return $jobs;
}

function makeEmojiUrl(string $filename): string
{
    $subpath = '3/128/' . $filename . '.png';
    return 'https://static.xx.fbcdn.net/images/emoji.php/v9/t' . checksum($subpath) . '/' . $subpath;
}

function saveFile(string $path, string $contents): bool
{
    $targetHash = hash('sha256', $contents);
    if (is_file($path)) {
        $existingHash = @hash_file('sha256', $path);
        if ($existingHash !== false && hash_equals($existingHash, $targetHash)) {
            return true;
        }
    }

    $tmpPath = $path . '.tmp-' . bin2hex(random_bytes(4));
    for ($attempt = 0; $attempt < 10; $attempt++) {
        if (@file_put_contents($tmpPath, $contents) !== false) {
            if (@rename($tmpPath, $path)) {
                return true;
            }

            if ((!is_file($path) || @unlink($path)) && @rename($tmpPath, $path)) {
                return true;
            }
        }

        @unlink($tmpPath);
        usleep(200000);
    }

    return false;
}

function createHandle(string $url, int $timeout)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'fbmoji/2.0',
        CURLOPT_FAILONERROR => false,
    ));

    return $ch;
}

function downloadSequential(array &$jobs, array $options, string $outDir, string $safeSkip): array
{
    stdout("curl_multi is unavailable, falling back to sequential downloads.\n");

    $failures = array();
    foreach ($jobs as &$job) {
        $downloaded = false;
        foreach ($job['candidates'] as $candidate) {
            $url = makeEmojiUrl($candidate);
            for ($attempt = 0; $attempt <= $options['retries']; $attempt++) {
                $body = fetchOptional($url, $options['timeout'], 0);
                if ($body !== false && $body !== '') {
                    if (!saveFile($outDir . DIRECTORY_SEPARATOR . $candidate . '.png', $body)) {
                        fail('Unable to write png/' . $candidate . '.png');
                    }
                    stdout('[' . $job['pct'] . '%] Downloaded ' . $job['name'] . ' -> ' . $candidate . ".png\n");
                    $downloaded = true;
                    break 2;
                }
            }
        }

        if ($downloaded) {
            continue;
        }

        if ($job['ver'] === $safeSkip) {
            stdout('[' . $job['pct'] . '%] Skipped latest-only emoji: ' . $job['name'] . "\n");
            continue;
        }

        $failures[] = $job['name'];
        stdout('[' . $job['pct'] . '%] Failed to download ' . $job['name'] . "\n");
    }

    return $failures;
}

function downloadParallel(array &$jobs, array $options, string $outDir, string $safeSkip): array
{
    if (!function_exists('curl_multi_init')) {
        return downloadSequential($jobs, $options, $outDir, $safeSkip);
    }

    $multiHandle = curl_multi_init();
    $pending = range(0, count($jobs) - 1);
    $active = array();
    $failures = array();
    $concurrency = max(1, min($options['concurrency'], count($jobs)));

    while ($pending !== array() || $active !== array()) {
        while (count($active) < $concurrency && $pending !== array()) {
            $jobIndex = array_shift($pending);
            $job = &$jobs[$jobIndex];

            if ($job['done']) {
                unset($job);
                continue;
            }

            if (!$job['started']) {
                stdout('[' . $job['pct'] . '%] Queued ' . $job['name'] . "\n");
                $job['started'] = true;
            }

            $candidate = $job['candidates'][$job['candidateIndex']];
            $handle = createHandle(makeEmojiUrl($candidate), $options['timeout']);
            curl_multi_add_handle($multiHandle, $handle);

            $active[(int) $handle] = array(
                'handle' => $handle,
                'jobIndex' => $jobIndex,
                'candidate' => $candidate,
            );

            unset($job);
        }

        do {
            $status = curl_multi_exec($multiHandle, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        if ($running > 0) {
            curl_multi_select($multiHandle, 1.0);
        }

        while (($info = curl_multi_info_read($multiHandle)) !== false) {
            $handle = $info['handle'];
            $meta = $active[(int) $handle];
            unset($active[(int) $handle]);

            $job = &$jobs[$meta['jobIndex']];
            $body = curl_multi_getcontent($handle);
            $httpCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($handle);

            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);

            $success = $info['result'] === CURLE_OK && $httpCode >= 200 && $httpCode < 300 && $body !== false && $body !== '';
            if ($success) {
                $target = $outDir . DIRECTORY_SEPARATOR . $meta['candidate'] . '.png';
                if (!saveFile($target, $body)) {
                    fail('Unable to write png/' . $meta['candidate'] . '.png');
                }

                $job['done'] = true;
                $job['success'] = true;
                stdout('[' . $job['pct'] . '%] Downloaded ' . $job['name'] . ' -> ' . $meta['candidate'] . ".png\n");
                unset($job);
                continue;
            }

            $job['retryIndex']++;
            if ($job['retryIndex'] <= $options['retries']) {
                $pending[] = $meta['jobIndex'];
                unset($job);
                continue;
            }

            $job['retryIndex'] = 0;
            $job['candidateIndex']++;
            if ($job['candidateIndex'] < count($job['candidates'])) {
                $pending[] = $meta['jobIndex'];
                unset($job);
                continue;
            }

            $job['done'] = true;
            $reason = $curlError !== '' ? $curlError : ('HTTP ' . $httpCode);

            if ($job['ver'] === $safeSkip) {
                stdout('[' . $job['pct'] . '%] Skipped latest-only emoji: ' . $job['name'] . ' (' . $reason . ')' . "\n");
            } else {
                $failures[] = $job['name'] . ' (' . $reason . ')';
                stdout('[' . $job['pct'] . '%] Failed ' . $job['name'] . ' (' . $reason . ')' . "\n");
            }

            unset($job);
        }
    }

    curl_multi_close($multiHandle);

    return $failures;
}

set_time_limit(0);

if (PHP_SAPI !== 'cli') {
    header('content-type: text/plain');
}

$options = parseOptions();
$outDir = __DIR__ . DIRECTORY_SEPARATOR . 'png';

if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fail('Unable to create png output directory.');
}

$emojiTestUrl = 'https://unicode.org/Public/emoji/' . $options['version'] . '/emoji-test.txt';
$data = fetchText($emojiTestUrl, $options['timeout'], $options['retries']);
$safeSkip = $options['version'];
if (preg_match('/^# Version:\s+([0-9.]+)/m', $data, $matches) === 1) {
    $safeSkip = $matches[1];
}

$db = buildDatabase($data);
$jobs = buildJobs($db, $options['limit']);

if ($jobs === array()) {
    fail('No emoji entries were parsed from ' . $emojiTestUrl);
}

stdout('Preparing to download ' . count($jobs) . " emoji assets.\n");
$failures = downloadParallel($jobs, $options, $outDir, $safeSkip);

if ($failures !== array()) {
    stdout('Completed with ' . count($failures) . " failures.\n");
    foreach ($failures as $failure) {
        stdout(' - ' . $failure . "\n");
    }
    exit(1);
}

stdout("Complete!\n");
