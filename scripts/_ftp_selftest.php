<?php
declare(strict_types=1);

$php = dirname(__DIR__) . '/php8.0.30/php.exe';
$web = str_replace('\\', '/', dirname(__DIR__));
require __DIR__ . '/lib/ftp-config.php';

function fail(string $msg): void
{
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

function read_reply($fp): string
{
    $buf = '';
    while (($line = fgets($fp)) !== false) {
        $buf .= $line;
        if (preg_match('/^\d{3} /', $line)) {
            break;
        }
        if (strlen($buf) > 8192) {
            break;
        }
    }
    if ($buf === '') {
        fail('empty FTP reply');
    }
    return $buf;
}

function expect_code(string $reply, string $code, string $label): void
{
    if (!preg_match('/^' . $code . ' /m', $reply) && !preg_match('/\n' . $code . ' /', $reply) && strpos($reply, $code . ' ') !== 0) {
        if (!preg_match('/(^|\n)' . $code . ' /', $reply)) {
            fail($label . ' expected ' . $code . ' got ' . trim($reply));
        }
    }
}

function connect_ctrl(int $port)
{
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 5);
    if (!$fp) {
        fail('connect ' . $errstr);
    }
    stream_set_timeout($fp, 5);
    return $fp;
}

function login($fp, string $user, string $pass): string
{
    fwrite($fp, "USER $user\r\n");
    $r = read_reply($fp);
    expect_code($r, '331', 'USER');
    fwrite($fp, "PASS $pass\r\n");
    $r = read_reply($fp);
    return $r;
}

function pasv($fp): array
{
    fwrite($fp, "PASV\r\n");
    $r = read_reply($fp);
    expect_code($r, '227', 'PASV');
    if (!preg_match('/\((\d+),(\d+),(\d+),(\d+),(\d+),(\d+)\)/', $r, $m)) {
        fail('PASV parse ' . trim($r));
    }
    $ip = $m[1] . '.' . $m[2] . '.' . $m[3] . '.' . $m[4];
    $port = ((int)$m[5] * 256) + (int)$m[6];
    if ($ip !== '127.0.0.1') {
        fail('PASV advertised ' . $ip);
    }
    $errno = 0;
    $errstr = '';
    $data = @stream_socket_client('tcp://' . $ip . ':' . $port, $errno, $errstr, 5);
    if (!$data) {
        fail('data connect ' . $errstr);
    }
    stream_set_timeout($data, 5);
    return [$data, $r];
}

$cfg = ftp_ensure_config($web);
if ($cfg === null) {
    fail('config');
}
$port = (int)$cfg['port'];

$deadline = microtime(true) + 8;
$up = false;
while (microtime(true) < $deadline) {
    $errno = 0;
    $errstr = '';
    $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.3);
    if ($probe) {
        fclose($probe);
        $up = true;
        break;
    }
    usleep(200000);
}
if (!$up) {
    $log = @file_get_contents(ftp_log_file($web));
    fail('server not listening. log: ' . $log);
}

$fp = connect_ctrl($port);
$banner = read_reply($fp);
expect_code($banner, '220', 'banner');

$bad = login($fp, 'web', 'nope');
expect_code($bad, '530', 'bad password');

$ok = login($fp, 'web', 'web');
expect_code($ok, '230', 'login');

fwrite($fp, "PWD\r\n");
$pwd = read_reply($fp);
expect_code($pwd, '257', 'PWD');
if (strpos($pwd, '"/"') === false) {
    fail('PWD not root: ' . trim($pwd));
}

fwrite($fp, "CWD ..\r\n");
$cwd = read_reply($fp);
expect_code($cwd, '250', 'CWD ..');
if (strpos($cwd, '/') === false) {
    fail('CWD .. reply ' . trim($cwd));
}
fwrite($fp, "PWD\r\n");
$pwd2 = read_reply($fp);
if (strpos($pwd2, '"/"') === false) {
    fail('jail escape: ' . trim($pwd2));
}

