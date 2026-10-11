<?php
declare(strict_types=1);

const PHP_RUNTIME_FILE = PANEL_ROOT . '/data/php-runtime.json';
const PHP_DOWNLOAD_FILE = PANEL_ROOT . '/data/php-download.json';
const PHP_VERSIONS_VHOST = APACHE_ROOT . '/conf/extra/httpd-php-versions.conf';
const PHP_RELEASES_URL = 'https://windows.php.net/downloads/releases/releases.json';
const PHP_DOWNLOAD_BASE = 'https://windows.php.net/downloads/releases/';

/**
 * Scan <install>/php* folders that contain php-cgi.exe and build the version map.
 * Key = major.minor (e.g. 8.0). Highest patch wins when several folders share a key.
 */
function discover_php_versions(): array
{
    $found = [];
    $entries = @scandir(WEB_ROOT);
    if ($entries === false) {
        return [];
    }
    $settings = panel_settings_raw();
    $portMap = is_array($settings['php_ports'] ?? null) ? $settings['php_ports'] : [];
    $usedPorts = [];

    foreach ($entries as $name) {
        if (!preg_match('/^php(\d+)\.(\d+)(?:\.(\d+))?$/i', $name, $m)) {
            continue;
        }
        $dir = WEB_ROOT . '/' . $name;
        if (!is_dir($dir) || !is_file($dir . '/php-cgi.exe')) {
            continue;
        }
        $major = (int)$m[1];
        $minor = (int)$m[2];
        $patch = isset($m[3]) ? (int)$m[3] : 0;
        $key = $major . '.' . $minor;
        $full = $major . '.' . $minor . '.' . $patch;
        if (isset($found[$key])) {
            $prev = $found[$key]['_patch'];
            if ($patch < $prev) {
                continue;
            }
        }
        $hosts = php_version_hosts($key);
        $port = php_assign_port($key, $portMap, $usedPorts);
        $usedPorts[$port] = true;
        $found[$key] = [
            'label' => 'PHP ' . $full,
            'version' => $full,
            'key' => $key,
            'dir' => $dir,
            'folder' => $name,
            'port' => $port,
            'hosts' => $hosts,
            'server_name' => $hosts[0],
            '_patch' => $patch,
        ];
    }

    uasort($found, static function (array $a, array $b): int {
        return version_compare($a['version'], $b['version']);
    });

    $out = [];
    foreach ($found as $key => $meta) {
        unset($meta['_patch']);
        $out[$key] = $meta;
    }
    return $out;
}

/** Hostnames without dots: 7.4 → php74 · localhost74 · localhost.php74 */
function php_version_hosts(string $key): array
{
    [$maj, $min] = array_map('intval', explode('.', $key . '.0'));
    $slug = $maj . $min; // 74, 80, 84 — never use a dotted ServerName
    return ['php' . $slug, 'localhost' . $slug, 'localhost.php' . $slug];
}

function php_log_id(string $key): string
{
    return 'php' . str_replace('.', '', $key);
}

function php_assign_port(string $key, array $portMap, array $usedPorts): int
{
    if (isset($portMap[$key])) {
        $p = (int)$portMap[$key];
        if ($p >= 1024 && $p <= 65535 && !isset($usedPorts[$p])) {
            return $p;
        }
    }
    [$maj, $min] = array_map('intval', explode('.', $key . '.0'));
    $preferred = 9000 + ($maj * 10) + $min;
    if ($preferred >= 1024 && $preferred <= 65535 && !isset($usedPorts[$preferred])) {
        return $preferred;
    }
    for ($p = 9070; $p <= 9199; $p++) {
        if (!isset($usedPorts[$p])) {
            return $p;
        }
    }
    return $preferred;
}

/** Settings without depending on PHP_VERSIONS being defined yet. */
function panel_settings_raw(): array
{
    $defaults = [
        'default_php' => '8.4',
        'php_ports' => [],
        'db' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'user' => 'root',
            'pass' => 'root',
        ],
    ];
    if (!is_file(SETTINGS_FILE)) {
        return $defaults;
    }
    $data = json_decode((string)@file_get_contents(SETTINGS_FILE), true);
    if (!is_array($data)) {
        return $defaults;
    }
    $out = array_merge($defaults, $data);
    if (!is_array($out['php_ports'] ?? null)) {
        $out['php_ports'] = [];
    }
    if (!is_array($out['db'] ?? null)) {
        $out['db'] = $defaults['db'];
    } else {
        $out['db'] = array_merge($defaults['db'], $out['db']);
    }
    return $out;
}

