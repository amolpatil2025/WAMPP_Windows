<?php
declare(strict_types=1);

require_once SCRIPTS_DIR . '/lib/ftp-config.php';

function ftp_php_binary(): string
{
    $order = array_unique(array_merge([default_php_version()], ['8.4', '8.0', '7.4']));
    foreach ($order as $ver) {
        if (!isset(PHP_VERSIONS[$ver])) {
            continue;
        }
        $exe = PHP_VERSIONS[$ver]['dir'] . '/php.exe';
        if (is_file($exe)) {
            return str_replace('\\', '/', $exe);
        }
    }
    return '';
}

function ftp_php_pids_on_port(int $port): array
{
    $own = [];
    foreach (pids_on_port($port) as $pid) {
        $out = [];
        exec('tasklist /FI "PID eq ' . (int)$pid . '" /FO CSV /NH', $out);
        if (stripos(implode("\n", $out), 'php.exe') !== false) {
            $own[] = (int)$pid;
        }
    }
    return $own;
}

function ftp_reserved_ports(): array
{
    $ports = [80, 443, 3306, MAILPIT_SMTP_PORT, MAILPIT_UI_PORT];
    foreach (PHP_VERSIONS as $meta) {
        $ports[] = (int)$meta['port'];
    }
    return $ports;
}

function ftp_status_brief(): array
{
    $cfg = ftp_ensure_config(WEB_ROOT);
    $port = is_array($cfg) ? (int)$cfg['port'] : 2121;
    $php = ftp_php_binary();
    $listening = port_listening($port);
    $ours = $listening && $php !== '' && ftp_php_pids_on_port($port) !== [];
    return [
        'up' => $ours,
        'installed' => $php !== '',
        'port_busy' => $listening && !$ours,
        'config_ok' => is_array($cfg),
        'port' => $port,
        'bind' => is_array($cfg) ? (string)$cfg['bind'] : '0.0.0.0',
        'lan_ip' => ftp_lan_ip(),
        'label' => 'FTP',
    ];
}

function start_ftp(): array
{
    $php = ftp_php_binary();
    if ($php === '') {
        return ['ok' => false, 'message' => 'php.exe not found, so FTP cannot start'];
    }
    $cfg = ftp_ensure_config(WEB_ROOT);
    if ($cfg === null) {
        return ['ok' => false, 'message' => 'ftp/ftp.json is invalid'];
    }
    $port = (int)$cfg['port'];
    if (ftp_php_pids_on_port($port) !== []) {
        return ['ok' => true, 'message' => 'FTP already running on :' . $port];
    }
    if (port_listening($port)) {
        return ['ok' => false, 'message' => 'Port ' . $port . ' is already used by another program'];
    }
    $exe = str_replace('/', '\\', $php);
    $script = str_replace('/', '\\', SCRIPTS_DIR . '/ftp-server.php');
    start_hidden('"' . $exe . '" "' . $script . '"');
    $deadline = microtime(true) + 2.5;
    while (microtime(true) < $deadline) {
        usleep(200000);
        if (ftp_php_pids_on_port($port) !== []) {
            $where = $cfg['bind'] === '0.0.0.0' ? 'all interfaces' : '127.0.0.1';
            return ['ok' => true, 'message' => 'FTP started on ' . $where . ' :' . $port];
        }
    }
    $log = trim(read_tail(ftp_log_file(WEB_ROOT), 8));
    $last = $log === '' ? '' : trim((string)substr($log, (int)strrpos("\n" . $log, "\n")));
    return ['ok' => false, 'message' => 'Failed to start FTP' . ($last !== '' ? ' — ' . $last : '')];
}

function stop_ftp(): array
{
    $cfg = ftp_read_config(WEB_ROOT);
    $port = is_array($cfg) ? (int)$cfg['port'] : 2121;
    $pids = ftp_php_pids_on_port($port);
    if (!$pids) {
        @unlink(ftp_pid_file(WEB_ROOT));
        return ['ok' => true, 'message' => 'FTP was not running'];
    }
    foreach ($pids as $pid) {
        run_cmd('taskkill /F /PID ' . (int)$pid);
    }
    usleep(300000);
    @unlink(ftp_pid_file(WEB_ROOT));
    return ['ok' => true, 'message' => 'FTP stopped'];
}

function restart_ftp(): array
{
    stop_ftp();
    usleep(300000);
    return start_ftp();
}

