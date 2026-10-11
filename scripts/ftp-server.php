<?php
/**
 * Local plain-FTP server for the Web Stack.
 * Accounts live in <install>/ftp/ftp.json. Each account is jailed to its home folder.
 *
 *   php scripts/ftp-server.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "FTP server is CLI only.\n");
    exit(1);
}

require __DIR__ . '/lib/ftp-config.php';

$webRoot = str_replace('\\', '/', dirname(__DIR__));
$started = false;

set_time_limit(0);
ignore_user_abort(true);
if (function_exists('cli_set_process_title')) {
    @cli_set_process_title('Web Stack FTP');
}

function ftp_log(string $webRoot, string $message): void
{
    $path = ftp_log_file($webRoot);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($webRoot): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    ftp_log($webRoot, 'PHP: ' . $message . ' in ' . basename($file) . ':' . $line);
    return true;
});

$cfg = ftp_ensure_config($webRoot);
if ($cfg === null) {
    ftp_log($webRoot, 'refusing to start: ftp/ftp.json is missing or invalid');
    exit(1);
}

$listenBind = (string)$cfg['bind'];
$listenPort = (int)$cfg['port'];
$listenAddr = 'tcp://' . $listenBind . ':' . $listenPort;
$context = stream_context_create([
    'socket' => [
        'so_reuseaddr' => true,
        'backlog' => 32,
    ],
]);
$errno = 0;
$errstr = '';
$server = @stream_socket_server($listenAddr, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$server) {
    ftp_log($webRoot, 'bind failed on ' . $listenBind . ':' . $listenPort . ' (' . $errno . ' ' . $errstr . ')');
    exit(1);
}
stream_set_blocking($server, false);

$pidFile = ftp_pid_file($webRoot);
@file_put_contents($pidFile, (string)getmypid());
$started = true;
ftp_log($webRoot, 'listening on ' . $listenBind . ':' . $listenPort);

register_shutdown_function(static function () use ($webRoot, $pidFile, &$server, &$started): void {
    if (is_resource($server)) {
        @fclose($server);
    }
    if (is_file($pidFile) && (int)@file_get_contents($pidFile) === getmypid()) {
        @unlink($pidFile);
    }
    if ($started) {
        ftp_log($webRoot, 'stopped');
    }
});

final class FtpSession
{
    /** @var resource */
    public $ctrl;
    /** @var string */
    public $in = '';
    /** @var bool */
    public $authed = false;
    /** @var array|null */
    public $account = null;
    /** @var string */
    public $pendingUser = '';
    /** @var string */
    public $cwd = '/';
    /** @var string */
    public $type = 'A';
    /** @var int */
    public $rest = 0;
    /** @var array|null */
    public $rename = null;
    /** @var resource|null */
    public $pasv = null;
    /** @var resource|null */
    public $data = null;
    /** @var string|null */
    public $active = null;
    /** @var array|null */
    public $xfer = null;
    /** @var bool */
    public $dead = false;
    /** @var int */
    public $fails = 0;
    /** @var int */
    public $seen = 0;

    /** @param resource $ctrl */
    public function __construct($ctrl)
    {
        $this->ctrl = $ctrl;
        $this->seen = time();
    }
}

/** @var FtpSession[] $sessions */
$sessions = [];
$lastConfig = $cfg;
$lastConfigMtime = @filemtime(ftp_config_file($webRoot));

while (true) {
    $mtime = @filemtime(ftp_config_file($webRoot));
    if ($mtime !== $lastConfigMtime) {
        $fresh = ftp_read_config($webRoot);
        $lastConfigMtime = $mtime;
        if (is_array($fresh)) {
            $lastConfig = $fresh;
        } else {
            ftp_log($webRoot, 'ignored unreadable ftp.json, keeping previous accounts');
        }
    }

    $read = [$server];
    $write = [];
    foreach ($sessions as $session) {
        if ($session->dead || !is_resource($session->ctrl)) {
            $session->dead = true;
            continue;
        }
        $read[] = $session->ctrl;
        if (is_resource($session->pasv)) {
            $read[] = $session->pasv;
        }
        if (is_resource($session->data) && is_array($session->xfer) && empty($session->xfer['wait'])) {
            if ($session->xfer['dir'] === 'in') {
                $read[] = $session->data;
            } else {
                $write[] = $session->data;
            }
        }
    }

    $writeArg = $write ? $write : null;
    $except = null;
    $ready = @stream_select($read, $writeArg, $except, 0, 200000);
    if ($ready === false) {
        usleep(50000);
        foreach ($sessions as $session) {
            if (!is_resource($session->ctrl)) {
                $session->dead = true;
            }
        }
        ftp_reap($sessions);
        continue;
    }

    if ($ready > 0 && in_array($server, $read, true)) {
        $client = @stream_socket_accept($server, 0);
        if (is_resource($client)) {
            if (count($sessions) >= 30) {
                @fwrite($client, "421 Too many connections\r\n");
                @fclose($client);
            } else {
                stream_set_blocking($client, false);
                $session = new FtpSession($client);
                $sessions[] = $session;
                ftp_reply($session, 220, 'Web Stack FTP ready');
            }
        }
    }

    $writeList = is_array($writeArg) ? $writeArg : [];
    foreach ($sessions as $session) {
        if ($session->dead) {
            continue;
        }
        try {
            if (is_resource($session->pasv) && in_array($session->pasv, $read, true)) {
                ftp_accept_pasv($session);
            }
            if (in_array($session->ctrl, $read, true)) {
                ftp_read_control($session, $webRoot, $lastConfig, $listenBind);
            }
            if (!$session->dead && is_array($session->xfer)) {
                ftp_pump($session, $writeList, $read);
                if (is_array($session->xfer) && time() - $session->seen > 120) {
                    ftp_finish($session, 426, 'Transfer timed out');
                }
            }
            if (!$session->dead && $session->authed && time() - $session->seen > 600 && !is_array($session->xfer)) {
                ftp_reply($session, 421, 'Idle timeout');
                $session->dead = true;
            }
        } catch (Throwable $e) {
            ftp_log($webRoot, 'session error: ' . $e->getMessage());
            if (!$session->dead) {
                ftp_reply($session, 451, 'Local error');
            }
            $session->dead = true;
        }
    }

    ftp_reap($sessions);
}