function write_php_runtime_file(array $versions): void
{
    $dir = dirname(PHP_RUNTIME_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $default = panel_settings_raw()['default_php'] ?? '';
    if ($default === '' || !isset($versions[$default])) {
        $keys = array_keys($versions);
        $default = $keys ? (string)end($keys) : '';
    }
    $payload = [
        'default' => $default,
        'updated' => date('c'),
        'versions' => [],
    ];
    foreach ($versions as $key => $meta) {
        $payload['versions'][$key] = [
            'label' => $meta['label'],
            'version' => $meta['version'],
            'dir' => str_replace('/', '\\', $meta['dir']),
            'folder' => $meta['folder'],
            'port' => (int)$meta['port'],
            'hosts' => $meta['hosts'],
        ];
    }
    @file_put_contents(
        PHP_RUNTIME_FILE,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        LOCK_EX
    );
}

function build_php_config_files(array $versions): array
{
    $files = [
        'httpd' => [
            'label' => 'Apache httpd.conf',
            'path' => APACHE_ROOT . '/conf/httpd.conf',
        ],
        'php-fpm' => [
            'label' => 'Apache PHP vhosts',
            'path' => APACHE_ROOT . '/conf/extra/httpd-php-fpm.conf',
        ],
        'php-versions' => [
            'label' => 'Apache PHP version hosts',
            'path' => PHP_VERSIONS_VHOST,
        ],
    ];
    foreach ($versions as $key => $meta) {
        $id = php_log_id($key);
        $files[$id] = [
            'label' => 'php.ini (' . $meta['version'] . ')',
            'path' => $meta['dir'] . '/php.ini',
        ];
    }
    return $files;
}

function build_php_log_sources(array $versions): array
{
    $sources = [
        'apache' => [
            'label' => 'Apache',
            'domains' => ['*'],
            'kinds' => [
                'error' => ['label' => 'Error', 'path' => APACHE_ROOT . '/logs/error_log', 'format' => 'apache_error'],
                'access' => ['label' => 'Access', 'path' => APACHE_ROOT . '/logs/access_log', 'format' => 'apache_access'],
            ],
        ],
        'default' => [
            'label' => 'localhost',
            'domains' => ['localhost', 'latest', 'localhost.latest'],
            'kinds' => [
                'error' => ['label' => 'Vhost error', 'path' => APACHE_ROOT . '/logs/php-default-error.log', 'format' => 'apache_error'],
                'access' => ['label' => 'Access', 'path' => APACHE_ROOT . '/logs/php-default-access.log', 'format' => 'apache_access'],
            ],
        ],
    ];
    foreach ($versions as $key => $meta) {
        $id = php_log_id($key);
        $sources[$id] = [
            'label' => $meta['label'],
            'domains' => $meta['hosts'],
            'kinds' => [
                'error' => ['label' => 'Vhost error', 'path' => APACHE_ROOT . '/logs/' . $id . '-error.log', 'format' => 'apache_error'],
                'access' => ['label' => 'Access', 'path' => APACHE_ROOT . '/logs/' . $id . '-access.log', 'format' => 'apache_access'],
                'php' => ['label' => 'PHP runtime', 'path' => $meta['dir'] . '/logs/php_errors.log', 'format' => 'php_error'],
            ],
        ];
    }
    return $sources;
}

function build_php_log_files(array $versions): array
{
    $files = ['error' => APACHE_ROOT . '/logs/error_log'];
    foreach ($versions as $key => $meta) {
        $id = php_log_id($key);
        $files[$id] = APACHE_ROOT . '/logs/' . $id . '-error.log';
    }
    return $files;
}

function regenerate_php_version_vhosts(array $versions): array
{
    $chunks = [
        "# Auto-generated by Stack Panel from php* folders — do not edit by hand.\n",
        "# Regenerated when the panel loads or a PHP version is installed.\n\n",
    ];
    foreach ($versions as $key => $meta) {
        $port = (int)$meta['port'];
        $server = $meta['hosts'][0];
        $aliases = array_slice($meta['hosts'], 1);
        $aliasLine = $aliases ? "\n    ServerAlias " . implode(' ', $aliases) : '';
        $log = php_log_id($key);
        $label = $meta['label'];
        $folder = $meta['folder'];
        $chunks[] = "# {$label} ({$folder})\n"
            . "<VirtualHost *:80>\n"
            . "    ServerName {$server}{$aliasLine}\n"
            . "    DocumentRoot \"\${WEBROOT}/htdocs\"\n"
            . "    RewriteEngine On\n"
            . "    RewriteRule ^/(.*\\.php)/(.*)\$ /\$1?/\$2 [E=FIXED_SN:/\$1,NE,QSA,L]\n"
            . "    <Directory \"\${WEBROOT}/htdocs\">\n"
            . "        Options Indexes FollowSymLinks\n"
            . "        AllowOverride All\n"
            . "        Require all granted\n"
            . "        DirectoryIndex index.php index.html\n"
            . "        AcceptPathInfo On\n"
            . "        <FilesMatch \"\\.php\$\">\n"
            . "            SetHandler \"proxy:fcgi://127.0.0.1:{$port}/\"\n"
            . "        </FilesMatch>\n"
            . "    </Directory>\n"
            . "    ProxyFCGISetEnvIf \"reqenv('REDIRECT_FIXED_SN') =~ m#^/.+#\" SCRIPT_NAME \"%{reqenv:REDIRECT_FIXED_SN}\"\n"
            . "    ProxyFCGISetEnvIf \"reqenv('FIXED_SN') =~ m#^/.+#\" SCRIPT_NAME \"%{reqenv:FIXED_SN}\"\n"
            . "    ProxyFCGISetEnvIf \"true\" SCRIPT_FILENAME \"\${WEBROOT}/htdocs%{reqenv:SCRIPT_NAME}\"\n"
            . "    ErrorLog \"logs/{$log}-error.log\"\n"
            . "    CustomLog \"logs/{$log}-access.log\" \"%h %l %u %t \\\"%r\\\" %>s %b \\\"%{Host}i\\\"\"\n"
            . "</VirtualHost>\n\n";
    }
    $body = implode('', $chunks);
    $dir = dirname(PHP_VERSIONS_VHOST);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $current = is_file(PHP_VERSIONS_VHOST) ? (string)@file_get_contents(PHP_VERSIONS_VHOST) : '';
    if ($current !== $body) {
        if (@file_put_contents(PHP_VERSIONS_VHOST, $body) === false) {
            return ['ok' => false, 'message' => 'Could not write PHP version vhosts'];
        }
    }
    return ['ok' => true, 'message' => 'PHP version vhosts ready', 'path' => PHP_VERSIONS_VHOST];
}

function ensure_php_fpm_include(): void
{
    $path = APACHE_ROOT . '/conf/extra/httpd-php-fpm.conf';
    if (!is_file($path)) {
        return;
    }
    $raw = (string)@file_get_contents($path);
    if ($raw === '') {
        return;
    }
    $includeLine = 'Include conf/extra/httpd-php-versions.conf';
    if (stripos($raw, 'httpd-php-versions.conf') === false) {
        if (preg_match('/Include\s+conf\/extra\/httpd-sites\.conf/i', $raw)) {
            $raw = preg_replace(
                '/Include\s+conf\/extra\/httpd-sites\.conf/i',
                $includeLine . "\nInclude conf/extra/httpd-sites.conf",
                $raw,
                1
            );
        } else {
            $raw = rtrim($raw) . "\n\n" . $includeLine . "\nInclude conf/extra/httpd-sites.conf\n";
        }
        @file_put_contents($path, $raw);
    }

    // Drop legacy hardcoded per-version VirtualHost blocks (kept only localhost).
    if (preg_match('/# PHP 7\.4[\s\S]*?(?=Include conf\/extra\/httpd-php-versions\.conf|Include conf\/extra\/httpd-sites\.conf)/', $raw)) {
        $raw = (string)preg_replace(
            '/\n# PHP 7\.4[\s\S]*?(?=\nInclude conf\/extra\/httpd-php-versions\.conf|\nInclude conf\/extra\/httpd-sites\.conf)/',
            "\n",
            $raw,
            1
        );
        @file_put_contents($path, $raw);
    }
}

function php_refresh_catalog(bool $writeRuntime = true): array
{
    $versions = discover_php_versions();
    ensure_php_fpm_include();
    regenerate_php_version_vhosts($versions);
    if ($writeRuntime) {
        write_php_runtime_file($versions);
    }
    return $versions;
}

function php_http_get(string $url, int $timeout = 20): array
{
    if (function_exists('curl_init')) {
        foreach ([true, false] as $verify) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => $verify,
                CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                CURLOPT_USERAGENT => 'WebStackPanel/1.0',
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body !== false && $code < 400) {
                return ['ok' => true, 'body' => (string)$body, 'message' => 'ok'];
            }
            if ($verify && $body === false && stripos($err, 'ssl') !== false) {
                continue; // retry without verify (common with corporate proxies)
            }
            if ($body === false) {
                return ['ok' => false, 'message' => $err !== '' ? $err : 'Download failed', 'body' => ''];
            }
            return ['ok' => false, 'message' => 'HTTP ' . $code, 'body' => (string)$body];
        }
    }
    foreach ([true, false] as $verify) {
        $ctx = stream_context_create([
            'http' => ['timeout' => $timeout, 'header' => "User-Agent: WebStackPanel/1.0\r\n"],
            'ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false) {
            return ['ok' => true, 'body' => $body, 'message' => 'ok'];
        }
    }
    return ['ok' => false, 'message' => 'Could not fetch ' . $url, 'body' => ''];
}

