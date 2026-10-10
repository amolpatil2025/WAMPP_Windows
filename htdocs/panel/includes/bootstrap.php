<?php
declare(strict_types=1);

const PANEL_ROOT = __DIR__ . '/..';

// Install root = folder that contains htdocs/ (not a fixed C:/web).
define('WEB_ROOT', str_replace('\\', '/', dirname(__DIR__, 3)));
define('SCRIPTS_DIR', WEB_ROOT . '/scripts');
define('APACHE_BIN', WEB_ROOT . '/Apache24/bin/httpd.exe');
define('APACHE_ROOT', WEB_ROOT . '/Apache24');

define('MAILPIT_BIN', WEB_ROOT . '/mailpit/mailpit.exe');
const MAILPIT_SMTP_PORT = 1025;
const MAILPIT_UI_PORT = 8025;
const MAILPIT_UI_URL = 'http://127.0.0.1:8025/';
define('MAILPIT_DATA', WEB_ROOT . '/mailpit/data');

const DB_HOST = '127.0.0.1';
const DB_USER = 'root';
const DB_PASS = 'root';
const DB_PORT = 3306;

define('PHP_VERSIONS', [
    '7.4' => [
        'label' => 'PHP 7.4',
        'dir' => WEB_ROOT . '/php7.4.33',
        'port' => 9074,
        'hosts' => ['php7', 'localhost7', 'localhost.php7'],
    ],
    '8.0' => [
        'label' => 'PHP 8.0',
        'dir' => WEB_ROOT . '/php8.0.30',
        'port' => 9080,
        'hosts' => ['php8', 'localhost8', 'localhost.php8'],
    ],
    '8.4' => [
        'label' => 'PHP 8.4',
        'dir' => WEB_ROOT . '/php8.4.26',
        'port' => 9084,
        'hosts' => ['php8.4', 'localhost84', 'localhost.php84'],
    ],
]);

const LOCALHOST_DOMAINS = ['localhost', 'latest', 'localhost.latest'];

const SETTINGS_FILE = PANEL_ROOT . '/data/settings.json';

define('CONFIG_FILES', [
    'httpd' => [
        'label' => 'Apache httpd.conf',
        'path' => APACHE_ROOT . '/conf/httpd.conf',
    ],
    'php-fpm' => [
        'label' => 'Apache PHP vhosts',
        'path' => APACHE_ROOT . '/conf/extra/httpd-php-fpm.conf',
    ],
    'php74' => [
        'label' => 'php.ini (7.4)',
        'path' => WEB_ROOT . '/php7.4.33/php.ini',
    ],
    'php80' => [
        'label' => 'php.ini (8.0)',
        'path' => WEB_ROOT . '/php8.0.30/php.ini',
    ],
    'php84' => [
        'label' => 'php.ini (8.4)',
        'path' => WEB_ROOT . '/php8.4.26/php.ini',
    ],
]);

define('LOG_FILES', [
    'error' => APACHE_ROOT . '/logs/error_log',
    'php74' => APACHE_ROOT . '/logs/php74-error.log',
    'php80' => APACHE_ROOT . '/logs/php80-error.log',
    'php84' => APACHE_ROOT . '/logs/php84-error.log',
]);

