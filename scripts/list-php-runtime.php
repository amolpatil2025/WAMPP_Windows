<?php
/** Print "dir\tport\tkey" lines for every discovered PHP (CLI helper for VBS). */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$file = dirname(__DIR__) . '/htdocs/panel/data/php-runtime.json';
$only = $argv[1] ?? ''; // optional version key, or "default"
if (!is_file($file)) {
    exit(1);
}
$data = json_decode((string)file_get_contents($file), true);
if (!is_array($data) || !isset($data['versions']) || !is_array($data['versions'])) {
    exit(1);
}
if ($only === 'default') {
    $key = (string)($data['default'] ?? '');
    if ($key === '' || !isset($data['versions'][$key])) {
        exit(1);
    }
    $meta = $data['versions'][$key];
    echo $meta['dir'] . "\t" . (int)$meta['port'] . "\t" . $key . "\n";
    exit(0);
}
if ($only !== '') {
    if (!isset($data['versions'][$only])) {
        exit(1);
    }
    $meta = $data['versions'][$only];
    echo $meta['dir'] . "\t" . (int)$meta['port'] . "\t" . $only . "\n";
    exit(0);
}
foreach ($data['versions'] as $key => $meta) {
    echo $meta['dir'] . "\t" . (int)$meta['port'] . "\t" . $key . "\n";
}