/**
 * @param FtpSession[] $sessions
 */
function ftp_reap(array &$sessions): void
{
    foreach ($sessions as $i => $session) {
        if (!$session->dead && is_resource($session->ctrl)) {
            continue;
        }
        ftp_close_session($session);
        unset($sessions[$i]);
    }
    if ($sessions) {
        $sessions = array_values($sessions);
    }
}

function ftp_close_session(FtpSession $session): void
{
    if (is_array($session->xfer) && isset($session->xfer['fh']) && is_resource($session->xfer['fh'])) {
        @fclose($session->xfer['fh']);
    }
    $session->xfer = null;
    foreach (['data', 'pasv', 'ctrl'] as $slot) {
        if (is_resource($session->{$slot})) {
            @fclose($session->{$slot});
        }
        $session->{$slot} = null;
    }
}

function ftp_reply(FtpSession $session, int $code, string $text): void
{
    if ($session->dead || !is_resource($session->ctrl)) {
        $session->dead = true;
        return;
    }
    stream_set_blocking($session->ctrl, true);
    $ok = @fwrite($session->ctrl, $code . ' ' . $text . "\r\n");
    stream_set_blocking($session->ctrl, false);
    if ($ok === false) {
        $session->dead = true;
    }
}

function ftp_reply_block(FtpSession $session, int $code, array $lines): void
{
    if ($session->dead || !is_resource($session->ctrl)) {
        $session->dead = true;
        return;
    }
    $last = count($lines) - 1;
    $out = '';
    foreach ($lines as $i => $line) {
        $out .= $code . ($i === $last ? ' ' : '-') . $line . "\r\n";
    }
    stream_set_blocking($session->ctrl, true);
    $ok = @fwrite($session->ctrl, $out);
    stream_set_blocking($session->ctrl, false);
    if ($ok === false) {
        $session->dead = true;
    }
}

function ftp_peer_ip($socket): string
{
    $name = @stream_socket_get_name($socket, true);
    if (!is_string($name) || $name === '') {
        return '';
    }
    $colon = strrpos($name, ':');
    if ($colon === false) {
        return $name;
    }
    return substr($name, 0, $colon);
}

function ftp_local_ip($socket): string
{
    $name = @stream_socket_get_name($socket, false);
    $ip = '';
    if (is_string($name)) {
        $colon = strrpos($name, ':');
        $ip = $colon === false ? $name : substr($name, 0, $colon);
    }
    if ($ip === '' || $ip === '0.0.0.0' || $ip === '::') {
        return '127.0.0.1';
    }
    return $ip;
}

function ftp_accept_pasv(FtpSession $session): void
{
    $client = @stream_socket_accept($session->pasv, 0);
    @fclose($session->pasv);
    $session->pasv = null;
    if (!is_resource($client)) {
        return;
    }
    $ctrlIp = ftp_peer_ip($session->ctrl);
    $dataIp = ftp_peer_ip($client);
    if ($ctrlIp !== '' && $dataIp !== '' && $ctrlIp !== $dataIp) {
        @fclose($client);
        return;
    }
    stream_set_blocking($client, false);
    if (is_resource($session->data)) {
        @fclose($session->data);
    }
    $session->data = $client;
}

function ftp_read_control(FtpSession $session, string $webRoot, array $cfg, string $listenBind): void
{
    $chunk = @fread($session->ctrl, 4096);
    if ($chunk === false || $chunk === '') {
        if ($chunk === '' && !@feof($session->ctrl)) {
            return;
        }
        $session->dead = true;
        return;
    }
    $session->in .= $chunk;
    $session->seen = time();
    if (strlen($session->in) > 8192 && strpos($session->in, "\n") === false) {
        ftp_reply($session, 500, 'Line too long');
        $session->dead = true;
        return;
    }
    while (($pos = strpos($session->in, "\n")) !== false) {
        $line = substr($session->in, 0, $pos);
        $session->in = substr($session->in, $pos + 1);
        $line = rtrim($line, "\r");
        if ($line === '') {
            continue;
        }
        ftp_command($session, $webRoot, $cfg, $listenBind, $line);
        if ($session->dead) {
            return;
        }
    }
}