/** Structured log catalog: source -> kind -> path */
define('LOG_SOURCES', [
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
    'php74' => [
        'label' => 'PHP 7.4',
        'domains' => ['php7', 'localhost7', 'localhost.php7'],
        'kinds' => [
            'error' => ['label' => 'Vhost error', 'path' => APACHE_ROOT . '/logs/php74-error.log', 'format' => 'apache_error'],
            'access' => ['label' => 'Access', 'path' => APACHE_ROOT . '/logs/php74-access.log', 'format' => 'apache_access'],
            'php' => ['label' => 'PHP runtime', 'path' => WEB_ROOT . '/php7.4.33/logs/php_errors.log', 'format' => 'php_error'],
        ],
    ],
    'php80' => [
        'label' => 'PHP 8.0',
        'domains' => ['php8', 'localhost8', 'localhost.php8'],
        'kinds' => [
            'error' => ['label' => 'Vhost error', 'path' => APACHE_ROOT . '/logs/php80-error.log', 'format' => 'apache_error'],
            'access' => ['label' => 'Access', 'path' => APACHE_ROOT . '/logs/php80-access.log', 'format' => 'apache_access'],
            'php' => ['label' => 'PHP runtime', 'path' => WEB_ROOT . '/php8.0.30/logs/php_errors.log', 'format' => 'php_error'],
        ],
    ],
    'php84' => [
        'label' => 'PHP 8.4',
        'domains' => ['php8.4', 'localhost84', 'localhost.php84'],
        'kinds' => [
            'error' => ['label' => 'Vhost error', 'path' => APACHE_ROOT . '/logs/php84-error.log', 'format' => 'apache_error'],
            'access' => ['label' => 'Access', 'path' => APACHE_ROOT . '/logs/php84-access.log', 'format' => 'apache_access'],
            'php' => ['label' => 'PHP runtime', 'path' => WEB_ROOT . '/php8.4.26/logs/php_errors.log', 'format' => 'php_error'],
        ],
    ],
]);

/** Keep Apache Define WEBROOT in sync with this install folder. */
function ensure_apache_install_root(): void
{
    $confDir = APACHE_ROOT . '/conf';
    if (!is_dir($confDir)) {
        return;
    }
    $logsDir = APACHE_ROOT . '/logs';
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0777, true);
    }
    $path = $confDir . '/install-root.conf';
    $body = "# Auto-generated — do not edit. Install root for this copy of the stack.\n"
        . 'Define WEBROOT "' . WEB_ROOT . "\"\n"
        . 'Define SRVROOT "' . WEB_ROOT . "/Apache24\"\n";
    $current = is_file($path) ? (string)@file_get_contents($path) : '';
    if ($current !== $body) {
        @file_put_contents($path, $body);
    }
}

/**
 * Pin php.ini filesystem paths to the PHP folder on THIS machine.
 * Safe when the project is copied to another device / drive / folder name.
 */
function phpini_set_directive(string $content, string $key, string $value, bool $quote = false): string
{
    $formatted = $quote ? '"' . $value . '"' : $value;
    $line = $key . ' = ' . $formatted;
    $qKey = preg_quote($key, '/');

    // Active lines only (do not touch commented examples like ";     session.save_path = ...").
    $active = '/^\s*' . $qKey . '\s*=.*$/mi';
    if (preg_match($active, $content)) {
        $content = (string)preg_replace($active, $line, $content, 1);
        // Drop duplicate active assignments for this key.
        $seen = false;
        $out = [];
        foreach (preg_split('/\R/', $content) ?: [] as $row) {
            if (preg_match('/^\s*' . $qKey . '\s*=/i', $row)) {
                if ($seen) {
                    continue;
                }
                $seen = true;
                $out[] = $line;
                continue;
            }
            $out[] = $row;
        }
        return implode("\n", $out);
    }

    // Uncomment a plain ";key = ..." stub (not indented doc examples).
    $stub = '/^;' . $qKey . '\s*=.*$/mi';
    if (preg_match($stub, $content)) {
        return (string)preg_replace($stub, $line, $content, 1);
    }

    return rtrim($content) . "\n" . $line . "\n";
}

