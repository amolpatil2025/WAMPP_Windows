<?php
declare(strict_types=1);

const SITES_FILE = PANEL_ROOT . '/data/sites.json';
define('SITES_VHOST_FILE', APACHE_ROOT . '/conf/extra/httpd-sites.conf');
const HOSTS_FILE = 'C:/Windows/System32/drivers/etc/hosts';
const HOSTS_BEGIN = '# --- stack-panel-sites-begin ---';
const HOSTS_END = '# --- stack-panel-sites-end ---';

/** Rewrite legacy C:/web paths to the current install root. */
function remap_legacy_web_root(string $path): string
{
    $path = normalize_fs_path($path);
    if ($path === '') {
        return $path;
    }
    if (preg_match('#^[A-Za-z]:/web(/|$)#i', $path)) {
        return normalize_fs_path(WEB_ROOT . substr($path, strlen('C:/web')));
    }
    return $path;
}

function reserved_domains(): array
{
    $list = LOCALHOST_DOMAINS;
    foreach (PHP_VERSIONS as $meta) {
        foreach ($meta['hosts'] as $h) {
            $list[] = strtolower($h);
        }
    }
    $list[] = 'localhost';
    return array_values(array_unique($list));
}

function load_custom_sites(): array
{
    if (!is_file(SITES_FILE)) {
        return [];
    }
    $data = json_decode((string)@file_get_contents(SITES_FILE), true);
    if (!is_array($data)) {
        return [];
    }
    $changed = false;
    foreach ($data as &$site) {
        if (!is_array($site) || empty($site['root'])) {
            continue;
        }
        $mapped = remap_legacy_web_root((string)$site['root']);
        if ($mapped !== normalize_fs_path((string)$site['root'])) {
            $site['root'] = $mapped;
            $changed = true;
        }
    }
    unset($site);
    $sites = array_values($data);
    if ($changed) {
        save_custom_sites($sites);
        regenerate_sites_vhosts($sites);
    }
    return $sites;
}