function ftp_command(FtpSession $session, string $webRoot, array $cfg, string $listenBind, string $line): void
{
    $space = strpos($line, ' ');
    if ($space === false) {
        $cmd = strtoupper($line);
        $arg = '';
    } else {
        $cmd = strtoupper(substr($line, 0, $space));
        $arg = substr($line, $space + 1);
    }
    $alias = [
        'XCWD' => 'CWD',
        'XMKD' => 'MKD',
        'XRMD' => 'RMD',
        'XPWD' => 'PWD',
        'XCUP' => 'CDUP',
    ];
    if (isset($alias[$cmd])) {
        $cmd = $alias[$cmd];
    }

    if ($session->authed) {
        if (!ftp_refresh_account($session, $webRoot, $cfg)) {
            return;
        }
    }

    $open = [
        'USER' => true, 'PASS' => true, 'QUIT' => true, 'NOOP' => true, 'FEAT' => true,
        'SYST' => true, 'HELP' => true, 'CLNT' => true, 'AUTH' => true, 'HOST' => true,
        'OPTS' => true, 'PBSZ' => true, 'PROT' => true, 'TYPE' => true,
    ];
    if (!$session->authed && !isset($open[$cmd])) {
        ftp_reply($session, 530, 'Please log in');
        return;
    }

    switch ($cmd) {
        case 'USER':
            $session->pendingUser = trim($arg);
            $session->authed = false;
            $session->account = null;
            ftp_reply($session, 331, 'Password required');
            return;
        case 'PASS':
            ftp_auth($session, $webRoot, $cfg, $arg);
            return;
        case 'QUIT':
            ftp_reply($session, 221, 'Goodbye');
            $session->dead = true;
            return;
        case 'NOOP':
            ftp_reply($session, 200, 'NOOP ok');
            return;
        case 'HELP':
            ftp_reply($session, 214, 'Web Stack FTP. Commands: USER PASS PASV EPSV LIST MLSD RETR STOR');
            return;
        case 'SYST':
            ftp_reply($session, 215, 'UNIX Type: L8');
            return;
        case 'FEAT':
            ftp_reply_block($session, 211, [
                'Features:',
                ' SIZE',
                ' MDTM',
                ' REST STREAM',
                ' MLST type*;size*;modify*;perm*;',
                ' MLSD',
                ' UTF8',
                ' TVFS',
                ' EPSV',
                'End',
            ]);
            return;
        case 'OPTS':
            $opt = strtoupper(trim($arg));
            if ($opt === 'UTF8 ON' || $opt === 'UTF-8 ON' || $opt === 'UTF8 OFF') {
                ftp_reply($session, 200, 'UTF8 accepted');
                return;
            }
            ftp_reply($session, 501, 'Option not understood');
            return;
        case 'AUTH':
            ftp_reply($session, 502, 'TLS is not available. Use plain FTP');
            return;
        case 'PBSZ':
        case 'PROT':
            ftp_reply($session, 502, 'TLS is not available. Use plain FTP');
            return;
        case 'HOST':
        case 'CLNT':
            ftp_reply($session, 200, 'OK');
            return;
        case 'TYPE':
            $type = strtoupper(trim($arg));
            if ($type === 'A' || $type === 'A N' || $type === 'I' || $type === 'L 8' || $type === 'L8') {
                $session->type = ($type[0] === 'A') ? 'A' : 'I';
                ftp_reply($session, 200, 'Type set to ' . $session->type);
                return;
            }
            ftp_reply($session, 504, 'Type not implemented');
            return;
        case 'MODE':
            ftp_reply($session, strtoupper(trim($arg)) === 'S' ? 200 : 504, strtoupper(trim($arg)) === 'S' ? 'Mode S' : 'Mode not implemented');
            return;
        case 'STRU':
            ftp_reply($session, strtoupper(trim($arg)) === 'F' ? 200 : 504, strtoupper(trim($arg)) === 'F' ? 'Structure F' : 'Structure not implemented');
            return;
        case 'ALLO':
            ftp_reply($session, 202, 'ALLO ignored');
            return;
        case 'PWD':
            ftp_reply($session, 257, ftp_quote($session->cwd) . ' is current directory');
            return;
        case 'CWD':
            ftp_cwd($session, $arg === '' ? '/' : $arg);
            return;
        case 'CDUP':
            ftp_cwd($session, '..');
            return;
        case 'PASV':
            ftp_enter_pasv($session, $cfg, $listenBind, false);
            return;
        case 'EPSV':
            $epsv = strtoupper(trim($arg));
            if ($epsv === 'ALL') {
                ftp_reply($session, 200, 'EPSV ALL accepted');
                return;
            }
            if ($epsv !== '' && $epsv !== '1') {
                ftp_reply($session, 522, 'Network protocol not supported, use (1)');
                return;
            }
            ftp_enter_pasv($session, $cfg, $listenBind, true);
            return;
        case 'PORT':
            ftp_port($session, $arg, false);
            return;
        case 'EPRT':
            ftp_port($session, $arg, true);
            return;
        case 'REST':
            if (!preg_match('/^\d+$/', trim($arg))) {
                ftp_reply($session, 501, 'REST needs a byte offset');
                return;
            }
            $session->rest = (int)trim($arg);
            ftp_reply($session, 350, 'Restart at ' . $session->rest);
            return;
        case 'LIST':
        case 'NLST':
        case 'MLSD':
            ftp_list_cmd($session, $cmd, $arg);
            return;
        case 'MLST':
            ftp_mlst($session, $arg);
            return;
        case 'RETR':
            ftp_retr($session, $arg);
            return;
        case 'STOR':
        case 'APPE':
            ftp_stor($session, $arg, $cmd === 'APPE');
            return;
        case 'DELE':
            ftp_dele($session, $webRoot, $arg);
            return;
        case 'MKD':
            ftp_mkd($session, $webRoot, $arg);
            return;
        case 'RMD':
            ftp_rmd($session, $webRoot, $arg);
            return;
        case 'RNFR':
            ftp_rnfr($session, $arg);
            return;
        case 'RNTO':
            ftp_rnto($session, $webRoot, $arg);
            return;
        case 'SIZE':
            ftp_cmd_size($session, $arg);
            return;
        case 'MDTM':
            ftp_cmd_mdtm($session, $arg);
            return;
        case 'ABOR':
            if (is_array($session->xfer)) {
                ftp_finish($session, 426, 'Transfer aborted');
                return;
            }
            ftp_reply($session, 225, 'No transfer');
            return;
        case 'STAT':
            ftp_reply($session, 211, 'Web Stack FTP. Type ' . $session->type . '. CWD ' . $session->cwd);
            return;
        default:
            ftp_reply($session, 502, 'Command not implemented');
            return;
    }
}