function ensure_php_runtime_paths(): void
{
    $winRoot = str_replace('/', '\\', WEB_ROOT);
    foreach (PHP_VERSIONS as $meta) {
        $dir = str_replace('\\', '/', $meta['dir']);
        if (!is_dir($dir)) {
            continue;
        }
        foreach (['tmp', 'logs'] as $sub) {
            $path = $dir . '/' . $sub;
            if (!is_dir($path)) {
                @mkdir($path, 0777, true);
            }
        }
        $ini = $dir . '/php.ini';
        if (!is_file($ini)) {
            continue;
        }
        $raw = (string)@file_get_contents($ini);
        if ($raw === '') {
            continue;
        }
        $updated = $raw;
        $folder = basename($dir);

        // Rewrite absolute paths from another device (active lines only).
        $pathRe = '#[A-Za-z]:[^\\r\\n"\']*[\\\\/]' . preg_quote($folder, '#') . '(?=[\\\\/])#i';
        $legacy = ['C:/web' => WEB_ROOT, 'C:\\web' => $winRoot, 'c:/web' => WEB_ROOT, 'c:\\web' => $winRoot];
        $lines = preg_split('/\R/', $updated) ?: [];
        foreach ($lines as $i => $row) {
            $trim = ltrim($row);
            if ($trim === '' || strpos($trim, ';') === 0) {
                continue;
            }
            $row = (string)preg_replace($pathRe, $dir, $row);
            $row = str_replace(array_keys($legacy), array_values($legacy), $row);
            $lines[$i] = $row;
        }
        $updated = implode("\n", $lines);

        $tmp = $dir . '/tmp';
        $log = $dir . '/logs/php_errors.log';
        $updated = phpini_set_directive($updated, 'session.save_path', $tmp, true);
        $updated = phpini_set_directive($updated, 'error_log', $log, false);
        $updated = phpini_set_directive($updated, 'upload_tmp_dir', $tmp, true);
        $updated = phpini_set_directive($updated, 'sys_temp_dir', $tmp, true);
        $updated = phpini_set_directive($updated, 'soap.wsdl_cache_dir', $tmp, true);

        if ($updated !== $raw) {
            @file_put_contents($ini, $updated);
        }
    }
}

ensure_apache_install_root();
ensure_php_runtime_paths();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');
if (!$isCli && !in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Panel is only available from localhost.');
}

function json_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function port_listening(int $port): bool
{
    $out = [];
    // /C: keeps the trailing space literal — without it findstr splits on spaces
    // and ":80 " becomes ":80", which falsely matches Mailpit :8025.
    exec('netstat -ano | findstr /C:":' . $port . ' " | findstr LISTENING', $out);
    return $out !== [];
}

function process_running(string $image): bool
{
    $out = [];
    exec('tasklist /FI "IMAGENAME eq ' . $image . '" /NH', $out);
    $joined = implode("\n", $out);
    return stripos($joined, $image) !== false;
}

function run_cmd(string $command): array
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($command, $descriptor, $pipes, WEB_ROOT, null, ['bypass_shell' => false]);
    if (!is_resource($proc)) {
        return ['ok' => false, 'output' => 'Failed to start process', 'code' => -1];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $output = trim($stdout . (strlen((string)$stderr) ? "\n" . $stderr : ''));
    return ['ok' => $code === 0, 'output' => $output, 'code' => $code];
}

function panel_settings(): array
{
    $defaults = ['default_php' => '8.4'];
    if (!is_file(SETTINGS_FILE)) {
        return $defaults;
    }
    $raw = @file_get_contents(SETTINGS_FILE);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return $defaults;
    }
    $out = array_merge($defaults, $data);
    if (!isset(PHP_VERSIONS[$out['default_php']])) {
        $out['default_php'] = '8.4';
    }
    return $out;
}

