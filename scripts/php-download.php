<?php
/**
 * Background installer: download a Windows TS x86 PHP zip into <install>/phpX.Y.Z
 * Usage: php scripts/php-download.php 8.1.34
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$version = $argv[1] ?? '';
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    fwrite(STDERR, "Usage: php-download.php X.Y.Z\n");
    exit(1);
}

require dirname(__DIR__) . '/htdocs/panel/includes/bootstrap.php';

$status = php_download_status();
$url = (string)($status['url'] ?? '');
$folder = (string)($status['folder'] ?? ('php' . $version));
$sha = strtolower((string)($status['sha256'] ?? ''));
$key = (string)($status['key'] ?? '');

if ($url === '' || (string)($status['version'] ?? '') !== $version) {
    $avail = php_available_releases();
    if (!$avail['ok']) {
        php_download_write(['status' => 'error', 'percent' => 0, 'version' => $version, 'message' => $avail['message'], 'error' => $avail['message']]);
        exit(1);
    }
    $pick = null;
    foreach ($avail['releases'] as $row) {
        if ($row['version'] === $version) {
            $pick = $row;
            break;
        }
    }
    if ($pick === null) {
        php_download_write(['status' => 'error', 'percent' => 0, 'version' => $version, 'message' => 'Release not found', 'error' => 'Release not found']);
        exit(1);
    }
    $url = $pick['url'];
    $folder = $pick['folder'];
    $sha = strtolower($pick['sha256']);
    $key = $pick['key'];
}

$destDir = WEB_ROOT . '/' . $folder;
$tmpDir = WEB_ROOT . '/.php-download-tmp';
$zipPath = $tmpDir . '/' . $folder . '.zip';

if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0777, true);
}

php_download_write([
    'status' => 'downloading',
    'percent' => 1,
    'version' => $version,
    'key' => $key,
    'folder' => $folder,
    'url' => $url,
    'sha256' => $sha,
    'message' => 'Downloading ' . basename($url),
    'error' => '',
]);

$fp = @fopen($zipPath, 'wb');
if (!$fp) {
    php_download_write(['status' => 'error', 'percent' => 0, 'version' => $version, 'key' => $key, 'folder' => $folder, 'message' => 'Cannot create temp zip', 'error' => 'Cannot create temp zip']);
    exit(1);
}

$ok = false;
$err = '';
if (function_exists('curl_init')) {
    $progress = static function ($res, $dlTotal, $dlNow) use ($version, $key, $folder, $url, $sha): int {
        if ($dlTotal > 0) {
            $pct = max(1, min(90, (int)round(($dlNow / $dlTotal) * 90)));
            php_download_write([
                'status' => 'downloading',
                'percent' => $pct,
                'version' => $version,
                'key' => $key,
                'folder' => $folder,
                'url' => $url,
                'sha256' => $sha,
                'message' => 'Downloading… ' . $pct . '%',
                'error' => '',
            ]);
        }
        return 0;
    };
    foreach ([true, false] as $verify) {
        rewind($fp);
        ftruncate($fp, 0);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_USERAGENT => 'WebStackPanel/1.0',
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => $progress,
        ]);
        $ok = curl_exec($ch) !== false;
        $err = $ok ? '' : curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($ok && $code < 400) {
            break;
        }
        if ($ok && $code >= 400) {
            $ok = false;
            $err = 'HTTP ' . $code;
            break;
        }
        if ($verify && stripos($err, 'ssl') !== false) {
            $ok = false;
            continue;
        }
        break;
    }
} else {
    $ctx = stream_context_create(['http' => ['timeout' => 600, 'header' => "User-Agent: WebStackPanel/1.0\r\n"]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) {
        $err = 'Download failed';
    } else {
        fwrite($fp, $data);
        $ok = true;
    }
}
fclose($fp);

if (!$ok || !is_file($zipPath) || filesize($zipPath) < 1000) {
    @unlink($zipPath);
    php_download_write(['status' => 'error', 'percent' => 0, 'version' => $version, 'key' => $key, 'folder' => $folder, 'message' => 'Download failed', 'error' => $err !== '' ? $err : 'Download failed']);
    exit(1);
}

if ($sha !== '') {
    $hash = hash_file('sha256', $zipPath);
    if (!is_string($hash) || strtolower($hash) !== $sha) {
        @unlink($zipPath);
        php_download_write(['status' => 'error', 'percent' => 0, 'version' => $version, 'key' => $key, 'folder' => $folder, 'message' => 'Checksum mismatch', 'error' => 'Checksum mismatch']);
        exit(1);
    }
}

php_download_write([
    'status' => 'extracting',
    'percent' => 92,
    'version' => $version,
    'key' => $key,
    'folder' => $folder,
    'url' => $url,
    'sha256' => $sha,
    'message' => 'Extracting…',
    'error' => '',
]);

if (is_dir($destDir)) {
    // Leave existing incomplete folder aside
    @rename($destDir, $destDir . '.bak-' . date('YmdHis'));
}
@mkdir($destDir, 0777, true);

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    php_download_write(['status' => 'error', 'percent' => 0, 'version' => $version, 'key' => $key, 'folder' => $folder, 'message' => 'Cannot open zip', 'error' => 'Cannot open zip']);
    exit(1);
}
$zip->extractTo($destDir);
$zip->close();
@unlink($zipPath);

if (!is_file($destDir . '/php-cgi.exe')) {
    // Some zips nest a single folder
    $kids = @scandir($destDir) ?: [];
    foreach ($kids as $kid) {
        if ($kid === '.' || $kid === '..') {
            continue;
        }
        $nested = $destDir . '/' . $kid;
        if (is_dir($nested) && is_file($nested . '/php-cgi.exe')) {
            foreach (scandir($nested) ?: [] as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                @rename($nested . '/' . $file, $destDir . '/' . $file);
            }
            @rmdir($nested);
            break;
        }
    }
}

if (!is_file($destDir . '/php-cgi.exe')) {
    php_download_write(['status' => 'error', 'percent' => 0, 'version' => $version, 'key' => $key, 'folder' => $folder, 'message' => 'php-cgi.exe missing after extract', 'error' => 'php-cgi.exe missing after extract']);
    exit(1);
}

php_download_write([
    'status' => 'configuring',
    'percent' => 96,
    'version' => $version,
    'key' => $key,
    'folder' => $folder,
    'url' => $url,
    'sha256' => $sha,
    'message' => 'Writing php.ini…',
    'error' => '',
]);

php_prepare_ini($destDir);
ensure_php_runtime_paths();

// Refresh catalog without bootstrapping infinite loops
$versions = php_refresh_catalog(true);
if (isset($versions[$key])) {
    // Persist discovered port
    $settings = panel_settings();
    $settings['php_ports'][$key] = (int)$versions[$key]['port'];
    if (!isset(PHP_VERSIONS[$settings['default_php']])) {
        $settings['default_php'] = $key;
    }
    save_panel_settings($settings);
}

// Add php74 / localhost74 / localhost.php74 (etc.) to the Windows hosts file
$hostSync = ['ok' => false, 'message' => ''];
if (function_exists('sync_windows_hosts')) {
    $hostSync = sync_windows_hosts(load_custom_sites());
}

$hostNote = '';
if (!empty($hostSync['ok'])) {
    $hostNote = empty($hostSync['added'])
        ? ' · hosts ok'
        : ' · hosts added: ' . implode(', ', $hostSync['added']);
} elseif (($hostSync['message'] ?? '') !== '') {
    $hostNote = ' · hosts: ' . $hostSync['message'];
}

php_download_write([
    'status' => 'done',
    'percent' => 100,
    'version' => $version,
    'key' => $key,
    'folder' => $folder,
    'url' => $url,
    'sha256' => $sha,
    'message' => 'PHP ' . $version . ' installed' . $hostNote,
    'error' => '',
    'port' => isset($versions[$key]) ? (int)$versions[$key]['port'] : null,
    'hosts' => $versions[$key]['hosts'] ?? [],
    'hosts_sync' => $hostSync,
]);

exit(0);