function ftp_refresh_account(FtpSession $session, string $webRoot, array $cfg): bool
{
    $name = (string)($session->account['name'] ?? '');
    $idx = ftp_find_user($cfg, $name);
    if ($idx === null || empty($cfg['users'][$idx]['enabled'])) {
        ftp_reply($session, 530, 'Account is not available');
        $session->dead = true;
        return false;
    }
    $user = $cfg['users'][$idx];
    $home = realpath((string)$user['home']);
    if ($home === false || !is_dir($home) || !ftp_path_inside($webRoot, $home)) {
        ftp_reply($session, 530, 'Home folder is not available');
        $session->dead = true;
        return false;
    }
    $home = str_replace('\\', '/', $home);
    $prev = (string)($session->account['home_real'] ?? '');
    $session->account['write'] = !empty($user['write']);
    $session->account['home_real'] = $home;
    $session->account['name'] = (string)$user['name'];
    if ($prev !== '' && strcasecmp($prev, $home) !== 0) {
        $session->cwd = '/';
    }
    return true;
}

function ftp_auth(FtpSession $session, string $webRoot, array $cfg, string $password): void
{
    $name = $session->pendingUser;
    $idx = $name === '' ? null : ftp_find_user($cfg, $name);
    $user = $idx !== null ? $cfg['users'][$idx] : null;
    $ok = false;
    if (is_array($user) && !empty($user['enabled'])) {
        $ok = password_verify($password, (string)$user['password_hash']);
    }
    if (!$ok || $user === null) {
        $session->fails++;
        ftp_log($webRoot, 'login failed for ' . ($name === '' ? '(blank)' : $name));
        ftp_reply($session, 530, 'Login incorrect');
        if ($session->fails >= 5) {
            ftp_reply($session, 421, 'Too many failed logins');
            $session->dead = true;
        }
        return;
    }
    $home = realpath((string)$user['home']);
    if ($home === false || !is_dir($home) || !ftp_path_inside($webRoot, $home)) {
        ftp_log($webRoot, 'login refused for ' . $user['name'] . ': home is outside the stack or missing');
        ftp_reply($session, 530, 'Home folder is not available');
        return;
    }
    $session->authed = true;
    $session->fails = 0;
    $session->cwd = '/';
    $session->account = [
        'name' => (string)$user['name'],
        'write' => !empty($user['write']),
        'home_real' => str_replace('\\', '/', $home),
    ];
    ftp_log($webRoot, 'login ok ' . $user['name']);
    ftp_reply($session, 230, 'Logged in');
}

function ftp_quote(string $path): string
{
    return '"' . str_replace('"', '""', $path) . '"';
}

