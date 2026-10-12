<?php
declare(strict_types=1);

function firewall_targets(): array
{
    $list = [];
    $apache = str_replace('/', '\\', APACHE_BIN);
    if (is_file($apache)) {
        $list[] = ['id' => 'apache', 'label' => 'Apache', 'path' => $apache];
    }
    foreach (PHP_VERSIONS as $key => $meta) {
        $exe = str_replace('/', '\\', $meta['dir'] . '/php.exe');
        if (!is_file($exe)) {
            continue;
        }
        $label = trim((string)($meta['label'] ?? $key));
        $list[] = [
            'id' => 'php-' . $key,
            'label' => ($label !== '' ? $label : ('PHP ' . $key)) . ' CLI',
            'path' => $exe,
        ];
    }
    return $list;
}

function firewall_rules_blob(): string
{
    $out = [];
    exec('reg query "HKLM\\SYSTEM\\CurrentControlSet\\Services\\SharedAccess\\Parameters\\FirewallPolicy\\FirewallRules" 2>nul', $out);
    return implode("\n", $out);
}

function firewall_program_state(string $blob, string $path): string
{
    $want = strtolower(str_replace('/', '\\', $path));
    $allow = false;
    $block = false;
    foreach (preg_split('/\R/', $blob) ?: [] as $line) {
        if (!preg_match('/\|App=([^|]+)/i', $line, $m)) {
            continue;
        }
        $app = strtolower(str_replace('/', '\\', trim($m[1], "\" \t")));
        if ($app !== $want) {
            continue;
        }
        if (stripos($line, 'Active=TRUE') === false || stripos($line, 'Dir=In') === false) {
            continue;
        }
        if (stripos($line, 'Action=Block') !== false) {
            $block = true;
        }
        if (stripos($line, 'Action=Allow') !== false) {
            $allow = true;
        }
    }
    if ($block || !$allow) {
        return 'missing';
    }
    return 'allowed';
}

function firewall_status(): array
{
    $blob = firewall_rules_blob();
    $checked = $blob !== '' && stripos($blob, 'REG_SZ') !== false;
    $missing = [];
    if ($checked) {
        foreach (firewall_targets() as $target) {
            if (firewall_program_state($blob, $target['path']) !== 'allowed') {
                $missing[] = [
                    'id' => $target['id'],
                    'label' => $target['label'],
                ];
            }
        }
    }
    return [
        'checked' => $checked,
        'missing' => $missing,
    ];
}

function firewall_allow(): array
{
    set_time_limit(180);
    $elevate = str_replace('/', '\\', SCRIPTS_DIR . '/allow-firewall-elevate.ps1');
    if (!is_file($elevate)) {
        return ['ok' => false, 'message' => 'Firewall helper is missing'];
    }
    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File "' . $elevate . '"';
    $ran = run_cmd($cmd);
    $status = firewall_status();
    if (!$ran['ok']) {
        $cancelled = stripos($ran['output'], 'cancel') !== false || (int)$ran['code'] === 2;
        return [
            'ok' => false,
            'message' => $cancelled
                ? 'Windows permission was not accepted'
                : 'Could not update Windows Firewall',
            'status' => $status,
        ];
    }
    if ($status['missing'] !== []) {
        return [
            'ok' => false,
            'message' => 'Windows Firewall still blocks ' . implode(', ', array_column($status['missing'], 'label')),
            'status' => $status,
        ];
    }
    return [
        'ok' => true,
        'message' => 'Apache and PHP CLI are allowed in Windows Firewall',
        'status' => $status,
    ];
}