fwrite($fp, "MKD _ftp_selftest\r\n");
$mkd = read_reply($fp);
expect_code($mkd, '257', 'MKD');

fwrite($fp, "CWD _ftp_selftest\r\n");
expect_code(read_reply($fp), '250', 'CWD test');

fwrite($fp, "TYPE I\r\n");
expect_code(read_reply($fp), '200', 'TYPE I');

[$data] = pasv($fp);
$payload = "hello ftp\nline2";
fwrite($fp, "STOR note.txt\r\n");
$stor = read_reply($fp);
expect_code($stor, '150', 'STOR');
fwrite($data, $payload);
fclose($data);
$done = read_reply($fp);
expect_code($done, '226', 'STOR done');

$file = $web . '/htdocs/_ftp_selftest/note.txt';
$saved = @file_get_contents($file);
if ($saved !== $payload) {
    fail('stored bytes mismatch: ' . var_export($saved, true));
}

fwrite($fp, "SIZE note.txt\r\n");
$size = read_reply($fp);
expect_code($size, '213', 'SIZE');
if (strpos($size, (string)strlen($payload)) === false) {
    fail('size reply ' . trim($size));
}

[$data] = pasv($fp);
fwrite($fp, "RETR note.txt\r\n");
expect_code(read_reply($fp), '150', 'RETR');
$got = stream_get_contents($data);
fclose($data);
expect_code(read_reply($fp), '226', 'RETR done');
if ($got !== $payload) {
    fail('retr mismatch ' . var_export($got, true));
}

fwrite($fp, "TYPE A\r\n");
expect_code(read_reply($fp), '200', 'TYPE A');
[$data] = pasv($fp);
fwrite($fp, "STOR ascii.txt\r\n");
expect_code(read_reply($fp), '150', 'STOR A');
fwrite($data, "a\r\nb\r\n");
fclose($data);
expect_code(read_reply($fp), '226', 'STOR A done');
$ascii = @file_get_contents($web . '/htdocs/_ftp_selftest/ascii.txt');
if ($ascii !== "a\nb\n") {
    fail('ascii store ' . var_export($ascii, true));
}

fwrite($fp, "DELE note.txt\r\n");
expect_code(read_reply($fp), '250', 'DELE');
fwrite($fp, "DELE ascii.txt\r\n");
expect_code(read_reply($fp), '250', 'DELE ascii');
fwrite($fp, "CDUP\r\n");
expect_code(read_reply($fp), '250', 'CDUP');
fwrite($fp, "RMD _ftp_selftest\r\n");
expect_code(read_reply($fp), '250', 'RMD');

if (is_dir($web . '/htdocs/_ftp_selftest')) {
    fail('test dir still exists');
}

fwrite($fp, "QUIT\r\n");
expect_code(read_reply($fp), '221', 'QUIT');
fclose($fp);

$cfg = ftp_read_config($web);
$cfg['users'][] = [
    'name' => 'reader',
    'password_hash' => password_hash('reader', PASSWORD_DEFAULT),
    'home' => $web . '/htdocs',
    'write' => false,
    'enabled' => true,
];
if (!ftp_write_config($web, $cfg)) {
    fail('write reader');
}
usleep(300000);
$fp = connect_ctrl($port);
read_reply($fp);
$ok = login($fp, 'reader', 'reader');
expect_code($ok, '230', 'reader login');
fwrite($fp, "MKD _ftp_should_fail\r\n");
$denied = read_reply($fp);
expect_code($denied, '550', 'readonly');
fwrite($fp, "QUIT\r\n");
fclose($fp);

$cfg = ftp_read_config($web);
$cfg['users'] = array_values(array_filter($cfg['users'], static function ($u) {
    return strcasecmp((string)$u['name'], 'reader') !== 0;
}));
ftp_write_config($web, $cfg);

echo "OK\n";