function ftp_is_lan_ipv4(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    if ($ip === '0.0.0.0' || strpos($ip, '127.') === 0 || strpos($ip, '169.254.') === 0) {
        return false;
    }
    return true;
}

function ftp_lan_rank(string $ip): int
{
    if (preg_match('/^192\.168\.(56|122)\./', $ip)) {
        return 40;
    }
    if (preg_match('/^192\.168\./', $ip)) {
        return 10;
    }
    if (preg_match('/^10\./', $ip)) {
        return 20;
    }
    if (preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $ip)) {
        return 30;
    }
    return 50;
}

function ftp_virtual_adapter(string $name): bool
{
    return (bool)preg_match('/vEthernet|VMware|VirtualBox|Hyper-V|WSL|Bluetooth|OpenVPN|TAP-Windows|WireGuard|ZeroTier|Tailscale|Docker|Npcap|VPN|NordLynx|Wintun|Loopback/i', $name);
}

/**
 * IPv4 addresses other computers on this network can use.
 * Real adapters with a gateway come first. VMware, Hyper-V, and VPN adapters are skipped.
 */
function ftp_lan_ips(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $out = [];
    @exec('ipconfig', $out);
    $adapters = [];
    $current = -1;
    foreach ($out as $line) {
        $trim = rtrim($line);
        if ($trim !== '' && preg_match('/^[^\s].*:$/', $trim)) {
            $adapters[] = ['name' => $trim, 'ips' => [], 'gateway' => false];
            $current = count($adapters) - 1;
            continue;
        }
        if ($current < 0) {
            continue;
        }
        if (preg_match('/IPv4[^:]*:\s*(\d{1,3}(?:\.\d{1,3}){3})/i', $line, $m)) {
            $adapters[$current]['ips'][] = $m[1];
        } elseif (preg_match('/(?:Gateway|Passerelle)[^:]*:\s*(\S+)/i', $line, $m) && $m[1] !== '') {
            $adapters[$current]['gateway'] = true;
        }
    }
    $ranked = [];
    foreach ($adapters as $adapter) {
        if (ftp_virtual_adapter($adapter['name'])) {
            continue;
        }
        foreach ($adapter['ips'] as $ip) {
            if (!ftp_is_lan_ipv4($ip)) {
                continue;
            }
            $score = ftp_lan_rank($ip) + ($adapter['gateway'] ? 0 : 25);
            if (preg_match('/\.1$/', $ip)) {
                $score += 15;
            }
            if (!isset($ranked[$ip]) || $score < $ranked[$ip]) {
                $ranked[$ip] = $score;
            }
        }
    }
    if (!$ranked) {
        $name = gethostname();
        $fallback = [];
        if (is_string($name) && $name !== '') {
            $list = @gethostbynamel($name);
            if (is_array($list)) {
                $fallback = $list;
            }
            $one = gethostbyname($name);
            if (is_string($one)) {
                $fallback[] = $one;
            }
        }
        foreach ($fallback as $ip) {
            $ip = (string)$ip;
            if (ftp_is_lan_ipv4($ip)) {
                $ranked[$ip] = ftp_lan_rank($ip);
            }
        }
    }
    asort($ranked);
    $cached = array_keys($ranked);
    return $cached;
}

function ftp_lan_ip(): string
{
    $ips = ftp_lan_ips();
    return $ips[0] ?? '';
}

function ftp_client_exes(): array
{
    $pf = str_replace('\\', '/', (string)(getenv('ProgramFiles') ?: 'C:/Program Files'));
    $pf86 = str_replace('\\', '/', (string)(getenv('ProgramFiles(x86)') ?: 'C:/Program Files (x86)'));
    $local = str_replace('\\', '/', (string)(getenv('LOCALAPPDATA') ?: ''));
    $pick = static function (array $paths): string {
        foreach ($paths as $path) {
            if ($path !== '' && is_file($path)) {
                return str_replace('/', '\\', $path);
            }
        }
        return '';
    };
    return [
        'filezilla' => $pick([
            $pf . '/FileZilla FTP Client/filezilla.exe',
            $pf86 . '/FileZilla FTP Client/filezilla.exe',
            $local !== '' ? $local . '/FileZilla FTP Client/filezilla.exe' : '',
        ]),
        'winscp' => $pick([
            $pf . '/WinSCP/WinSCP.exe',
            $pf86 . '/WinSCP/WinSCP.exe',
            $local !== '' ? $local . '/Programs/WinSCP/WinSCP.exe' : '',
        ]),
    ];
}

