<?php
/**
 * Shared FTP account file for the panel and scripts/ftp-server.php.
 * Runtime file: <install>/ftp/ftp.json (outside htdocs).
 */
declare(strict_types=1);

function ftp_config_file(string $webRoot): string
{
    return rtrim(str_replace('\\', '/', $webRoot), '/') . '/ftp/ftp.json';
}

function ftp_log_file(string $webRoot): string
{
    return rtrim(str_replace('\\', '/', $webRoot), '/') . '/ftp/logs/ftp.log';
}

function ftp_pid_file(string $webRoot): string
{
    return rtrim(str_replace('\\', '/', $webRoot), '/') . '/ftp/ftp.pid';
}

function ftp_path_inside(string $root, string $path): bool
{
    $root = rtrim(strtolower(str_replace('\\', '/', $root)), '/');
    $path = rtrim(strtolower(str_replace('\\', '/', $path)), '/');
    if ($root === '' || $path === '') {
        return false;
    }
    return $path === $root || strpos($path, $root . '/') === 0;
}

function ftp_default_config(string $webRoot): array
{
    $root = rtrim(str_replace('\\', '/', $webRoot), '/');
    return [
        '_comment' => 'Web Stack FTP. Manage accounts at http://localhost/panel/ (FTP).',
        'port' => 2121,
        'bind' => '0.0.0.0',
        'pasv_min' => 2122,
        'pasv_max' => 2200,
        'users' => [
            [
                'name' => 'web',
                'password_hash' => password_hash('web', PASSWORD_DEFAULT),
                'initial_password' => 'web',
                'home' => $root . '/htdocs',
                'write' => true,
                'enabled' => true,
            ],
        ],
    ];
}

/** @return array|null null when the file is missing or not valid JSON */
function ftp_read_config(string $webRoot): ?array
{
    $path = ftp_config_file($webRoot);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($path), true);
    if (!is_array($data) || !isset($data['users']) || !is_array($data['users'])) {
        return null;
    }
    $cfg = ftp_normalize_config($webRoot, $data);
    return $cfg;
}

function ftp_ensure_config(string $webRoot): ?array
{
    $cfg = ftp_read_config($webRoot);
    if ($cfg !== null) {
        return $cfg;
    }
    if (is_file(ftp_config_file($webRoot))) {
        return null;
    }
    $cfg = ftp_default_config($webRoot);
    if (!ftp_write_config($webRoot, $cfg)) {
        return null;
    }
    return $cfg;
}

function ftp_normalize_config(string $webRoot, array $data): array
{
    unset($webRoot);
    $port = (int)($data['port'] ?? 2121);
    if ($port < 1 || $port > 65535) {
        $port = 2121;
    }
    $bind = (string)($data['bind'] ?? '0.0.0.0');
    if ($bind !== '127.0.0.1' && $bind !== '0.0.0.0') {
        $bind = '0.0.0.0';
    }
    $pasvMin = (int)($data['pasv_min'] ?? 2122);
    $pasvMax = (int)($data['pasv_max'] ?? 2200);
    if ($pasvMin < 1024) {
        $pasvMin = 2122;
    }
    if ($pasvMax > 65535 || $pasvMax < $pasvMin) {
        $pasvMax = min(65535, $pasvMin + 78);
    }
    $users = [];
    foreach ($data['users'] as $user) {
        if (!is_array($user)) {
            continue;
        }
        $name = (string)($user['name'] ?? '');
        $hash = (string)($user['password_hash'] ?? '');
        $home = str_replace('\\', '/', (string)($user['home'] ?? ''));
        if ($name === '' || $hash === '' || $home === '') {
            continue;
        }
        $row = [
            'name' => $name,
            'password_hash' => $hash,
            'home' => $home,
            'write' => !empty($user['write']),
            'enabled' => !array_key_exists('enabled', $user) || !empty($user['enabled']),
        ];
        if (isset($user['initial_password']) && is_string($user['initial_password']) && $user['initial_password'] !== '') {
            $row['initial_password'] = $user['initial_password'];
        }
        $users[] = $row;
    }
    return [
        '_comment' => (string)($data['_comment'] ?? 'Web Stack FTP. Manage accounts at http://localhost/panel/ (FTP).'),
        'port' => $port,
        'bind' => $bind,
        'pasv_min' => $pasvMin,
        'pasv_max' => $pasvMax,
        'users' => $users,
    ];
}

function ftp_write_config(string $webRoot, array $cfg): bool
{
    $path = ftp_config_file($webRoot);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }
    $cfg = ftp_normalize_config($webRoot, $cfg);
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function ftp_find_user(array $cfg, string $name): ?int
{
    foreach ($cfg['users'] as $i => $user) {
        if (strcasecmp((string)$user['name'], $name) === 0) {
            return (int)$i;
        }
    }
    return null;
}

/**
 * Relative paths are inside the install folder.
 * Absolute paths must already exist and stay inside the install folder.
 */
function ftp_resolve_home(string $webRoot, string $input, bool $createUnderHtdocs = false): ?string
{
    $input = trim(str_replace('\\', '/', $input));
    if ($input === '') {
        return null;
    }
    $root = rtrim(str_replace('\\', '/', $webRoot), '/');
    if (!preg_match('#^[A-Za-z]:/#', $input)) {
        $input = $root . '/' . ltrim($input, '/');
    }
    if ($createUnderHtdocs && !file_exists($input)) {
        $htdocs = strtolower($root . '/htdocs');
        $norm = strtolower($input);
        if ($norm === $htdocs || strpos($norm, $htdocs . '/') === 0) {
            if (!@mkdir($input, 0777, true) && !is_dir($input)) {
                return null;
            }
        }
    }
    $real = realpath($input);
    if ($real === false || !is_dir($real)) {
        return null;
    }
    $real = str_replace('\\', '/', $real);
    if (!ftp_path_inside($root, $real)) {
        return null;
    }
    return $real;
}