function save_custom_sites(array $sites): bool
{
    $dir = dirname(SITES_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return file_put_contents(
        SITES_FILE,
        json_encode(array_values($sites), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    ) !== false;
}

function normalize_fs_path(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    return rtrim($path, '/');
}

function is_safe_site_path(string $path): bool
{
    $path = normalize_fs_path($path);
    if ($path === '' || preg_match('/\.\./', $path)) {
        return false;
    }
    // Absolute Windows path (any drive); install root is typical but not required
    if (!preg_match('#^[A-Za-z]:/#', $path)) {
        return false;
    }
    return true;
}

function is_valid_domain(string $domain): bool
{
    $domain = strtolower(trim($domain));
    if ($domain === '' || strlen($domain) > 253) {
        return false;
    }
    // Allow labels like my-app.test, site.local, php8.4 style is reserved separately
    return (bool)preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?$/', $domain);
}

function slugify_site_id(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? 'site';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'site';
}

function site_by_id(string $id): ?array
{
    foreach (load_custom_sites() as $site) {
        if (($site['id'] ?? '') === $id) {
            return $site;
        }
    }
    return null;
}

function all_site_domains(array $site): array
{
    $domains = [strtolower((string)($site['domain'] ?? ''))];
    foreach (($site['aliases'] ?? []) as $a) {
        $a = strtolower(trim((string)$a));
        if ($a !== '') {
            $domains[] = $a;
        }
    }
    return array_values(array_unique(array_filter($domains)));
}

function collect_used_domains(?string $exceptId = null): array
{
    $used = reserved_domains();
    foreach (load_custom_sites() as $site) {
        if ($exceptId !== null && ($site['id'] ?? '') === $exceptId) {
            continue;
        }
        foreach (all_site_domains($site) as $d) {
            $used[] = $d;
        }
    }
    return array_values(array_unique($used));
}

function validate_site_payload(array $input, ?string $exceptId = null): array
{
    $errors = [];
    $name = trim((string)($input['name'] ?? ''));
    $domain = strtolower(trim((string)($input['domain'] ?? '')));
    $root = normalize_fs_path((string)($input['root'] ?? ''));
    $php = (string)($input['php'] ?? default_php_version());
    $enabled = !isset($input['enabled']) || (bool)$input['enabled'];
    $aliasesRaw = $input['aliases'] ?? [];
    if (is_string($aliasesRaw)) {
        $aliasesRaw = preg_split('/[\s,]+/', $aliasesRaw) ?: [];
    }
    $aliases = [];
    foreach ($aliasesRaw as $a) {
        $a = strtolower(trim((string)$a));
        if ($a !== '' && $a !== $domain) {
            $aliases[] = $a;
        }
    }
    $aliases = array_values(array_unique($aliases));

    if ($name === '') {
        $errors[] = 'Name is required';
    }
    if (!is_valid_domain($domain)) {
        $errors[] = 'Domain is invalid (use something like myapp.test)';
    }
    if (!is_safe_site_path($root)) {
        $errors[] = 'Path must be an absolute Windows path without .. (e.g. ' . WEB_ROOT . '/htdocs/myapp)';
    }
    if (!isset(PHP_VERSIONS[$php])) {
        $errors[] = 'Unknown PHP version';
    }
    foreach ($aliases as $a) {
        if (!is_valid_domain($a)) {
            $errors[] = 'Invalid alias: ' . $a;
        }
    }

    $used = collect_used_domains($exceptId);
    $check = array_merge([$domain], $aliases);
    foreach ($check as $d) {
        if (in_array($d, $used, true)) {
            $errors[] = 'Domain already in use: ' . $d;
        }
    }

    $id = $exceptId ?: slugify_site_id($domain !== '' ? $domain : $name);
    if ($exceptId === null) {
        $base = $id;
        $n = 2;
        while (site_by_id($id)) {
            $id = $base . '-' . $n;
            $n++;
        }
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'site' => [
            'id' => $id,
            'name' => $name,
            'domain' => $domain,
            'aliases' => $aliases,
            'root' => $root,
            'php' => $php,
            'enabled' => $enabled,
            'updated' => date('c'),
        ],
    ];
}

function ensure_site_directory(string $root, bool $withIndex = true): array
{
    $root = normalize_fs_path($root);
    if (!is_dir($root)) {
        if (!@mkdir($root, 0777, true) && !is_dir($root)) {
            return ['ok' => false, 'message' => 'Could not create folder: ' . $root];
        }
    }
    $index = $root . '/index.php';
    if ($withIndex && !is_file($index)) {
        $php = "<?php\ndeclare(strict_types=1);\nheader('Content-Type: text/html; charset=utf-8');\n"
            . "echo '<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>' . htmlspecialchars(basename(__DIR__), ENT_QUOTES) . '</title></head><body>';\n"
            . "echo '<h1>' . htmlspecialchars(basename(__DIR__), ENT_QUOTES) . '</h1>';\n"
            . "echo '<p>PHP ' . PHP_VERSION . ' · ' . htmlspecialchars(\$_SERVER['HTTP_HOST'] ?? '', ENT_QUOTES) . '</p>';\n"
            . "echo '</body></html>';\n";
        @file_put_contents($index, $php);
    }
    return ['ok' => true, 'root' => $root];
}

function render_site_vhost(array $site): string
{
    $id = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)$site['id'])) ?: 'site';
    $root = normalize_fs_path((string)$site['root']);
    $php = (string)$site['php'];
    $port = PHP_VERSIONS[$php]['port'] ?? PHP_VERSIONS[default_php_version()]['port'];
    $domain = $site['domain'];
    $aliases = $site['aliases'] ?? [];
    $aliasLine = $aliases ? "\n    ServerAlias " . implode(' ', $aliases) : '';
    $log = 'site-' . $id;

    return <<<VHOST
# Site: {$site['name']} ({$id})
<VirtualHost *:80>
    ServerName {$domain}{$aliasLine}
    DocumentRoot "{$root}"
    RewriteEngine On
    RewriteRule ^/(.*\\.php)/(.*)\$ /\$1?/\$2 [NE,L,QSA]
    <Directory "{$root}">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html
        AcceptPathInfo On
        <FilesMatch "\\.php\$">
            SetHandler "proxy:fcgi://127.0.0.1:{$port}/"
        </FilesMatch>
    </Directory>
    ProxyFCGISetEnvIf "true" SCRIPT_FILENAME "{$root}%{reqenv:SCRIPT_NAME}"
    ErrorLog "logs/{$log}-error.log"
    CustomLog "logs/{$log}-access.log" "%h %l %u %t \\"%r\\" %>s %b \\"%{Host}i\\""