function ftp_enabled_account(?array $cfg): ?array
{
    if (!is_array($cfg)) {
        return null;
    }
    foreach ($cfg['users'] as $user) {
        if (!empty($user['enabled'])) {
            return $user;
        }
    }
    return null;
}

function ftp_client_url(string $host, int $port, ?array $user): string
{
    $auth = '';
    if (is_array($user)) {
        $name = rawurlencode((string)$user['name']);
        $pass = isset($user['initial_password']) ? (string)$user['initial_password'] : '';
        $auth = $pass !== '' ? $name . ':' . rawurlencode($pass) . '@' : $name . '@';
    }
    return 'ftp://' . $auth . $host . ':' . $port . '/';
}

function ftp_start_visible(string $command): void
{
    pclose(popen('cmd /c start "" ' . $command, 'r'));
}

function ftp_open_client(string $host, string $client): array
{
    $cfg = ftp_ensure_config(WEB_ROOT);
    if ($cfg === null) {
        return ['ok' => false, 'message' => 'ftp/ftp.json is invalid'];
    }
    $host = trim($host);
    $allowed = ['127.0.0.1', 'localhost'];
    if (($cfg['bind'] ?? '') === '0.0.0.0') {
        $allowed = array_merge($allowed, ftp_lan_ips());
    }
    if (!in_array($host, $allowed, true)) {
        return ['ok' => false, 'message' => 'That address is not this computer'];
    }
    $port = (int)$cfg['port'];
    $url = ftp_client_url($host, $port, ftp_enabled_account($cfg));
    $shown = 'ftp://' . $host . ':' . $port . '/';
    if ($client === 'explorer') {
        ftp_start_visible('explorer.exe ' . escapeshellarg($url));
        return ['ok' => true, 'message' => 'Opened ' . $shown . ' in Windows Explorer'];
    }
    $exes = ftp_client_exes();
    if ($client !== 'filezilla' && $client !== 'winscp') {
        return ['ok' => false, 'message' => 'Unknown FTP program'];
    }
    $exe = $exes[$client];
    $label = $client === 'filezilla' ? 'FileZilla' : 'WinSCP';
    if ($exe === '') {
        return ['ok' => false, 'message' => $label . ' is not installed. Use Explorer, or paste ' . $shown . ' into your FTP program.'];
    }
    ftp_start_visible(escapeshellarg($exe) . ' ' . escapeshellarg($url));
    return ['ok' => true, 'message' => 'Opened ' . $shown . ' in ' . $label];
}

function ftp_panel_state(): array
{
    $brief = ftp_status_brief();
    $cfg = ftp_read_config(WEB_ROOT);
    if ($cfg === null && empty($brief['config_ok'])) {
        $cfg = ftp_ensure_config(WEB_ROOT);
    }
    $users = [];
    if (is_array($cfg)) {
        foreach ($cfg['users'] as $user) {
            $users[] = [
                'name' => (string)$user['name'],
                'home' => (string)$user['home'],
                'write' => !empty($user['write']),
                'enabled' => !empty($user['enabled']),
                'initial_password' => isset($user['initial_password']) ? (string)$user['initial_password'] : null,
            ];
        }
    }
    return [
        'up' => $brief['up'],
        'installed' => $brief['installed'],
        'port_busy' => $brief['port_busy'],
        'config_ok' => is_array($cfg),
        'port' => is_array($cfg) ? (int)$cfg['port'] : 2121,
        'bind' => is_array($cfg) ? (string)$cfg['bind'] : '0.0.0.0',
        'pasv_min' => is_array($cfg) ? (int)$cfg['pasv_min'] : 2122,
        'pasv_max' => is_array($cfg) ? (int)$cfg['pasv_max'] : 2200,
        'users' => $users,
        'host' => '127.0.0.1',
        'lan_ip' => ftp_lan_ip(),
        'lan_ips' => ftp_lan_ips(),
        'clients' => array_map(static function (string $path): bool {
            return $path !== '';
        }, ftp_client_exes()),
        'stack_root' => WEB_ROOT,
        'log' => read_tail(ftp_log_file(WEB_ROOT), 40),
        'log_path' => ftp_log_file(WEB_ROOT),
    ];
}