function save_panel_settings(array $settings): bool
{
    $dir = dirname(SETTINGS_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return file_put_contents(SETTINGS_FILE, $json . "\n") !== false;
}

function default_php_version(): string
{
    return panel_settings()['default_php'];
}

function pids_on_port(int $port): array
{
    $out = [];
    exec('netstat -ano | findstr /C:":' . $port . ' " | findstr LISTENING', $out);
    $pids = [];
    foreach ($out as $line) {
        if (preg_match('/\s(\d+)\s*$/', $line, $m)) {
            $pids[(int)$m[1]] = true;
        }
    }
    return array_map('intval', array_keys($pids));
}

function start_hidden(string $command): void
{
    $runner = SCRIPTS_DIR . '/run-hidden.vbs';
    // Quote the whole command as a single VBS argument.
    $cmd = 'wscript //B //Nologo "' . $runner . '" "' . str_replace('"', '""', $command) . '"';
    pclose(popen($cmd, 'r'));
}

function start_php_version(string $version): array
{
    if (!isset(PHP_VERSIONS[$version])) {
        return ['ok' => false, 'message' => 'Unknown PHP version'];
    }
    $meta = PHP_VERSIONS[$version];
    if (port_listening($meta['port'])) {
        return ['ok' => true, 'message' => $meta['label'] . ' already running on :' . $meta['port']];
    }
    $dir = str_replace('/', '\\', $meta['dir']);
    $port = $meta['port'];
    // Multiple children: OE (and similar) call their own HTTP API during the same request.
    $command = 'cmd /c set PHPRC=' . $dir
        . '&& set PHP_FCGI_CHILDREN=8'
        . '&& set PHP_FCGI_MAX_REQUESTS=500'
        . '&& "' . $dir . '\php-cgi.exe" -b 127.0.0.1:' . $port . ' -c "' . $dir . '"';
    start_hidden($command);
    usleep(600000);
    $up = port_listening($port);
    return [
        'ok' => $up,
        'message' => $up ? $meta['label'] . ' started on :' . $port : 'Failed to start ' . $meta['label'],
    ];
}

function stop_php_version(string $version, bool $allowDefault = false): array
{
    if (!isset(PHP_VERSIONS[$version])) {
        return ['ok' => false, 'message' => 'Unknown PHP version'];
    }
    if (!$allowDefault && $version === default_php_version()) {
        return ['ok' => false, 'message' => 'Cannot stop the default PHP version (' . $version . ') used by localhost'];
    }
    $meta = PHP_VERSIONS[$version];
    $pids = pids_on_port($meta['port']);
    if (!$pids) {
        return ['ok' => true, 'message' => $meta['label'] . ' was not running'];
    }
    foreach ($pids as $pid) {
        run_cmd('taskkill /F /PID ' . (int)$pid);
    }
    usleep(300000);
    return ['ok' => true, 'message' => 'Stopped ' . $meta['label'] . ' (:' . $meta['port'] . ')'];
}

function stop_php_except_default(): array
{
    $default = default_php_version();
    $stopped = [];
    $kept = [];
    foreach (PHP_VERSIONS as $key => $meta) {
        if ($key === $default) {
            $kept[] = $meta['label'] . ' (:' . $meta['port'] . ')';
            continue;
        }
        $r = stop_php_version($key, true);
        if (!empty($r['ok'])) {
            $stopped[] = $meta['label'];
        }
    }
    return [
        'ok' => true,
        'message' => 'Stopped non-default PHP' . ($stopped ? ': ' . implode(', ', $stopped) : ' (none running)') .
            '. Kept default: ' . implode(', ', $kept),
        'stopped' => $stopped,
        'kept' => $kept,
        'default' => $default,
    ];
}

function apply_default_php_to_apache(string $version): array
{
    if (!isset(PHP_VERSIONS[$version])) {
        return ['ok' => false, 'message' => 'Unknown PHP version'];
    }
    $port = PHP_VERSIONS[$version]['port'];
    $path = APACHE_ROOT . '/conf/extra/httpd-php-fpm.conf';
    $conf = file_get_contents($path);
    if ($conf === false) {
        return ['ok' => false, 'message' => 'Cannot read vhost config'];
    }

    // Only rewrite the localhost / latest VirtualHost block's SetHandler.
    $pattern = '/(# localhost \/ latest — DEFAULT PHP[\s\S]*?<FilesMatch "\\\\\\.php\\$">\\s*SetHandler "proxy:fcgi:\\/\\/127\\.0\\.0\\.1:)\\d+(\\/")/';
    $replaced = preg_replace($pattern, '${1}' . $port . '${2}', $conf, 1, $count);
    if ($count < 1) {
        // Fallback: first SetHandler in file (localhost block is first)
        $replaced = preg_replace(
            '/(ServerName localhost[\s\S]*?SetHandler "proxy:fcgi:\\/\\/127\\.0\\.0\\.1:)\\d+(\\/")/',
            '${1}' . $port . '${2}',
            $conf,
            1,
            $count
        );
    }
    if ($count < 1) {
        return ['ok' => false, 'message' => 'Could not locate localhost SetHandler in vhost config'];
    }
    if (file_put_contents($path, $replaced) === false) {
        return ['ok' => false, 'message' => 'Failed to write vhost config'];
    }

    $check = run_cmd('"' . APACHE_BIN . '" -t');
    $ok = stripos($check['output'], 'Syntax OK') !== false;
    return [
        'ok' => $ok,
        'message' => $ok
            ? 'localhost now uses PHP ' . $version . ' (:' . $port . ')'
            : 'Config written but Apache syntax check failed',
        'port' => $port,
        'apache_test' => $check['output'],
    ];
}

function restart_apache_process(bool $deferred = false): array
{
    $check = run_cmd('"' . APACHE_BIN . '" -t');
    if (stripos($check['output'], 'Syntax OK') === false) {
        return ['ok' => false, 'message' => 'Config invalid — Apache not restarted', 'detail' => $check['output']];
    }
    if ($deferred) {
        // Delay so the current HTTP response can finish before httpd is killed.
        start_hidden('cmd /c "' . SCRIPTS_DIR . '/restart-apache-delayed.bat"');
        return ['ok' => true, 'message' => 'Apache restart scheduled', 'detail' => $check['output']];
    }
    run_cmd('taskkill /F /IM httpd.exe');
    usleep(700000);
    start_hidden('wscript //B //Nologo "' . str_replace('/', '\\', SCRIPTS_DIR) . '\start-httpd-silent.vbs"');
    usleep(900000);
    return ['ok' => true, 'message' => 'Apache restarted', 'detail' => $check['output']];
}

function start_mailpit(): array
{
    if (!is_file(MAILPIT_BIN)) {
        return ['ok' => false, 'message' => 'Mailpit not installed (' . WEB_ROOT . '/mailpit/mailpit.exe)'];
    }
    if (port_listening(MAILPIT_SMTP_PORT)) {
        return ['ok' => true, 'message' => 'Mailpit already running (SMTP :' . MAILPIT_SMTP_PORT . ')'];
    }
    if (!is_dir(MAILPIT_DATA)) {
        @mkdir(MAILPIT_DATA, 0777, true);
    }
    $exe = str_replace('/', '\\', MAILPIT_BIN);
    $db = str_replace('/', '\\', MAILPIT_DATA . '/mailpit.db');
    $cmd = '"' . $exe . '" --smtp 127.0.0.1:' . MAILPIT_SMTP_PORT
        . ' --listen 127.0.0.1:' . MAILPIT_UI_PORT
        . ' --database "' . $db . '" --quiet';
    start_hidden($cmd);
    usleep(700000);
    $up = port_listening(MAILPIT_SMTP_PORT);
    return [
        'ok' => $up,
        'message' => $up
            ? 'Mailpit started · SMTP :' . MAILPIT_SMTP_PORT . ' · UI ' . MAILPIT_UI_URL
            : 'Failed to start Mailpit',
    ];
}

function stop_mailpit(): array
{
    $pids = pids_on_port(MAILPIT_SMTP_PORT);
    if (!$pids) {
        // Also try UI port in case SMTP bind differs
        $pids = pids_on_port(MAILPIT_UI_PORT);
    }
    if (!$pids) {
        return ['ok' => true, 'message' => 'Mailpit was not running'];
    }
    foreach ($pids as $pid) {
        run_cmd('taskkill /F /PID ' . (int)$pid);
    }
    usleep(300000);
    return ['ok' => true, 'message' => 'Mailpit stopped'];
}

function service_status(): array
{
    $default = default_php_version();
    $php = [];
    foreach (PHP_VERSIONS as $key => $meta) {
        $php[$key] = [
            'label' => $meta['label'],
            'port' => $meta['port'],
            'up' => port_listening($meta['port']),
            'hosts' => $meta['hosts'],
            'is_default' => $key === $default,
        ];
    }

    $mysqlUp = port_listening(DB_PORT);
    $mysqlVersion = null;
    if ($mysqlUp && extension_loaded('mysqli')) {
        try {
            $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
            if (!$mysqli->connect_errno) {
                $mysqlVersion = $mysqli->server_info;
                $mysqli->close();
            }
        } catch (Throwable $e) {
            $mysqlVersion = null;
        }
    }

    return [
        'apache' => [
            'up' => process_running('httpd.exe') && port_listening(80),
            'port' => 80,
        ],
        'php' => $php,
        'default_php' => $default,
        'localhost_domains' => LOCALHOST_DOMAINS,
        'mail' => [
            'up' => port_listening(MAILPIT_SMTP_PORT),
            'installed' => is_file(MAILPIT_BIN),
            'smtp_host' => '127.0.0.1',
            'smtp_port' => MAILPIT_SMTP_PORT,
            'ui_port' => MAILPIT_UI_PORT,
            'ui_url' => MAILPIT_UI_URL,
            'label' => 'Mailpit',
        ],
        'mysql' => [
            'up' => $mysqlUp,
            'port' => DB_PORT,
            'version' => $mysqlVersion,
            'user' => DB_USER,
        ],
        'runtime' => [
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'host' => $_SERVER['HTTP_HOST'] ?? 'localhost',
        ],
    ];
}

function db_connect(?string $database = null): mysqli
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, $database ?? '', DB_PORT);
    $db->set_charset('utf8mb4');
    return $db;
}