/** @return array{0:string,1:string}|null */
function ftp_resolve(FtpSession $session, string $arg): ?array
{
    if (strpos($arg, "\0") !== false || preg_match('/[<>:"|?*]/', $arg)) {
        return null;
    }
    $arg = str_replace('\\', '/', $arg);
    if ($arg === '') {
        $arg = $session->cwd;
    }
    if ($arg[0] !== '/') {
        $base = $session->cwd === '/' ? '' : $session->cwd;
        $arg = $base . '/' . $arg;
    }
    $parts = [];
    foreach (explode('/', $arg) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    $virt = '/' . implode('/', $parts);
    if ($virt === '/') {
        $virt = '/';
    }
    $home = (string)$session->account['home_real'];
    $full = $home . ($virt === '/' ? '' : str_replace('/', DIRECTORY_SEPARATOR, $virt));
    return [$virt, $full];
}

function ftp_jailed(FtpSession $session, string $full): bool
{
    $home = (string)$session->account['home_real'];
    $check = file_exists($full) ? $full : dirname($full);
    $real = realpath($check);
    if ($real === false) {
        return false;
    }
    return ftp_path_inside($home, $real);
}

function ftp_cwd(FtpSession $session, string $arg): void
{
    $resolved = ftp_resolve($session, $arg);
    if ($resolved === null) {
        ftp_reply($session, 550, 'Path not allowed');
        return;
    }
    [$virt, $full] = $resolved;
    if (!ftp_jailed($session, $full) || !is_dir($full)) {
        ftp_reply($session, 550, 'Directory not found');
        return;
    }
    $session->cwd = $virt;
    ftp_reply($session, 250, 'Directory changed to ' . $virt);
}

function ftp_enter_pasv(FtpSession $session, array $cfg, string $listenBind, bool $epsv): void
{
    ftp_drop_data($session);
    $min = (int)$cfg['pasv_min'];
    $max = (int)$cfg['pasv_max'];
    $ctx = stream_context_create(['socket' => ['so_reuseaddr' => true]]);
    $sock = null;
    $port = 0;
    for ($p = $min; $p <= $max; $p++) {
        $errno = 0;
        $errstr = '';
        $try = @stream_socket_server('tcp://' . $listenBind . ':' . $p, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
        if (is_resource($try)) {
            $sock = $try;
            $port = $p;
            break;
        }
    }
    if (!$sock) {
        ftp_reply($session, 425, 'No passive port is free');
        return;
    }
    stream_set_blocking($sock, false);
    $session->pasv = $sock;
    $session->active = null;
    if ($epsv) {
        ftp_reply($session, 229, 'Entering extended passive mode (|||' . $port . '|)');
        return;
    }
    $ip = ftp_local_ip($session->ctrl);
    $octets = str_replace('.', ',', $ip);
    $p1 = intdiv($port, 256);
    $p2 = $port % 256;
    ftp_reply($session, 227, 'Entering passive mode (' . $octets . ',' . $p1 . ',' . $p2 . ')');
}

function ftp_port(FtpSession $session, string $arg, bool $eprt): void
{
    $ip = '';
    $port = 0;
    if ($eprt) {
        if (!preg_match('/^\|(\d+)\|([^|]+)\|(\d+)\|$/', trim($arg), $m)) {
            ftp_reply($session, 501, 'EPRT syntax error');
            return;
        }
        if ($m[1] !== '1') {
            ftp_reply($session, 522, 'Network protocol not supported, use (1)');
            return;
        }
        $ip = $m[2];
        $port = (int)$m[3];
    } else {
        if (!preg_match('/^(\d{1,3}),(\d{1,3}),(\d{1,3}),(\d{1,3}),(\d{1,3}),(\d{1,3})$/', trim($arg), $m)) {
            ftp_reply($session, 501, 'PORT syntax error');
            return;
        }
        foreach ([1, 2, 3, 4, 5, 6] as $i) {
            if ((int)$m[$i] > 255) {
                ftp_reply($session, 501, 'PORT syntax error');
                return;
            }
        }
        $ip = $m[1] . '.' . $m[2] . '.' . $m[3] . '.' . $m[4];
        $port = ((int)$m[5] * 256) + (int)$m[6];
    }
    $peer = ftp_peer_ip($session->ctrl);
    if ($peer !== '' && $ip !== $peer) {
        ftp_reply($session, 500, 'PORT address must match the client');
        return;
    }
    if ($port < 1 || $port > 65535) {
        ftp_reply($session, 501, 'PORT syntax error');
        return;
    }
    ftp_drop_data($session);
    $session->active = $ip . ':' . $port;
    ftp_reply($session, 200, 'PORT command successful');
}

function ftp_drop_data(FtpSession $session): void
{
    if (is_array($session->xfer) && isset($session->xfer['fh']) && is_resource($session->xfer['fh'])) {
        @fclose($session->xfer['fh']);
    }
    $session->xfer = null;
    if (is_resource($session->data)) {
        @fclose($session->data);
    }
    $session->data = null;
    if (is_resource($session->pasv)) {
        @fclose($session->pasv);
    }
    $session->pasv = null;
    $session->active = null;
}

function ftp_list_arg(string $arg): string
{
    $arg = trim($arg);
    while ($arg !== '' && $arg[0] === '-') {
        $space = strpos($arg, ' ');
        if ($space === false) {
            return '';
        }
        $arg = trim(substr($arg, $space + 1));
    }
    return $arg;
}

function ftp_list_cmd(FtpSession $session, string $cmd, string $arg): void
{
    $pathArg = ftp_list_arg($arg);
    $resolved = ftp_resolve($session, $pathArg === '' ? $session->cwd : $pathArg);
    if ($resolved === null) {
        ftp_reply($session, 550, 'Path not allowed');
        return;
    }
    [$virt, $full] = $resolved;
    if (!ftp_jailed($session, $full) || !file_exists($full)) {
        ftp_reply($session, 550, 'Path not found');
        return;
    }
    $lines = [];
    if (is_dir($full)) {
        $rows = ftp_dir_rows($session, $full);
        if ($cmd !== 'NLST') {
            $self = @stat($full);
            $lines[] = ftp_format_row($cmd, '.', true, 0, $self ? (int)$self['mtime'] : time(), !empty($session->account['write']), true);
            $lines[] = ftp_format_row($cmd, '..', true, 0, $self ? (int)$self['mtime'] : time(), !empty($session->account['write']), false);
        }
        foreach ($rows as $row) {
            $lines[] = ftp_format_row($cmd, $row['name'], $row['dir'], $row['size'], $row['mtime'], !empty($session->account['write']), false);
        }
    } else {
        $st = @stat($full);
        $lines[] = ftp_format_row(
            $cmd,
            basename(str_replace('\\', '/', $virt)),
            false,
            $st ? (int)$st['size'] : 0,
            $st ? (int)$st['mtime'] : time(),
            !empty($session->account['write']),
            false
        );
    }
    $body = $lines ? implode("\r\n", $lines) . "\r\n" : '';
    ftp_begin_out($session, null, $body);
}

/** @return array<int,array{name:string,dir:bool,size:int,mtime:int}> */
function ftp_dir_rows(FtpSession $session, string $dir): array
{
    $home = (string)$session->account['home_real'];
    $names = @scandir($dir);
    if ($names === false) {
        return [];
    }
    $rows = [];
    foreach ($names as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $full = $dir . DIRECTORY_SEPARATOR . $name;
        $real = realpath($full);
        if ($real === false || !ftp_path_inside($home, $real)) {
            continue;
        }
        $st = @stat($full);
        if ($st === false) {
            continue;
        }
        $safe = str_replace(["\r", "\n"], '?', $name);
        $rows[] = [
            'name' => $safe,
            'dir' => is_dir($full),
            'size' => is_dir($full) ? 0 : (int)$st['size'],
            'mtime' => (int)$st['mtime'],
        ];
    }
    return $rows;
}

function ftp_format_row(string $cmd, string $name, bool $dir, int $size, int $mtime, bool $writable, bool $cdir): string
{
    if ($cmd === 'NLST') {
        return $name;
    }
    if ($cmd === 'MLSD') {
        $type = $cdir ? 'cdir' : ($name === '..' ? 'pdir' : ($dir ? 'dir' : 'file'));
        $perm = $dir ? ($writable ? 'elcmpf' : 'el') : ($writable ? 'adfrw' : 'r');
        $facts = 'type=' . $type . ';modify=' . gmdate('YmdHis', $mtime) . ';perm=' . $perm . ';';
        if (!$dir) {
            $facts = 'type=' . $type . ';size=' . $size . ';modify=' . gmdate('YmdHis', $mtime) . ';perm=' . $perm . ';';
        }
        return $facts . ' ' . $name;
    }
    $mode = $dir
        ? ($writable ? 'drwxrwxrwx' : 'dr-xr-xr-x')
        : ($writable ? '-rw-rw-rw-' : '-r--r--r--');
    $mon = date('M', $mtime);
    $day = str_pad(date('j', $mtime), 2, ' ', STR_PAD_LEFT);
    $rest = date('Y', $mtime) === date('Y') ? date('H:i', $mtime) : sprintf('%5d', (int)date('Y', $mtime));
    return sprintf('%s 1 %-8s %-8s %12d %s %s %s', $mode, 'web', 'web', $size, $mon, $day, $rest . ' ' . $name);
}

function ftp_mlst(FtpSession $session, string $arg): void
{
    $resolved = ftp_resolve($session, $arg === '' ? $session->cwd : $arg);
    if ($resolved === null) {
        ftp_reply($session, 550, 'Path not allowed');
        return;
    }
    [$virt, $full] = $resolved;
    if (!ftp_jailed($session, $full) || !file_exists($full)) {
        ftp_reply($session, 550, 'Path not found');
        return;
    }
    $st = @stat($full);
    $dir = is_dir($full);
    $name = $virt === '/' ? '/' : basename($virt);
    $fact = ftp_format_row('MLSD', $name, $dir, $st ? (int)$st['size'] : 0, $st ? (int)$st['mtime'] : time(), !empty($session->account['write']), $virt === '/');
    if ($session->dead || !is_resource($session->ctrl)) {
        return;
    }
    stream_set_blocking($session->ctrl, true);
    @fwrite($session->ctrl, "250-Listing " . $virt . "\r\n " . $fact . "\r\n250 End\r\n");
    stream_set_blocking($session->ctrl, false);
}

function ftp_retr(FtpSession $session, string $arg): void
{
    $rest = $session->rest;
    $session->rest = 0;
    $resolved = ftp_resolve($session, $arg);
    if ($resolved === null) {
        ftp_reply($session, 550, 'Path not allowed');
        return;
    }
    [$virt, $full] = $resolved;
    if (!ftp_jailed($session, $full) || !is_file($full)) {
        ftp_reply($session, 550, 'File not found');
        return;
    }
    $fh = @fopen($full, 'rb');
    if (!$fh) {
        ftp_reply($session, 550, 'Cannot read file');
        return;
    }
    if ($rest > 0) {
        @fseek($fh, $rest);
    }
    ftp_begin_out($session, $fh, '');
    unset($virt);
}

function ftp_stor(FtpSession $session, string $arg, bool $append): void
{
    $rest = $session->rest;
    $session->rest = 0;
    if (empty($session->account['write'])) {
        ftp_reply($session, 550, 'This account is read-only');
        return;
    }
    $resolved = ftp_resolve($session, $arg);
    if ($resolved === null) {
        ftp_reply($session, 550, 'Path not allowed');
        return;
    }
    [$virt, $full] = $resolved;
    if (!ftp_jailed($session, $full) || is_dir($full)) {
        ftp_reply($session, 550, 'Cannot store there');
        return;
    }
    $parent = dirname($full);
    if (!is_dir($parent) || !ftp_jailed($session, $parent)) {
        ftp_reply($session, 550, 'Folder not found');
        return;
    }
    if ($append) {
        $fh = @fopen($full, 'ab');
    } elseif ($rest > 0 && is_file($full)) {
        $fh = @fopen($full, 'c+b');
        if ($fh) {
            @fseek($fh, $rest);
        }
    } else {
        $fh = @fopen($full, 'wb');
    }
    if (!$fh) {
        ftp_reply($session, 550, 'Cannot write file');
        return;
    }
    unset($virt);
    ftp_begin_in($session, $fh);
}

function ftp_need_write(FtpSession $session): bool
{
    if (!empty($session->account['write'])) {
        return true;
    }
    ftp_reply($session, 550, 'This account is read-only');
    return false;
}

function ftp_dele(FtpSession $session, string $webRoot, string $arg): void
{
    if (!ftp_need_write($session)) {
        return;
    }
    $resolved = ftp_resolve($session, $arg);
    if ($resolved === null) {
        ftp_reply($session, 550, 'Path not allowed');
        return;
    }
    [$virt, $full] = $resolved;
    if (!ftp_jailed($session, $full) || !is_file($full) || !@unlink($full)) {
        ftp_reply($session, 550, 'Cannot delete file');
        return;
    }
    ftp_log($webRoot, 'deleted ' . $virt . ' by ' . $session->account['name']);
    ftp_reply($session, 250, 'File deleted');
}

function ftp_mkd(FtpSession $session, string $webRoot, string $arg): void
{
    if (!ftp_need_write($session)) {
        return;
    }
    $resolved = ftp_resolve($session, $arg);
    if ($resolved === null) {
        ftp_reply($session, 550, 'Path not allowed');
        return;
    }
    [$virt, $full] = $resolved;
    $parent = dirname($full);
    if (!ftp_jailed($session, $parent) || !is_dir($parent) || is_file($full) || is_dir($full) || !@mkdir($full)) {
        ftp_reply($session, 550, 'Cannot create folder');
        return;
    }
    ftp_log($webRoot, 'mkdir ' . $virt . ' by ' . $session->account['name']);
    ftp_reply($session, 257, ftp_quote($virt) . ' created');
}

function ftp_rmd(FtpSession $session, string $webRoot, string $arg): void
{
    if (!ftp_need_write($session)) {
        return;
    }
    $resolved = ftp_resolve($session, $arg);
    if ($resolved === null) {
        ftp_reply($session, 550, 'Path not allowed');
        return;
    }
    [$virt, $full] = $resolved;
    $home = rtrim((string)$session->account['home_real'], '/');
    if (rtrim(str_replace('\\', '/', $full), '/') === $home || !ftp_jailed($session, $full) || !is_dir($full) || !@rmdir($full)) {
        ftp_reply($session, 550, 'Cannot remove folder');
        return;
    }
    ftp_log($webRoot, 'rmdir ' . $virt . ' by ' . $session->account['name']);
    ftp_reply($session, 250, 'Folder removed');
}

function ftp_rnfr(FtpSession $session, string $arg): void
{
    if (!ftp_need_write($session)) {
        return;
    }
    $resolved = ftp_resolve($session, $arg);
    if ($resolved === null) {
        ftp_reply($session, 550, 'Path not allowed');
        return;
    }
    [$virt, $full] = $resolved;
    if (!ftp_jailed($session, $full) || !file_exists($full)) {
        ftp_reply($session, 550, 'Path not found');
        return;
    }
    $session->rename = ['virt' => $virt, 'full' => $full];
    ftp_reply($session, 350, 'Ready for new name');
}

function ftp_rnto(FtpSession $session, string $webRoot, string $arg): void
{
    if (!ftp_need_write($session)) {
        $session->rename = null;
        return;
    }
    if (!is_array($session->rename)) {
        ftp_reply($session, 503, 'Send RNFR first');
        return;
    }
    $from = $session->rename;
    $session->rename = null;
    $resolved = ftp_resolve($session, $arg);
    if ($resolved === null) {
        ftp_reply($session, 550, 'Path not allowed');
        return;
    }
    [$virt, $full] = $resolved;
    if (!ftp_jailed($session, $full) || is_dir($full) || !@rename($from['full'], $full)) {
        ftp_reply($session, 550, 'Cannot rename');
        return;
    }
    ftp_log($webRoot, 'rename ' . $from['virt'] . ' -> ' . $virt . ' by ' . $session->account['name']);
    ftp_reply($session, 250, 'Renamed');
}

function ftp_cmd_size(FtpSession $session, string $arg): void
{
    $resolved = ftp_resolve($session, $arg);
    if ($resolved === null) {
        ftp_reply($session, 550, 'Path not allowed');
        return;
    }
    [, $full] = $resolved;
    if (!ftp_jailed($session, $full) || !is_file($full)) {
        ftp_reply($session, 550, 'File not found');
        return;
    }
    $size = @filesize($full);
    if ($size === false) {
        ftp_reply($session, 550, 'File not found');
        return;
    }
    ftp_reply($session, 213, (string)$size);
}

function ftp_cmd_mdtm(FtpSession $session, string $arg): void
{
    $resolved = ftp_resolve($session, $arg);
    if ($resolved === null) {
        ftp_reply($session, 550, 'Path not allowed');
        return;
    }
    [, $full] = $resolved;
    if (!ftp_jailed($session, $full) || !is_file($full)) {
        ftp_reply($session, 550, 'File not found');
        return;
    }
    $mtime = @filemtime($full);
    if ($mtime === false) {
        ftp_reply($session, 550, 'File not found');
        return;
    }
    ftp_reply($session, 213, gmdate('YmdHis', $mtime));
}

/** @param resource|null $fh */
function ftp_begin_out(FtpSession $session, $fh, string $body): void
{
    if (!$session->data && !$session->pasv && !$session->active) {
        if (is_resource($fh)) {
            @fclose($fh);
        }
        ftp_reply($session, 425, 'Use PASV or PORT first');
        return;
    }
    if (!$session->data && $session->active) {
        $errno = 0;
        $errstr = '';
        $remote = $session->active;
        $session->active = null;
        $data = @stream_socket_client('tcp://' . $remote, $errno, $errstr, 5);
        if (!is_resource($data)) {
            if (is_resource($fh)) {
                @fclose($fh);
            }
            ftp_reply($session, 425, 'Cannot open data connection');
            return;
        }
        stream_set_blocking($data, false);
        $session->data = $data;
    }
    $session->xfer = [
        'dir' => 'out',
        'fh' => $fh,
        'out' => $body,
        'ascii' => $session->type === 'A',
        'carry' => '',
        'eof' => !is_resource($fh),
        'wait' => !is_resource($session->data),
        'since' => time(),
    ];
    $mode = $session->type === 'A' ? 'ASCII' : 'binary';
    ftp_reply($session, 150, 'Opening ' . $mode . ' mode data connection');
}

/** @param resource $fh */
function ftp_begin_in(FtpSession $session, $fh): void
{
    if (!$session->data && !$session->pasv && !$session->active) {
        @fclose($fh);
        ftp_reply($session, 425, 'Use PASV or PORT first');
        return;
    }
    if (!$session->data && $session->active) {
        $errno = 0;
        $errstr = '';
        $remote = $session->active;
        $session->active = null;
        $data = @stream_socket_client('tcp://' . $remote, $errno, $errstr, 5);
        if (!is_resource($data)) {
            @fclose($fh);
            ftp_reply($session, 425, 'Cannot open data connection');
            return;
        }
        stream_set_blocking($data, false);
        $session->data = $data;
    }
    $session->xfer = [
        'dir' => 'in',
        'fh' => $fh,
        'out' => '',
        'ascii' => $session->type === 'A',
        'carry' => '',
        'eof' => false,
        'wait' => !is_resource($session->data),
        'since' => time(),
    ];
    $mode = $session->type === 'A' ? 'ASCII' : 'binary';
    ftp_reply($session, 150, 'Opening ' . $mode . ' mode data connection');
}

function ftp_pump(FtpSession $session, array $writable, array $readable): void
{
    $xfer = $session->xfer;
    if (!is_array($xfer)) {
        return;
    }
    if (!empty($xfer['wait'])) {
        if (is_resource($session->data)) {
            $xfer['wait'] = false;
            $session->xfer = $xfer;
        } elseif (time() - (int)$xfer['since'] > 20) {
            ftp_finish($session, 425, 'Data connection timed out');
            return;
        } else {
            return;
        }
    }
    if ($xfer['dir'] === 'out') {
        ftp_pump_out($session, $writable);
        return;
    }
    if (is_resource($session->data) && in_array($session->data, $readable, true)) {
        ftp_pump_in($session);
    }
}

function ftp_pump_out(FtpSession $session, array $writable): void
{
    $xfer = $session->xfer;
    if (!is_array($xfer)) {
        return;
    }
    if (is_resource($xfer['fh']) && empty($xfer['eof']) && strlen((string)$xfer['out']) < 65536) {
        $chunk = @fread($xfer['fh'], 32768);
        if ($chunk === false || $chunk === '') {
            $xfer['eof'] = true;
            if (!empty($xfer['ascii']) && $xfer['carry'] === "\r") {
                $xfer['out'] .= "\r\n";
                $xfer['carry'] = '';
            }
        } else {
            if (!empty($xfer['ascii'])) {
                $carry = (string)$xfer['carry'];
                $chunk = ftp_to_crlf($carry, $chunk);
                $xfer['carry'] = $carry;
            }
            $xfer['out'] .= $chunk;
            if (feof($xfer['fh'])) {
                $xfer['eof'] = true;
                if (!empty($xfer['ascii']) && $xfer['carry'] === "\r") {
                    $xfer['out'] .= "\r\n";
                    $xfer['carry'] = '';
                }
            }
        }
    }
    $session->xfer = $xfer;
    if ($xfer['out'] !== '' && is_resource($session->data) && in_array($session->data, $writable, true)) {
        $n = @fwrite($session->data, (string)$xfer['out']);
        if ($n === false) {
            ftp_finish($session, 426, 'Data connection closed');
            return;
        }
        if ($n > 0) {
            $xfer['out'] = (string)substr((string)$xfer['out'], $n);
            $session->xfer = $xfer;
            $session->seen = time();
        }
    }
    $xfer = $session->xfer;
    if (is_array($xfer) && !empty($xfer['eof']) && $xfer['out'] === '') {
        ftp_finish($session, 226, 'Transfer complete');
    }
}

function ftp_pump_in(FtpSession $session): void
{
    $xfer = $session->xfer;
    if (!is_array($xfer) || !is_resource($session->data) || !is_resource($xfer['fh'])) {
        ftp_finish($session, 426, 'Data connection closed');
        return;
    }
    $chunk = @fread($session->data, 32768);
    if ($chunk === false || $chunk === '') {
        if (!@feof($session->data)) {
            return;
        }
        if (!empty($xfer['ascii']) && $xfer['carry'] !== '') {
            $tail = $xfer['carry'] === "\r" ? "\n" : (string)$xfer['carry'];
            @fwrite($xfer['fh'], $tail);
        }
        ftp_finish($session, 226, 'Transfer complete');
        return;
    }
    if (!empty($xfer['ascii'])) {
        $carry = (string)$xfer['carry'];
        $chunk = ftp_from_crlf($carry, $chunk);
        $xfer['carry'] = $carry;
        $session->xfer = $xfer;
    }
    $written = @fwrite($xfer['fh'], $chunk);
    if ($written === false) {
        ftp_finish($session, 426, 'Cannot write file');
        return;
    }
    $session->seen = time();
}

function ftp_finish(FtpSession $session, int $code, string $text): void
{
    if (is_array($session->xfer) && isset($session->xfer['fh']) && is_resource($session->xfer['fh'])) {
        @fclose($session->xfer['fh']);
    }
    $session->xfer = null;
    $session->rest = 0;
    if (is_resource($session->data)) {
        @fclose($session->data);
    }
    $session->data = null;
    if (is_resource($session->pasv)) {
        @fclose($session->pasv);
    }
    $session->pasv = null;
    ftp_reply($session, $code, $text);
}

function ftp_to_crlf(string &$carry, string $chunk): string
{
    $chunk = $carry . $chunk;
    $carry = '';
    if ($chunk !== '' && substr($chunk, -1) === "\r") {
        $carry = "\r";
        $chunk = substr($chunk, 0, -1);
    }
    $chunk = str_replace("\r\n", "\n", $chunk);
    $chunk = str_replace("\r", "\n", $chunk);
    return str_replace("\n", "\r\n", $chunk);
}

function ftp_from_crlf(string &$carry, string $chunk): string
{
    $chunk = $carry . $chunk;
    $carry = '';
    if ($chunk !== '' && substr($chunk, -1) === "\r") {
        $carry = "\r";
        $chunk = substr($chunk, 0, -1);
    }
    $chunk = str_replace("\r\n", "\n", $chunk);
    return str_replace("\r", "\n", $chunk);
}