</VirtualHost>

VHOST;
}

function regenerate_sites_vhosts(?array $sites = null): array
{
    if ($sites === null) {
        $sites = load_custom_sites();
    }
    $chunks = [
        "# Auto-generated by Stack Panel — do not edit by hand.\n",
        "# Source: panel/data/sites.json\n\n",
    ];
    foreach ($sites as $site) {
        if (empty($site['enabled'])) {
            $chunks[] = '# disabled: ' . ($site['id'] ?? '') . "\n";
            continue;
        }
        $chunks[] = render_site_vhost($site);
    }
    $ok = file_put_contents(SITES_VHOST_FILE, implode('', $chunks)) !== false;
    if (!$ok) {
        return ['ok' => false, 'message' => 'Failed to write ' . SITES_VHOST_FILE];
    }
    $check = run_cmd('"' . APACHE_BIN . '" -t');
    $syntax = stripos($check['output'], 'Syntax OK') !== false;
    return [
        'ok' => $syntax,
        'message' => $syntax ? 'Sites vhost regenerated' : 'Vhost written but Apache syntax failed',
        'apache_test' => $check['output'],
        'path' => SITES_VHOST_FILE,
    ];
}

function hosts_block_for_sites(array $sites): string
{
    $names = [];
    foreach ($sites as $site) {
        if (empty($site['enabled'])) {
            continue;
        }
        foreach (all_site_domains($site) as $d) {
            $names[$d] = true;
        }
    }
    $lines = [HOSTS_BEGIN];
    if ($names) {
        $lines[] = '127.0.0.1 ' . implode(' ', array_keys($names));
    } else {
        $lines[] = '# (no custom sites)';
    }
    $lines[] = HOSTS_END;
    return implode("\n", $lines) . "\n";
}

function parse_hosts_names(string $content): array
{
    $present = [];
    foreach (preg_split('/\R/', $content) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = preg_split('/\s+/', $line) ?: [];
        if (count($parts) < 2) {
            continue;
        }
        // skip IP
        array_shift($parts);
        foreach ($parts as $name) {
            $name = strtolower(trim($name));
            if ($name !== '') {
                $present[$name] = true;
            }
        }
    }
    return $present;
}

function hosts_wanted_names(array $sites): array
{
    $names = [];
    foreach ($sites as $site) {
        if (empty($site['enabled'])) {
            continue;
        }
        foreach (all_site_domains($site) as $d) {
            $names[$d] = true;
        }
    }
    return array_keys($names);
}

function build_updated_hosts_content(string $current, array $sites): array
{
    $block = hosts_block_for_sites($sites);
    $wanted = hosts_wanted_names($sites);
    $wantedSet = array_fill_keys($wanted, true);
    $presentBefore = parse_hosts_names($current);
    $missingBefore = [];
    foreach ($wanted as $name) {
        if (!isset($presentBefore[$name])) {
            $missingBefore[] = $name;
        }
    }

    $working = $current;
    // Remove previous managed block
    if (strpos($working, HOSTS_BEGIN) !== false && strpos($working, HOSTS_END) !== false) {
        $working = preg_replace(
            '/' . preg_quote(HOSTS_BEGIN, '/') . '.*?' . preg_quote(HOSTS_END, '/') . '\s*/s',
            '',
            $working,
            1
        ) ?? $working;
    }

    // Drop unmanaged duplicates of our wanted names on loopback
    $kept = [];
    foreach (preg_split('/\R/', $working) ?: [] as $line) {
        $trim = trim($line);
        if ($trim === '' || (isset($trim[0]) && $trim[0] === '#')) {
            $kept[] = $line;
            continue;
        }
        $parts = preg_split('/\s+/', $trim) ?: [];
        if (count($parts) >= 2 && ($parts[0] === '127.0.0.1' || $parts[0] === '::1')) {
            $remain = [];
            for ($i = 1, $n = count($parts); $i < $n; $i++) {
                $name = strtolower($parts[$i]);
                if (!isset($wantedSet[$name])) {
                    $remain[] = $parts[$i];
                }
            }
            if ($remain) {
                $kept[] = $parts[0] . ' ' . implode(' ', $remain);
            }
            continue;
        }
        $kept[] = $line;
    }

    // Trim trailing empties
    while ($kept && trim(end($kept)) === '') {
        array_pop($kept);
    }

    $updated = implode("\n", $kept);
    $updated = rtrim($updated) . "\n\n" . $block;
    $presentAfter = parse_hosts_names($updated);

    return [
        'content' => $updated,
        'block' => $block,
        'wanted' => $wanted,
        'missing' => $missingBefore,
        'added' => $missingBefore,
        'present' => array_keys($presentAfter),
    ];
}