function read_tail(string $path, int $lines = 120): string
{
    if (!is_file($path)) {
        return '';
    }
    $content = @file($path, FILE_IGNORE_NEW_LINES);
    if ($content === false) {
        return '';
    }
    return implode("\n", array_slice($content, -$lines));
}

function read_tail_lines(string $path, int $lines = 200): array
{
    if (!is_file($path)) {
        return [];
    }
    $content = @file($path, FILE_IGNORE_NEW_LINES);
    if ($content === false) {
        return [];
    }
    return array_values(array_slice($content, -$lines));
}

function parse_apache_error_line(string $line): array
{
    $entry = [
        'time' => '',
        'level' => 'info',
        'category' => 'apache',
        'code' => '',
        'client' => '',
        'pid' => '',
        'message' => $line,
        'raw' => $line,
        'kind' => 'error',
    ];

    if (preg_match('/^\[([^\]]+)\]\s+\[([^:\]]+):([^\]]+)\]\s+(.*)$/', $line, $m)) {
        $entry['time'] = $m[1];
        $entry['category'] = $m[2];
        $entry['level'] = strtolower($m[3]);
        $rest = $m[4];

        if (preg_match('/\[pid\s+(\d+)(?::tid\s+(\d+))?\]\s*/', $rest, $pm)) {
            $entry['pid'] = $pm[1] . (isset($pm[2]) ? ':' . $pm[2] : '');
            $rest = preg_replace('/\[pid\s+\d+(?::tid\s+\d+)?\]\s*/', '', $rest, 1);
        }
        if (preg_match('/\[client\s+([^\]]+)\]\s*/', $rest, $cm)) {
            $entry['client'] = $cm[1];
            $rest = preg_replace('/\[client\s+[^\]]+\]\s*/', '', $rest, 1);
        }
        if (preg_match('/\b(AH\d{4,5})\b:\s*/', $rest, $am)) {
            $entry['code'] = $am[1];
        }
        $entry['message'] = trim($rest);
    }

    return $entry;
}

