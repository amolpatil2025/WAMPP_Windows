<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($action) {
        case 'status':
            json_out(['ok' => true, 'data' => service_status()]);

        case 'start_php':
            $results = [];
            foreach (array_keys(PHP_VERSIONS) as $ver) {
                $results[] = start_php_version($ver)['message'];
            }
            usleep(400000);
            json_out(['ok' => true, 'message' => 'PHP listeners started', 'detail' => implode('; ', $results), 'data' => service_status()]);

        case 'stop_php':
            $r = stop_php_except_default();
            usleep(300000);
            json_out(['ok' => true, 'message' => $r['message'], 'detail' => 'Default PHP ' . $r['default'] . ' kept running for localhost', 'data' => service_status()]);

        case 'start_php_version':
            $ver = (string)($_GET['version'] ?? $_POST['version'] ?? '');
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            if ($ver === '' && isset($body['version'])) {
                $ver = (string)$body['version'];
            }
            $r = start_php_version($ver);
            json_out(['ok' => $r['ok'], 'message' => $r['message'], 'data' => service_status()], $r['ok'] ? 200 : 400);

        case 'stop_php_version':
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            $ver = (string)($_GET['version'] ?? $body['version'] ?? '');
            $r = stop_php_version($ver, false);
            json_out(['ok' => $r['ok'], 'message' => $r['message'], 'data' => service_status()], $r['ok'] ? 200 : 400);

        case 'set_default_php':
            if ($method !== 'POST') {
                json_out(['ok' => false, 'message' => 'POST required'], 405);
            }
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            $ver = (string)($body['version'] ?? '');
            if (!isset(PHP_VERSIONS[$ver])) {
                json_out(['ok' => false, 'message' => 'Unknown PHP version'], 400);
            }
            $settings = panel_settings();
            $settings['default_php'] = $ver;
            if (!save_panel_settings($settings)) {
                json_out(['ok' => false, 'message' => 'Failed to save settings'], 500);
            }
            $applied = apply_default_php_to_apache($ver);
            if (!$applied['ok']) {
                json_out(['ok' => false, 'message' => $applied['message'], 'detail' => $applied['apache_test'] ?? ''], 400);
            }
            // Ensure default listener is up
            start_php_version($ver);
            $restart = restart_apache_process(true);
            json_out([
                'ok' => true,
                'message' => $applied['message'] . ' · Apache will reload in ~2s',
                'data' => service_status(),
            ]);

        case 'start_apache':
            // Default PHP is paired with Apache for localhost
            start_php_version(default_php_version());
            if (process_running('httpd.exe')) {
                json_out(['ok' => true, 'message' => 'Apache already running', 'data' => service_status()]);
            }
            $check = run_cmd('"' . APACHE_BIN . '" -t');
            if (!$check['ok'] && stripos($check['output'], 'Syntax OK') === false) {
                json_out(['ok' => false, 'message' => 'Config invalid', 'detail' => $check['output']], 400);
            }
            start_hidden('wscript //B //Nologo "' . str_replace('/', '\\', SCRIPTS_DIR) . '\start-httpd-silent.vbs"');
            usleep(900000);
            json_out(['ok' => true, 'message' => 'Apache started (default PHP ' . default_php_version() . ')', 'data' => service_status()]);

        case 'stop_apache':
            run_cmd('taskkill /F /IM httpd.exe');
            $phpStop = stop_php_version(default_php_version(), true);
            usleep(500000);
            json_out([
                'ok' => true,
                'message' => 'Apache stopped · default PHP ' . default_php_version() . ' stopped',
                'detail' => $phpStop['message'] ?? '',
                'data' => service_status(),
            ]);

        case 'restart_apache':
            $restart = restart_apache_process(false);
            if (!$restart['ok']) {
                json_out(['ok' => false, 'message' => $restart['message'], 'detail' => $restart['detail'] ?? ''], 400);
            }
            json_out(['ok' => true, 'message' => $restart['message'], 'data' => service_status()]);

        case 'restart_all':
            stop_php_except_default();
            foreach (array_keys(PHP_VERSIONS) as $ver) {
                start_php_version($ver);
            }
            start_mailpit();
            start_ftp();
            $restart = restart_apache_process();
            if (!$restart['ok']) {
                json_out(['ok' => false, 'message' => $restart['message'], 'detail' => $restart['detail'] ?? ''], 400);
            }
            json_out(['ok' => true, 'message' => 'Stack restarted (default PHP kept)', 'data' => service_status()]);

        case 'start_mail':
            $r = start_mailpit();
            json_out(['ok' => $r['ok'], 'message' => $r['message'], 'data' => service_status()], $r['ok'] ? 200 : 400);

        case 'stop_mail':
            $r = stop_mailpit();
            json_out(['ok' => $r['ok'], 'message' => $r['message'], 'data' => service_status()]);

        case 'php_available':
            $r = php_available_releases();
            json_out([
                'ok' => $r['ok'],
                'message' => $r['message'],
                'releases' => $r['releases'],
                'download' => php_download_status(),
                'installed' => PHP_VERSIONS,
            ], $r['ok'] ? 200 : 400);

        case 'php_download':
            if ($method !== 'POST') {
                json_out(['ok' => false, 'message' => 'POST required'], 405);
            }
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            $ver = (string)($body['version'] ?? $body['key'] ?? '');
            $r = php_start_download($ver);
            json_out([
                'ok' => $r['ok'],
                'message' => $r['message'],
                'download' => $r['data'] ?? php_download_status(),
            ], $r['ok'] ? 200 : 400);

        case 'php_download_status':
            json_out(['ok' => true, 'download' => php_download_status(), 'data' => service_status()]);

        case 'php_refresh':
            // Re-scan php* folders and rewrite Apache version hosts + runtime map.
            $versions = php_refresh_catalog(true);
            $hosts = sync_windows_hosts(load_custom_sites());
            $hostMsg = !empty($hosts['ok'])
                ? (' · hosts ' . (empty($hosts['added']) ? 'ok' : ('added ' . implode(', ', $hosts['added']))))
                : (' · hosts: ' . ($hosts['message'] ?? 'not updated'));
            json_out([
                'ok' => true,
                'message' => count($versions) . ' PHP folder(s) found' . $hostMsg,
                'versions' => $versions,
                'hosts' => $hosts,
                'data' => service_status(),
            ]);

        case 'db_settings_get':
            $cfg = db_settings();
            $test = db_test_connection($cfg);
            json_out([
                'ok' => true,
                'settings' => [
                    'host' => $cfg['host'],
                    'port' => $cfg['port'],
                    'user' => $cfg['user'],
                    'pass' => $cfg['pass'],
                ],
                'connected' => !empty($test['ok']),
                'version' => $test['version'] ?? null,
                'error' => $test['ok'] ? null : ($test['message'] ?? null),
            ]);

        case 'db_settings_save':
            if ($method !== 'POST') {
                json_out(['ok' => false, 'message' => 'POST required'], 405);
            }
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            $r = save_db_settings($body);
            if (!$r['ok']) {
                json_out(['ok' => false, 'message' => $r['message']], 400);
            }
            $cfg = [
                'host' => trim((string)($body['host'] ?? '')),
                'port' => (int)($body['port'] ?? 3306),
                'user' => (string)($body['user'] ?? ''),
                'pass' => (string)($body['pass'] ?? ''),
            ];
            $test = db_test_connection($cfg);
            json_out([
                'ok' => true,
                'message' => $r['message'] . ($test['ok'] ? ' · connection OK' : ' · could not connect yet'),
                'connected' => !empty($test['ok']),
                'version' => $test['version'] ?? null,
                'error' => $test['ok'] ? null : ($test['message'] ?? null),
                'settings' => $cfg,
                'data' => service_status(),
            ]);

        case 'db_settings_test':
            if ($method !== 'POST') {
                json_out(['ok' => false, 'message' => 'POST required'], 405);
            }
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            $cfg = [
                'host' => trim((string)($body['host'] ?? db_settings()['host'])),
                'port' => (int)($body['port'] ?? db_settings()['port']),
                'user' => (string)($body['user'] ?? db_settings()['user']),
                'pass' => array_key_exists('pass', $body) ? (string)$body['pass'] : db_settings()['pass'],
            ];
            $test = db_test_connection($cfg);
            json_out([
                'ok' => !empty($test['ok']),
                'message' => $test['ok'] ? ('Connected · ' . ($test['version'] ?? 'MySQL')) : ($test['message'] ?? 'Failed'),
                'version' => $test['version'] ?? null,
            ], !empty($test['ok']) ? 200 : 400);

        case 'db_create':
            if ($method !== 'POST') {
                json_out(['ok' => false, 'message' => 'POST required'], 405);
            }
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            $name = trim((string)($body['name'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_$]{1,64}$/', $name)) {
                json_out(['ok' => false, 'message' => 'Database name can use letters, numbers, _ and $'], 400);
            }
            $db = db_connect();
            $db->query('CREATE DATABASE `' . str_replace('`', '``', $name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $db->close();
            json_out(['ok' => true, 'message' => 'Created database ' . $name, 'name' => $name]);

        case 'start_ftp':
            $r = start_ftp();
            json_out(['ok' => $r['ok'], 'message' => $r['message'], 'data' => service_status()], $r['ok'] ? 200 : 400);

        case 'stop_ftp':
            $r = stop_ftp();
            json_out(['ok' => $r['ok'], 'message' => $r['message'], 'data' => service_status()]);

        case 'restart_ftp':
            $r = restart_ftp();
            json_out(['ok' => $r['ok'], 'message' => $r['message'], 'data' => service_status()], $r['ok'] ? 200 : 400);

        case 'ftp_get':
            json_out(['ok' => true, 'data' => ftp_panel_state()]);

        case 'ftp_save_settings':
            if ($method !== 'POST') {
                json_out(['ok' => false, 'message' => 'POST required'], 405);
            }
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            $r = ftp_save_settings($body);
            json_out(['ok' => $r['ok'], 'message' => $r['message'], 'data' => ftp_panel_state()], $r['ok'] ? 200 : 400);

        case 'ftp_save_user':
            if ($method !== 'POST') {
                json_out(['ok' => false, 'message' => 'POST required'], 405);
            }
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            $r = ftp_save_user($body);
            json_out(['ok' => $r['ok'], 'message' => $r['message'], 'data' => ftp_panel_state()], $r['ok'] ? 200 : 400);

        case 'ftp_delete_user':
            if ($method !== 'POST') {
                json_out(['ok' => false, 'message' => 'POST required'], 405);
            }
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            $r = ftp_delete_user((string)($body['name'] ?? ''));
            json_out(['ok' => $r['ok'], 'message' => $r['message'], 'data' => ftp_panel_state()], $r['ok'] ? 200 : 400);

        case 'config_get':
            $id = $_GET['id'] ?? '';
            if (!isset(CONFIG_FILES[$id])) {
                json_out(['ok' => false, 'message' => 'Unknown config'], 404);
            }
            $path = CONFIG_FILES[$id]['path'];
            if (!is_file($path)) {
                json_out(['ok' => false, 'message' => 'File missing'], 404);
            }
            json_out([
                'ok' => true,
                'id' => $id,
                'label' => CONFIG_FILES[$id]['label'],
                'path' => $path,
                'content' => file_get_contents($path),
            ]);

        case 'config_save':
            if ($method !== 'POST') {
                json_out(['ok' => false, 'message' => 'POST required'], 405);
            }
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            $id = (string)($body['id'] ?? '');
            $content = (string)($body['content'] ?? '');
            if (!isset(CONFIG_FILES[$id])) {
                json_out(['ok' => false, 'message' => 'Unknown config'], 404);
            }
            $path = CONFIG_FILES[$id]['path'];
            $backup = $path . '.bak-' . date('Ymd-His');
            @copy($path, $backup);
            if (file_put_contents($path, $content) === false) {
                json_out(['ok' => false, 'message' => 'Write failed'], 500);
            }
            $check = ['output' => ''];
            if (strpos($id, 'httpd') === 0 || $id === 'php-fpm') {
                $check = run_cmd('"' . APACHE_BIN . '" -t');
            }
            json_out([
                'ok' => true,
                'message' => 'Saved ' . CONFIG_FILES[$id]['label'],
                'backup' => $backup,
                'apache_test' => $check['output'],
            ]);

        case 'logs':
            // Legacy id= support + new source/kind API
            $source = (string)($_GET['source'] ?? '');
            $kind = (string)($_GET['kind'] ?? 'error');
            $limit = min(500, max(20, (int)($_GET['limit'] ?? 200)));

            if ($source === '' && isset($_GET['id'])) {
                $id = (string)$_GET['id'];
                $legacy = [
                    'error' => ['apache', 'error'],
                    'php74' => ['php74', 'error'],
                    'php80' => ['php80', 'error'],
                    'php84' => ['php84', 'error'],
                ];
                if (!isset($legacy[$id]) && !isset(LOG_FILES[$id])) {
                    json_out(['ok' => false, 'message' => 'Unknown log'], 404);
                }
                if (isset($legacy[$id])) {
                    [$source, $kind] = $legacy[$id];
                }
            }

            if ($source === '') {
                $source = 'apache';
            }

            $resolved = resolve_log_source($source, $kind);
            if ($resolved === null) {
                json_out(['ok' => false, 'message' => 'Unknown log source/kind'], 404);
            }

            $lines = read_tail_lines($resolved['path'], $limit);
            $entries = parse_log_lines($lines, $resolved['format']);
            $sourcesMeta = [];
            foreach (LOG_SOURCES as $sid => $smeta) {
                $kinds = [];
                foreach ($smeta['kinds'] as $kid => $kmeta) {
                    $kinds[$kid] = [
                        'label' => $kmeta['label'],
                        'exists' => is_file($kmeta['path']),
                        'size' => is_file($kmeta['path']) ? filesize($kmeta['path']) : 0,
                    ];
                }
                $sourcesMeta[$sid] = [
                    'label' => $smeta['label'],
                    'domains' => $smeta['domains'] ?? [],
                    'kinds' => $kinds,
                ];
            }

            $domainsSeen = [];
            foreach ($entries as $e) {
                if (!empty($e['domain'])) {
                    $domainsSeen[$e['domain']] = true;
                }
            }

            json_out([
                'ok' => true,
                'source' => $source,
                'kind' => $kind,
                'label' => $resolved['source_label'] . ' · ' . $resolved['kind_label'],
                'path' => $resolved['path'],
                'exists' => $resolved['exists'],
                'size' => $resolved['size'],
                'domains' => LOG_SOURCES[$source]['domains'] ?? [],
                'domains_seen' => array_keys($domainsSeen),
                'entries' => $entries,
                'counts' => log_level_counts($entries),
                'total' => count($entries),
                'sources' => $sourcesMeta,
                'content' => implode("\n", $lines),
            ]);

        case 'db_list':
            $db = db_connect();
            $dbs = [];
            $res = $db->query('SHOW DATABASES');
            while ($row = $res->fetch_row()) {
                $name = $row[0];
                if (in_array($name, ['information_schema', 'performance_schema', 'sys'], true)) {
                    continue;
                }
                $dbs[] = $name;
            }
            $db->close();
            json_out(['ok' => true, 'databases' => $dbs]);

        case 'db_tables':
            $database = (string)($_GET['db'] ?? '');
            if ($database === '') {
                json_out(['ok' => false, 'message' => 'db required'], 400);
            }
            $db = db_connect($database);
            $tables = [];
            $res = $db->query('SHOW FULL TABLES');
            while ($row = $res->fetch_row()) {
                $tables[] = ['name' => $row[0], 'type' => $row[1] ?? 'BASE TABLE'];
            }
            $db->close();
            json_out(['ok' => true, 'db' => $database, 'tables' => $tables]);

        case 'db_browse':
            $database = (string)($_GET['db'] ?? '');
            $table = (string)($_GET['table'] ?? '');
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;
            if ($database === '' || $table === '') {
                json_out(['ok' => false, 'message' => 'db and table required'], 400);
            }
            if (!preg_match('/^[A-Za-z0-9_$-]+$/', $table) && !preg_match('/^[A-Za-z0-9_`$-]+$/', $table)) {
                // allow quoted identifiers via backtick wrap after sanitize
            }
            $safeTable = str_replace('`', '``', $table);
            $db = db_connect($database);
            $countRes = $db->query('SELECT COUNT(*) AS c FROM `' . $safeTable . '`');
            $total = (int)$countRes->fetch_assoc()['c'];
            $res = $db->query('SELECT * FROM `' . $safeTable . '` LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset);
            $columns = [];
            $fields = $res->fetch_fields();
            foreach ($fields as $f) {
                $columns[] = $f->name;
            }
            $rows = [];
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $db->close();
            json_out([
                'ok' => true,
                'db' => $database,
                'table' => $table,
                'columns' => $columns,
                'rows' => $rows,
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
            ]);

        case 'db_query':
            if ($method !== 'POST') {
                json_out(['ok' => false, 'message' => 'POST required'], 405);
            }
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            $database = (string)($body['db'] ?? '');
            $sql = trim((string)($body['sql'] ?? ''));
            if ($sql === '') {
                json_out(['ok' => false, 'message' => 'SQL required'], 400);
            }
            $db = db_connect($database !== '' ? $database : null);
            $started = microtime(true);
            if ($db->multi_query($sql)) {
                $results = [];
                do {
                    if ($result = $db->store_result()) {
                        $columns = [];
                        foreach ($result->fetch_fields() as $f) {
                            $columns[] = $f->name;
                        }
                        $rows = [];
                        while ($row = $result->fetch_assoc()) {
                            $rows[] = $row;
                            if (count($rows) >= 500) {
                                break;
                            }
                        }
                        $results[] = [
                            'type' => 'resultset',
                            'columns' => $columns,
                            'rows' => $rows,
                            'row_count' => $result->num_rows,
                        ];
                        $result->free();
                    } else if ($db->errno === 0) {
                        $results[] = [
                            'type' => 'ok',
                            'affected' => $db->affected_rows,
                            'info' => $db->info,
                        ];
                    }
                } while ($db->more_results() && $db->next_result());
                $ms = round((microtime(true) - $started) * 1000, 1);
                $db->close();
                json_out(['ok' => true, 'ms' => $ms, 'results' => $results]);
            }
            $err = $db->error;
            $db->close();
            json_out(['ok' => false, 'message' => $err], 400);

        case 'db_structure':
            $database = (string)($_GET['db'] ?? '');
            $table = (string)($_GET['table'] ?? '');
            if ($database === '' || $table === '') {
                json_out(['ok' => false, 'message' => 'db and table required'], 400);
            }
            $safeTable = str_replace('`', '``', $table);
            $db = db_connect($database);
            $cols = [];
            $res = $db->query('SHOW FULL COLUMNS FROM `' . $safeTable . '`');
            while ($row = $res->fetch_assoc()) {
                $cols[] = $row;
            }
            $create = '';
            $cres = $db->query('SHOW CREATE TABLE `' . $safeTable . '`');
            if ($crow = $cres->fetch_row()) {
                $create = $crow[1] ?? '';
            }
            $db->close();
            json_out(['ok' => true, 'columns' => $cols, 'create' => $create]);

        case 'sites_list':
            json_out(['ok' => true, 'data' => sites_overview()]);

        case 'sites_save':
            if ($method !== 'POST') {
                json_out(['ok' => false, 'message' => 'POST required'], 405);
            }
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            $id = isset($body['id']) && $body['id'] !== '' ? (string)$body['id'] : null;
            $result = upsert_site($body, $id);
            json_out($result, !empty($result['ok']) ? 200 : 400);

        case 'sites_delete':
            if ($method !== 'POST') {
                json_out(['ok' => false, 'message' => 'POST required'], 405);
            }
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            $id = (string)($body['id'] ?? '');
            if ($id === '') {
                json_out(['ok' => false, 'message' => 'id required'], 400);
            }
            $result = delete_site($id);
            json_out($result, !empty($result['ok']) ? 200 : 400);

        case 'sites_hosts_block':
            json_out([
                'ok' => true,
                'hosts_block' => hosts_block_for_sites(load_custom_sites()),
                'hosts_file' => HOSTS_FILE,
            ]);

        case 'sites_sync_hosts':
            $sites = load_custom_sites();
            $hosts = sync_windows_hosts($sites);
            json_out([
                'ok' => true,
                'synced' => !empty($hosts['ok']),
                'message' => $hosts['message'] ?? '',
                'hosts' => $hosts,
                'data' => sites_overview(),
            ]);

        case 'phpini_get':
            $ver = (string)($_GET['version'] ?? '');
            if ($ver === '') {
                $ver = default_php_version();
            }
            $data = phpini_get($ver);
            json_out($data, !empty($data['ok']) ? 200 : 400);

        case 'phpini_save':
            if ($method !== 'POST') {
                json_out(['ok' => false, 'message' => 'POST required'], 405);
            }
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            $ver = (string)($body['version'] ?? '');
            if ($ver === '') {
                json_out(['ok' => false, 'message' => 'version required'], 400);
            }
            $result = phpini_save($ver, $body);
            json_out($result, !empty($result['ok']) ? 200 : 400);

        case 'phpini_restart':
            if ($method !== 'POST') {
                json_out(['ok' => false, 'message' => 'POST required'], 405);
            }
            $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
            $ver = (string)($body['version'] ?? $_GET['version'] ?? '');
            if (!isset(PHP_VERSIONS[$ver])) {
                json_out(['ok' => false, 'message' => 'Unknown PHP version'], 400);
            }
            // Defer so this FastCGI response can finish if we are bouncing the serving PHP.
            $bat = SCRIPTS_DIR . '/restart-php-delayed.bat';
            start_hidden('cmd /c "' . str_replace('/', '\\', $bat) . '" ' . $ver);
            json_out([
                'ok' => true,
                'message' => PHP_VERSIONS[$ver]['label'] . ' will reload in ~2s with current php.ini',
                'data' => service_status(),
            ]);

        default:
            json_out(['ok' => false, 'message' => 'Unknown action'], 404);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'message' => $e->getMessage()], 500);
}