/** Pick Thread-Safe x86 zip from a releases.json branch. */
function php_pick_ts_x86(array $branch): ?array
{
    foreach ($branch as $buildKey => $build) {
        if (!is_array($build) || !is_string($buildKey)) {
            continue;
        }
        if (!preg_match('/^ts-(vc|vs)\d+-x86$/i', $buildKey)) {
            continue;
        }
        if (!isset($build['zip']['path'])) {
            continue;
        }
        return [
            'build' => $buildKey,
            'path' => (string)$build['zip']['path'],
            'size' => (string)($build['zip']['size'] ?? ''),
            'sha256' => (string)($build['zip']['sha256'] ?? ''),
        ];
    }
    return null;
}

function php_available_releases(): array
{
    $res = php_http_get(PHP_RELEASES_URL, 25);
    if (!$res['ok']) {
        return ['ok' => false, 'message' => 'Could not load PHP releases: ' . $res['message'], 'releases' => []];
    }
    $json = json_decode($res['body'], true);
    if (!is_array($json)) {
        return ['ok' => false, 'message' => 'Invalid releases.json', 'releases' => []];
    }
    $installed = PHP_VERSIONS;
    $list = [];
    foreach ($json as $branch => $meta) {
        if (!is_array($meta) || !isset($meta['version'])) {
            continue;
        }
        $pick = php_pick_ts_x86($meta);
        if ($pick === null) {
            continue;
        }
        $version = (string)$meta['version'];
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $version, $m)) {
            continue;
        }
        $key = $m[1] . '.' . $m[2];
        $folder = 'php' . $version;
        $list[] = [
            'branch' => (string)$branch,
            'key' => $key,
            'version' => $version,
            'folder' => $folder,
            'build' => $pick['build'],
            'file' => $pick['path'],
            'size' => $pick['size'],
            'sha256' => $pick['sha256'],
            'url' => PHP_DOWNLOAD_BASE . $pick['path'],
            'installed' => isset($installed[$key]) && ($installed[$key]['version'] ?? '') === $version,
            'installed_other' => isset($installed[$key]) && ($installed[$key]['version'] ?? '') !== $version,
            'installed_folder' => $installed[$key]['folder'] ?? null,
            'arch' => 'x86 Thread Safe (matches Apache Win32)',
        ];
    }
    usort($list, static function (array $a, array $b): int {
        return version_compare($b['version'], $a['version']);
    });
    return ['ok' => true, 'message' => 'ok', 'releases' => $list];
}