function parse_apache_access_line(string $line): array
{
    $entry = [
        'time' => '',
        'level' => 'info',
        'category' => 'http',
        'code' => '',
        'client' => '',
        'pid' => '',
        'message' => $line,
        'raw' => $line,
        'kind' => 'access',
        'method' => '',
        'path' => '',
        'status' => '',
        'size' => '',
        'domain' => '',
    ];

    // With Host:  host - - [time] "METHOD path proto" status size "Host"
    // Without:    host - - [time] "METHOD path proto" status size
    if (preg_match('/^(\S+)\s+\S+\s+\S+\s+\[([^\]]+)\]\s+"(\S+)\s+([^\s"]+)(?:\s+[^"]*)?"\s+(\d{3})\s+(\S+)(?:\s+"([^"]*)")?/', $line, $m)) {
        $entry['client'] = $m[1];
        $entry['time'] = $m[2];
        $entry['method'] = $m[3];
        $entry['path'] = $m[4];
        $entry['status'] = $m[5];
        $entry['size'] = $m[6];
        $entry['code'] = $m[5];
        $entry['domain'] = isset($m[7]) ? strtolower(trim($m[7])) : '';
        $status = (int)$m[5];
        if ($status >= 500) {
            $entry['level'] = 'error';
        } elseif ($status >= 400) {
            $entry['level'] = 'warn';
        } elseif ($status >= 300) {
            $entry['level'] = 'notice';
        } else {
            $entry['level'] = 'info';
        }
        $entry['category'] = 'http.' . strtolower($m[3]);
        $hostBit = $entry['domain'] !== '' ? '[' . $entry['domain'] . '] ' : '';
        $entry['message'] = $hostBit . $m[3] . ' ' . $m[4] . ' → ' . $m[5] . ' (' . $m[6] . ' bytes)';
    }

    return $entry;
}