function write_hosts_file(string $content): bool
{
    return @file_put_contents(HOSTS_FILE, $content) !== false;
}

function write_hosts_elevated(string $block): array
{
    $blockFile = PANEL_ROOT . '/data/hosts.block.txt';
    $dir = dirname($blockFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    file_put_contents($blockFile, $block);

    $ps1 = SCRIPTS_DIR . '/update-hosts.ps1';
    // Elevate with UAC; wait for completion.
    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command '
        . '"Start-Process -FilePath powershell -Verb RunAs -Wait -ArgumentList '
        . "'-NoProfile -ExecutionPolicy Bypass -File \\\"" . $ps1 . "\\\" -BlockFile \\\"" . $blockFile . "\\\"'\"";

    $result = run_cmd($cmd);
    $readable = is_readable(HOSTS_FILE) ? (string)file_get_contents(HOSTS_FILE) : '';
    $ok = strpos($readable, HOSTS_BEGIN) !== false;
    return [
        'ok' => $ok,
        'message' => $ok ? 'Hosts file updated (Administrator)' : 'Elevated hosts update failed or was cancelled',
        'detail' => $result['output'],
    ];
}

function sync_windows_hosts(array $sites): array
{
    $block = hosts_block_for_sites($sites);
    if (!is_file(HOSTS_FILE) || !is_readable(HOSTS_FILE)) {
        return ['ok' => false, 'message' => 'Hosts file not readable', 'hosts_block' => $block, 'missing' => hosts_wanted_names($sites)];
    }

    $current = (string)file_get_contents(HOSTS_FILE);
    $plan = build_updated_hosts_content($current, $sites);
    $wanted = $plan['wanted'];
    $missing = $plan['missing'];

    // Nothing new and managed block already correct
    if ($missing === [] && strpos($current, HOSTS_BEGIN) !== false) {
        $existingBlockMatch = [];
        if (preg_match('/' . preg_quote(HOSTS_BEGIN, '/') . '.*?' . preg_quote(HOSTS_END, '/') . '/s', $current, $existingBlockMatch)) {
            $normExisting = preg_replace('/\s+/', ' ', trim($existingBlockMatch[0]));
            $normNew = preg_replace('/\s+/', ' ', trim($plan['block']));
            if ($normExisting === $normNew) {
                return [
                    'ok' => true,
                    'message' => 'Hosts already up to date',
                    'hosts_block' => $plan['block'],
                    'missing' => [],
                    'added' => [],
                ];
            }
        }
    }

    if (write_hosts_file($plan['content'])) {
        $msg = $missing
            ? ('Added hosts: ' . implode(', ', $missing))
            : 'Hosts file updated';
        return [
            'ok' => true,
            'message' => $msg,
            'hosts_block' => $plan['block'],
            'missing' => [],
            'added' => $missing,
        ];
    }

    // Permission denied — try UAC elevation
    $elev = write_hosts_elevated($plan['block']);
    if ($elev['ok']) {
        return [
            'ok' => true,
            'message' => ($missing ? ('Added hosts: ' . implode(', ', $missing) . ' · ') : '') . $elev['message'],
            'hosts_block' => $plan['block'],
            'missing' => [],
            'added' => $missing,
            'elevated' => true,
        ];
    }

    return [
        'ok' => false,
        'message' => 'Could not write hosts file. Approve the UAC prompt, or paste the block below as Administrator.',
        'hosts_block' => $plan['block'],
        'missing' => $missing,
        'added' => [],
        'detail' => $elev['detail'] ?? '',
    ];
}

function system_sites_catalog(): array
{
    $default = default_php_version();
    $items = [
        [
            'id' => 'system-localhost',
            'name' => 'localhost (default PHP)',
            'domains' => LOCALHOST_DOMAINS,
            'root' => WEB_ROOT . '/htdocs',
            'php' => $default,
            'system' => true,
            'url' => 'http://localhost/',
        ],
    ];
    foreach (PHP_VERSIONS as $key => $meta) {
        $items[] = [
            'id' => 'system-php-' . $key,
            'name' => $meta['label'],
            'domains' => $meta['hosts'],
            'root' => WEB_ROOT . '/htdocs',
            'php' => $key,
            'system' => true,
            'url' => 'http://' . $meta['hosts'][0] . '/',
        ];
    }
    return $items;
}

function sites_overview(): array
{
    $custom = load_custom_sites();
    foreach ($custom as &$site) {
        $site['system'] = false;
        $site['url'] = 'http://' . $site['domain'] . '/';
        $site['root_exists'] = is_dir(normalize_fs_path((string)$site['root']));
        $site['php_label'] = PHP_VERSIONS[$site['php']]['label'] ?? $site['php'];
        $site['php_port'] = PHP_VERSIONS[$site['php']]['port'] ?? null;
    }
    unset($site);

    return [
        'system' => system_sites_catalog(),
        'custom' => $custom,
        'php_versions' => array_map(static function ($k, $m) {
            return ['id' => $k, 'label' => $m['label'], 'port' => $m['port']];
        }, array_keys(PHP_VERSIONS), array_values(PHP_VERSIONS)),
        'default_php' => default_php_version(),
        'htdocs' => WEB_ROOT . '/htdocs',
        'reserved' => reserved_domains(),
    ];
}

function upsert_site(array $input, ?string $id = null): array
{
    $parsed = validate_site_payload($input, $id);
    if (!$parsed['ok']) {
        return ['ok' => false, 'message' => implode('; ', $parsed['errors']), 'errors' => $parsed['errors']];
    }
    $site = $parsed['site'];
    if ($id) {
        $existing = site_by_id($id);
        if (!$existing) {
            return ['ok' => false, 'message' => 'Site not found'];
        }
        $site['id'] = $id;
        $site['created'] = $existing['created'] ?? date('c');
    } else {
        $site['created'] = date('c');
    }

    if (!empty($input['create_folder'])) {
        $dir = ensure_site_directory($site['root'], true);
        if (!$dir['ok']) {
            return $dir;
        }
    }

    $sites = load_custom_sites();
    $found = false;
    foreach ($sites as $i => $row) {
        if (($row['id'] ?? '') === $site['id']) {
            $sites[$i] = $site;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $sites[] = $site;
    }

    if (!save_custom_sites($sites)) {
        return ['ok' => false, 'message' => 'Failed to save sites.json'];
    }

    $vhost = regenerate_sites_vhosts();
    if (!$vhost['ok']) {
        return ['ok' => false, 'message' => $vhost['message'], 'detail' => $vhost['apache_test'] ?? ''];
    }

    start_php_version($site['php']);
    $hosts = sync_windows_hosts($sites);
    $restart = restart_apache_process(true);

    return [
        'ok' => true,
        'message' => ($id ? 'Site updated' : 'Site created') . ' · ' . $site['domain'],
        'site' => $site,
        'hosts' => $hosts,
        'restart' => $restart,
        'data' => sites_overview(),
    ];
}

function delete_site(string $id): array
{
    $sites = load_custom_sites();
    $next = [];
    $removed = null;
    foreach ($sites as $site) {
        if (($site['id'] ?? '') === $id) {
            $removed = $site;
            continue;
        }
        $next[] = $site;
    }
    if ($removed === null) {
        return ['ok' => false, 'message' => 'Site not found'];
    }
    save_custom_sites($next);
    $vhost = regenerate_sites_vhosts();
    $hosts = sync_windows_hosts($next);
    restart_apache_process(true);
    return [
        'ok' => true,
        'message' => 'Deleted ' . ($removed['domain'] ?? $id),
        'hosts' => $hosts,
        'vhost' => $vhost,
        'data' => sites_overview(),
    ];
}