function ftp_save_settings(array $in): array
{
    $cfg = ftp_ensure_config(WEB_ROOT);
    if ($cfg === null) {
        return ['ok' => false, 'message' => 'ftp/ftp.json is invalid'];
    }
    $port = (int)($in['port'] ?? 0);
    $bind = (string)($in['bind'] ?? '');
    if ($port < 1 || $port > 65535) {
        return ['ok' => false, 'message' => 'Port must be between 1 and 65535'];
    }
    if (in_array($port, ftp_reserved_ports(), true)) {
        return ['ok' => false, 'message' => 'Port ' . $port . ' is used by Apache, PHP, MySQL, or Mail'];
    }
    if ($bind !== '127.0.0.1' && $bind !== '0.0.0.0') {
        return ['ok' => false, 'message' => 'Listen address must be localhost or all interfaces'];
    }
    $oldPort = (int)$cfg['port'];
    $wasRunning = ftp_php_pids_on_port($oldPort) !== [];
    $cfg['port'] = $port;
    $cfg['bind'] = $bind;
    if ($port >= (int)$cfg['pasv_min'] && $port <= (int)$cfg['pasv_max']) {
        $cfg['pasv_min'] = $port + 1;
        $cfg['pasv_max'] = min(65535, $port + 79);
    }
    if (!ftp_write_config(WEB_ROOT, $cfg)) {
        return ['ok' => false, 'message' => 'Could not save FTP settings'];
    }
    if (!$wasRunning) {
        return ['ok' => true, 'message' => 'FTP settings saved'];
    }
    foreach (ftp_php_pids_on_port($oldPort) as $pid) {
        run_cmd('taskkill /F /PID ' . (int)$pid);
    }
    usleep(300000);
    $started = start_ftp();
    return [
        'ok' => $started['ok'],
        'message' => $started['ok'] ? 'FTP settings saved and server restarted' : $started['message'],
    ];
}

function ftp_save_user(array $in): array
{
    $cfg = ftp_ensure_config(WEB_ROOT);
    if ($cfg === null) {
        return ['ok' => false, 'message' => 'ftp/ftp.json is invalid'];
    }
    $name = trim((string)($in['name'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $name)) {
        return ['ok' => false, 'message' => 'Username can use letters, numbers, dot, underscore, and hyphen'];
    }
    $previous = trim((string)($in['previous'] ?? ''));
    $password = (string)($in['password'] ?? '');
    $home = ftp_resolve_home(WEB_ROOT, (string)($in['home'] ?? ''), true);
    if ($home === null) {
        return ['ok' => false, 'message' => 'Home must be an existing folder inside this stack (for example htdocs)'];
    }
    $idx = ftp_find_user($cfg, $previous !== '' ? $previous : $name);
    $duplicate = ftp_find_user($cfg, $name);
    if ($duplicate !== null && $duplicate !== $idx) {
        return ['ok' => false, 'message' => 'That username already exists'];
    }
    if ($idx === null && $password === '') {
        return ['ok' => false, 'message' => 'Password is required for a new account'];
    }
    if ($password !== '' && strlen($password) > 128) {
        return ['ok' => false, 'message' => 'Password is too long'];
    }
    $row = [
        'name' => $name,
        'home' => $home,
        'write' => !empty($in['write']),
        'enabled' => !empty($in['enabled']),
    ];
    if ($idx !== null) {
        $current = $cfg['users'][$idx];
        $row['password_hash'] = (string)$current['password_hash'];
        if (isset($current['initial_password'])) {
            $row['initial_password'] = (string)$current['initial_password'];
        }
    }
    if ($password !== '') {
        $row['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        unset($row['initial_password']);
    }
    if ($idx === null) {
        $cfg['users'][] = $row;
    } else {
        $cfg['users'][$idx] = $row;
    }
    $cfg['users'] = array_values($cfg['users']);
    if (!ftp_write_config(WEB_ROOT, $cfg)) {
        return ['ok' => false, 'message' => 'Could not save the FTP account'];
    }
    return ['ok' => true, 'message' => 'Saved FTP account ' . $name];
}

function ftp_delete_user(string $name): array
{
    $cfg = ftp_ensure_config(WEB_ROOT);
    if ($cfg === null) {
        return ['ok' => false, 'message' => 'ftp/ftp.json is invalid'];
    }
    $idx = ftp_find_user($cfg, $name);
    if ($idx === null) {
        return ['ok' => false, 'message' => 'Account not found'];
    }
    array_splice($cfg['users'], $idx, 1);
    if (!ftp_write_config(WEB_ROOT, $cfg)) {
        return ['ok' => false, 'message' => 'Could not delete the FTP account'];
    }
    return ['ok' => true, 'message' => 'Removed FTP account ' . $name];
}
