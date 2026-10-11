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
        'bind' => is_array($cfg) ? (string)$cfg['bind'] : '127.0.0.1',
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

function ftp_lan_ip(): string
{
    $ip = gethostbyname(gethostname());
    if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return '';
    }
    if ($ip === '127.0.0.1') {
        return '';
    }
    return $ip;
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
        'bind' => is_array($cfg) ? (string)$cfg['bind'] : '127.0.0.1',
        'pasv_min' => is_array($cfg) ? (int)$cfg['pasv_min'] : 2122,
        'pasv_max' => is_array($cfg) ? (int)$cfg['pasv_max'] : 2200,
        'users' => $users,
        'host' => '127.0.0.1',
        'lan_ip' => ftp_lan_ip(),
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