function php_download_status(): array
{
    if (!is_file(PHP_DOWNLOAD_FILE)) {
        return ['active' => false, 'status' => 'idle', 'message' => ''];
    }
    $data = json_decode((string)@file_get_contents(PHP_DOWNLOAD_FILE), true);
    if (!is_array($data)) {
        return ['active' => false, 'status' => 'idle', 'message' => ''];
    }
    $status = (string)($data['status'] ?? 'idle');
    $active = in_array($status, ['queued', 'downloading', 'extracting', 'configuring'], true);
    return array_merge(['active' => $active], $data);
}

function php_download_write(array $data): void
{
    $dir = dirname(PHP_DOWNLOAD_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $data['updated'] = date('c');
    @file_put_contents(PHP_DOWNLOAD_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
}

function php_start_download(string $versionOrKey): array
{
    $current = php_download_status();
    if (!empty($current['active'])) {
        return ['ok' => false, 'message' => 'A PHP download is already running'];
    }
    $avail = php_available_releases();
    if (!$avail['ok']) {
        return ['ok' => false, 'message' => $avail['message']];
    }
    $pick = null;
    foreach ($avail['releases'] as $row) {
        if ($row['version'] === $versionOrKey || $row['key'] === $versionOrKey || $row['branch'] === $versionOrKey) {
            $pick = $row;
            break;
        }
    }
    if ($pick === null) {
        return ['ok' => false, 'message' => 'That PHP build is not in the current Windows releases list'];
    }
    $dest = WEB_ROOT . '/' . $pick['folder'];
    if (is_dir($dest) && is_file($dest . '/php-cgi.exe')) {
        return ['ok' => false, 'message' => $pick['folder'] . ' is already installed'];
    }
    php_download_write([
        'status' => 'queued',
        'percent' => 0,
        'version' => $pick['version'],
        'key' => $pick['key'],
        'folder' => $pick['folder'],
        'url' => $pick['url'],
        'sha256' => $pick['sha256'],
        'message' => 'Queued',
        'error' => '',
    ]);
    $php = '';
    foreach (PHP_VERSIONS as $meta) {
        if (is_file($meta['dir'] . '/php.exe')) {
            $php = $meta['dir'] . '/php.exe';
            break;
        }
    }
    if ($php === '') {
        return ['ok' => false, 'message' => 'Need an existing php.exe to run the installer'];
    }
    $script = SCRIPTS_DIR . '/php-download.php';
    $cmd = '"' . str_replace('/', '\\', $php) . '" "' . str_replace('/', '\\', $script) . '" ' . escapeshellarg($pick['version']);
    start_hidden($cmd);
    return ['ok' => true, 'message' => 'Downloading PHP ' . $pick['version'], 'data' => php_download_status()];
}

function php_prepare_ini(string $dir): void
{
    $ini = $dir . '/php.ini';
    if (!is_file($ini)) {
        $src = is_file($dir . '/php.ini-development') ? $dir . '/php.ini-development' : ($dir . '/php.ini-production');
        if (is_file($src)) {
            @copy($src, $ini);
        }
    }
    if (!is_file($ini)) {
        return;
    }
    foreach (['tmp', 'logs'] as $sub) {
        if (!is_dir($dir . '/' . $sub)) {
            @mkdir($dir . '/' . $sub, 0777, true);
        }
    }
    $raw = (string)@file_get_contents($ini);
    $updated = phpini_set_directive($raw, 'extension_dir', 'ext', true);
    $updated = phpini_set_directive($updated, 'date.timezone', 'Asia/Kolkata', false);
    $updated = phpini_set_directive($updated, 'display_errors', 'On', false);
    $updated = phpini_set_directive($updated, 'log_errors', 'On', false);
    $updated = phpini_set_directive($updated, 'error_log', str_replace('\\', '/', $dir . '/logs/php_errors.log'), false);
    $updated = phpini_set_directive($updated, 'session.save_path', str_replace('\\', '/', $dir . '/tmp'), true);
    $updated = phpini_set_directive($updated, 'upload_tmp_dir', str_replace('\\', '/', $dir . '/tmp'), true);
    $updated = phpini_set_directive($updated, 'SMTP', '127.0.0.1', false);
    $updated = phpini_set_directive($updated, 'smtp_port', '1025', false);
    $updated = phpini_set_directive($updated, 'sendmail_from', 'local@web.test', false);
    foreach (['curl', 'mbstring', 'mysqli', 'openssl', 'pdo_mysql', 'fileinfo'] as $ext) {
        $updated = preg_replace('/^\s*;\s*extension\s*=\s*' . preg_quote($ext, '/') . '\s*$/mi', 'extension=' . $ext, $updated, 1) ?? $updated;
    }
    if ($updated !== $raw) {
        @file_put_contents($ini, $updated);
    }
}