function parse_php_error_line(string $line): array
{
    $entry = [
        'time' => '',
        'level' => 'error',
        'category' => 'php',
        'code' => '',
        'client' => '',
        'pid' => '',
        'message' => $line,
        'raw' => $line,
        'kind' => 'php',
    ];

    if (preg_match('/^\[([^\]]+)\]\s+(.*)$/', $line, $m)) {
        $entry['time'] = $m[1];
        $rest = $m[2];
    } else {
        $rest = $line;
    }

    if (preg_match('/PHP\s+(Fatal error|Parse error|Warning|Notice|Deprecated|Strict standards|Recoverable fatal error)/i', $rest, $lm)) {
        $type = strtolower($lm[1]);
        $entry['code'] = 'PHP ' . $lm[1];
        if (strpos($type, 'fatal') !== false || strpos($type, 'parse') !== false) {
            $entry['level'] = 'error';
        } elseif (strpos($type, 'warn') !== false) {
            $entry['level'] = 'warn';
        } elseif (strpos($type, 'deprecat') !== false) {
            $entry['level'] = 'notice';
        } else {
            $entry['level'] = 'notice';
        }
        $entry['category'] = 'php.' . preg_replace('/\s+/', '_', $type);
    }

    $entry['message'] = trim($rest);
    return $entry;
}

function parse_log_lines(array $lines, string $format): array
{
    $entries = [];
    foreach ($lines as $line) {
        $line = rtrim($line);
        if ($line === '') {
            continue;
        }
        if ($format === 'apache_access') {
            $entries[] = parse_apache_access_line($line);
        } elseif ($format === 'php_error') {
            $entries[] = parse_php_error_line($line);
        } else {
            $entries[] = parse_apache_error_line($line);
        }
    }
    return array_reverse($entries);
}

function log_level_counts(array $entries): array
{
    $counts = ['error' => 0, 'warn' => 0, 'notice' => 0, 'info' => 0, 'other' => 0];
    foreach ($entries as $e) {
        $level = $e['level'] ?? 'other';
        if ($level === 'warning') {
            $level = 'warn';
        }
        if (!isset($counts[$level])) {
            $counts['other']++;
        } else {
            $counts[$level]++;
        }
    }
    return $counts;
}

function resolve_log_source(string $source, string $kind): ?array
{
    if (!isset(LOG_SOURCES[$source]['kinds'][$kind])) {
        return null;
    }
    $meta = LOG_SOURCES[$source]['kinds'][$kind];
    return [
        'source' => $source,
        'source_label' => LOG_SOURCES[$source]['label'],
        'kind' => $kind,
        'kind_label' => $meta['label'],
        'path' => $meta['path'],
        'format' => $meta['format'],
        'exists' => is_file($meta['path']),
        'size' => is_file($meta['path']) ? filesize($meta['path']) : 0,
    ];
}

require __DIR__ . '/sites.php';
require __DIR__ . '/phpini.php';
