<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Web Stack Panel</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Sora:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/app.css?v=14">
</head>
<body>
  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <strong>Stack Panel</strong>
        <span>Apache · PHP · MySQL · Mail · FTP</span>
      </div>
      <nav class="nav">
        <button type="button" data-view="overview" class="active">Overview</button>
        <button type="button" data-view="sites">Sites</button>
        <button type="button" data-view="php">PHP Config</button>
        <button type="button" data-view="config">Config editor</button>
        <button type="button" data-view="logs">Logs</button>
        <button type="button" data-view="database">Database</button>
        <button type="button" data-view="ftp">FTP</button>
        <button type="button" onclick="window.open('http://127.0.0.1:8025/', '_blank')">Mail inbox</button>
        <button type="button" onclick="window.open('http://localhost/phpmyadmin/', '_blank')">Phpmyadmin</button>
      </nav>
    </aside>

    <main class="main">
      <div class="topbar">
        <div>
          <h1 id="page-title">Stack overview</h1>
          <p id="page-sub">Live status, restarts, and quick links</p>
        </div>
        <button class="btn" type="button" id="refresh-status">Refresh</button>
      </div>

      <section class="section active" id="view-overview">
        <div class="ov-health" id="ov-health">
          <div class="ov-health-main">
            <div class="ov-health-dot" id="ov-health-dot" aria-hidden="true"></div>
            <div>
              <div class="ov-health-title" id="ov-health-title">Checking stack…</div>
              <div class="ov-health-sub" id="ov-health-sub">Apache · PHP · MySQL · Mail · FTP</div>
            </div>
          </div>
          <div class="btn-row ov-health-actions">
            <button class="btn primary" type="button" data-action="restart_all" title="Reload Apache, PHP listeners, Mail, and FTP">Restart stack</button>
            <button class="btn" type="button" id="ov-start-needed" hidden>Start stopped</button>
            <button class="btn" type="button" id="refresh-status-ov">Refresh</button>
          </div>
        </div>

        <div class="ov-layout">
          <div class="stack ov-main">
            <div class="card ov-services-card">
              <div class="row-between ov-section-head">
                <div>
                  <h2>Services</h2>
                  <p class="muted">Web server, database, local mail, and FTP</p>
                </div>
              </div>
              <div class="ov-service-list" id="ov-services"></div>
            </div>

            <div class="card">
              <div class="row-between ov-section-head">
                <div>
                  <h2>PHP versions</h2>
                  <p class="muted">Scanned from <code>php*</code> folders. Each runs as FastCGI.</p>
                </div>
                <div class="ov-default-php">
                  <label class="ov-default-label" for="default-php-select">Default for localhost</label>
                  <div class="btn-row">
                    <select id="default-php-select" aria-label="Default PHP for localhost"></select>
                    <button class="btn primary" type="button" id="save-default-php">Apply</button>
                    <button class="btn" type="button" id="php-rescan" title="Re-scan php* folders">Rescan</button>
                  </div>
                </div>
              </div>
              <div class="ov-php-list" id="php-cards"></div>
              <div class="php-download-block" id="php-download-block">
                <div class="row-between" style="margin-top:16px">
                  <div>
                    <h3 style="margin:0 0 4px;font-size:0.92rem">Download PHP</h3>
                    <p class="muted" style="margin:0">Thread-Safe x86 builds from windows.php.net (matches Apache Win32).</p>
                  </div>
                  <button class="btn" type="button" id="php-load-releases">Refresh list</button>
                </div>
                <div id="php-download-progress" class="php-dl-progress hidden"></div>
                <div id="php-releases" class="php-releases"></div>
              </div>
            </div>
          </div>

          <aside class="stack ov-side">
            <div class="card">
              <h2>Open</h2>
              <p class="muted">Common tools</p>
              <div class="ov-tool-list" id="ov-tools"></div>
            </div>
            <div class="card">
              <h2>Sites</h2>
              <p class="muted">Built-in hosts</p>
              <div class="ov-site-list" id="site-links"></div>
            </div>
            <div class="card ov-hint-card">
              <h2>Tips</h2>
              <ul class="ov-tips" id="ov-tips">
                <li>PHP versions are folders named <code>phpX.Y.Z</code> under the install root — download more below.</li>
                <li>Stopped PHP versions won’t serve their hosts until you start them.</li>
                <li>Mail from PHP lands in Mailpit — nothing is sent to the internet.</li>
                <li>Open <strong>FTP</strong> for the host, port, and upload accounts.</li>
                <li>Use <strong>Sites</strong> to add custom domains like <code>myapp.test</code>.</li>
              </ul>
            </div>
          </aside>
        </div>
      </section>

      <section class="section" id="view-sites">
        <div class="sites-layout">
          <div class="stack">
            <div class="card">
              <div class="row-between">
                <div>
                  <h2>Your websites</h2>
                  <p class="muted">Custom domains with their own folder and PHP version.</p>
                </div>
                <div class="btn-row">
                  <button class="btn" type="button" id="site-sync-hosts-btn">Sync hosts</button>
                  <button class="btn primary" type="button" id="site-new-btn">Add site</button>
                </div>
              </div>
              <div id="custom-sites-table" class="sites-table-wrap"></div>
              <div id="hosts-hint" class="hosts-hint hidden"></div>
            </div>

            <div class="card">
              <h2>Built-in hosts</h2>
              <p class="muted">System PHP routers — document root <code><?= htmlspecialchars(WEB_ROOT . '/htdocs', ENT_QUOTES, 'UTF-8') ?></code>.</p>
              <div id="system-sites" class="stack" style="margin-top:12px"></div>
            </div>
          </div>

          <div class="card site-form-card" id="site-form-card">
            <h2 id="site-form-title">Add site</h2>
            <form id="site-form" class="site-form" autocomplete="off">
              <input type="hidden" id="site-id" value="">
              <label>Name
                <input type="text" id="site-name" placeholder="My project" required>
              </label>
              <label>Domain
                <input type="text" id="site-domain" placeholder="myapp.test" required>
              </label>
              <label>Aliases <span class="muted">(optional, spaces or commas)</span>
                <input type="text" id="site-aliases" placeholder="www.myapp.test myapp.local">
              </label>
              <label>Local path
                <div class="path-row">
                  <input type="text" id="site-root" placeholder="<?= htmlspecialchars(WEB_ROOT . '/htdocs/myapp', ENT_QUOTES, 'UTF-8') ?>" required>
                  <button class="btn" type="button" id="site-suggest-path">Use htdocs</button>
                </div>
              </label>
              <label>PHP version
                <select id="site-php"></select>
              </label>
              <label class="check-row">
                <input type="checkbox" id="site-enabled" checked>
                Enabled (serve over Apache)
              </label>
              <label class="check-row">
                <input type="checkbox" id="site-create-folder" checked>
                Create folder + index.php if missing
              </label>
              <div class="btn-row">
                <button class="btn primary" type="submit" id="site-save-btn">Save site</button>
                <button class="btn" type="button" id="site-reset-btn">Clear</button>
              </div>
              <p class="meta">Saving updates Apache vhosts, reloads Apache, and tries to write the Windows hosts file.</p>
            </form>
          </div>
        </div>
      </section>

      <section class="section" id="view-php">
        <div class="card php-toolbar-card">
          <div class="toolbar php-toolbar">
            <div class="php-mode-tabs" role="tablist">
              <button type="button" class="mode-tab active" data-php-mode="simple">Simple</button>
              <button type="button" class="mode-tab" data-php-mode="advanced">Advanced</button>
            </div>
            <select id="phpini-version" aria-label="PHP version"></select>
            <button class="btn" type="button" id="phpini-reload">Reload</button>
            <button class="btn primary" type="button" id="phpini-save">Save php.ini</button>
            <button class="btn warn" type="button" id="phpini-restart" title="Restart this PHP FastCGI worker">Restart PHP</button>
          </div>
          <p class="meta" id="phpini-path">Select a PHP version</p>
        </div>

        <div class="grid two php-config-grid" style="margin-top:14px">
          <div class="card">
            <div class="row-between">
              <h2>Error display</h2>
              <div class="level-live" id="phpini-level-live" aria-live="polite"></div>
            </div>
            <p class="muted">What visitors and logs see when something goes wrong.</p>

            <div class="toggle-list" id="phpini-toggles-simple">
              <label class="toggle-row">
                <span>
                  <strong>Show errors on page</strong>
                  <small>display_errors — print errors in the browser</small>
                </span>
                <span class="toggle-wrap">
                  <input type="checkbox" id="phpini-display_errors" class="toggle-input">
                  <span class="toggle-ui" aria-hidden="true"></span>
                </span>
              </label>
              <label class="toggle-row">
                <span>
                  <strong>Show startup errors</strong>
                  <small>display_startup_errors — boot / ini load failures</small>
                </span>
                <span class="toggle-wrap">
                  <input type="checkbox" id="phpini-display_startup_errors" class="toggle-input">
                  <span class="toggle-ui" aria-hidden="true"></span>
                </span>
              </label>
              <label class="toggle-row">
                <span>
                  <strong>Log errors to file</strong>
                  <small>log_errors — write to error_log path</small>
                </span>
                <span class="toggle-wrap">
                  <input type="checkbox" id="phpini-log_errors" class="toggle-input">
                  <span class="toggle-ui" aria-hidden="true"></span>
                </span>
              </label>
              <label class="toggle-row php-adv-only hidden">
                <span>
                  <strong>HTML-formatted errors</strong>
                  <small>html_errors — styled error output</small>
                </span>
                <span class="toggle-wrap">
                  <input type="checkbox" id="phpini-html_errors" class="toggle-input">
                  <span class="toggle-ui" aria-hidden="true"></span>
                </span>
              </label>
              <label class="toggle-row php-adv-only hidden">
                <span>
                  <strong>Expose PHP version</strong>
                  <small>expose_php — X-Powered-By header</small>
                </span>
                <span class="toggle-wrap">
                  <input type="checkbox" id="phpini-expose_php" class="toggle-input">
                  <span class="toggle-ui" aria-hidden="true"></span>
                </span>
              </label>
            </div>
          </div>

          <div class="card">
            <h2>Error reporting level</h2>
            <p class="muted">Which severity types PHP reports. Active levels show as badges above.</p>

            <div class="preset-row" id="phpini-presets"></div>

            <div class="level-chips" id="phpini-levels" role="group" aria-label="Error levels">
              <button type="button" class="level-chip error" data-level="error">
                <span class="level-dot"></span>Error
              </button>
              <button type="button" class="level-chip warn" data-level="warning">
                <span class="level-dot"></span>Warning
              </button>
              <button type="button" class="level-chip notice" data-level="notice">
                <span class="level-dot"></span>Notice
              </button>
              <button type="button" class="level-chip notice" data-level="deprecated">
                <span class="level-dot"></span>Deprecated
              </button>
              <button type="button" class="level-chip info" data-level="strict">
                <span class="level-dot"></span>Strict
              </button>
            </div>

            <div class="php-adv-only hidden" style="margin-top:14px">
              <label class="field-label">error_reporting expression
                <input type="text" id="phpini-error_reporting" class="field-input mono" spellcheck="false" placeholder="E_ALL">
              </label>
              <p class="meta">Editing chips updates this. You can also type a custom expression.</p>
            </div>
          </div>
        </div>

        <div class="card php-adv-only hidden" style="margin-top:14px" id="phpini-advanced">
          <h2>Limits &amp; uploads</h2>
          <p class="muted">Common resource and upload settings.</p>
          <div class="form-grid">
            <label class="field-label">Memory limit
              <input type="text" id="phpini-memory_limit" class="field-input" placeholder="128M">
            </label>
            <label class="field-label">Max execution time (sec)
              <input type="text" id="phpini-max_execution_time" class="field-input" placeholder="30">
            </label>
            <label class="field-label">Post max size
              <input type="text" id="phpini-post_max_size" class="field-input" placeholder="8M">
            </label>
            <label class="field-label">Upload max filesize
              <input type="text" id="phpini-upload_max_filesize" class="field-input" placeholder="2M">
            </label>
            <label class="field-label">Timezone
              <input type="text" id="phpini-date_timezone" class="field-input" placeholder="Asia/Kolkata">
            </label>
            <label class="field-label">Error log path
              <input type="text" id="phpini-error_log" class="field-input mono" placeholder="<?= htmlspecialchars(WEB_ROOT . '/php…/logs/php_errors.log', ENT_QUOTES, 'UTF-8') ?>">
            </label>
          </div>
          <div class="toggle-list" style="margin-top:12px">
            <label class="toggle-row">
              <span>
                <strong>File uploads</strong>
                <small>file_uploads</small>
              </span>
              <span class="toggle-wrap">
                <input type="checkbox" id="phpini-file_uploads" class="toggle-input">
                <span class="toggle-ui" aria-hidden="true"></span>
              </span>
            </label>
            <label class="toggle-row">
              <span>
                <strong>Allow URL fopen</strong>
                <small>allow_url_fopen — remote include/open</small>
              </span>
              <span class="toggle-wrap">
                <input type="checkbox" id="phpini-allow_url_fopen" class="toggle-input">
                <span class="toggle-ui" aria-hidden="true"></span>
              </span>
            </label>
            <label class="toggle-row">
              <span>
                <strong>Short open tags</strong>
                <small>short_open_tag — &lt;? … ?&gt;</small>
              </span>
              <span class="toggle-wrap">
                <input type="checkbox" id="phpini-short_open_tag" class="toggle-input">
                <span class="toggle-ui" aria-hidden="true"></span>
              </span>
            </label>
          </div>
        </div>

        <p class="meta" style="margin-top:12px" id="phpini-hint"></p>
      </section>

      <section class="section" id="view-config">
        <div class="card">
          <div class="toolbar">
            <select id="config-select">
              <option value="php-fpm">Apache PHP vhosts</option>
              <option value="httpd">Apache httpd.conf</option>
              <option value="php74">php.ini (7.4)</option>
              <option value="php80">php.ini (8.0)</option>
              <option value="php84">php.ini (8.4)</option>
            </select>
            <button class="btn" type="button" id="reload-config">Reload</button>
            <button class="btn primary" type="button" id="save-config">Save</button>
            <span class="muted" id="config-path"></span>
          </div>
          <textarea class="editor code" id="config-editor" spellcheck="false"></textarea>
          <p class="meta">Saves create a timestamped <code>.bak-*</code> beside the file. Apache configs are syntax-checked after save.</p>
        </div>
      </section>

      <section class="section" id="view-logs">
        <div class="card">
          <div class="toolbar log-toolbar">
            <div class="log-source-tabs" id="log-source-tabs" role="tablist"></div>
            <div class="log-kind-tabs" id="log-kind-tabs">
              <button type="button" class="log-kind active" data-kind="error">Error</button>
              <button type="button" class="log-kind" data-kind="access">Access</button>
              <button type="button" class="log-kind hidden" data-kind="php">PHP runtime</button>
            </div>
          </div>
          <div class="toolbar log-toolbar">
            <input type="search" id="log-search" placeholder="Search message, code, client, path…" autocomplete="off">
            <select id="log-level-filter">
              <option value="all">All types</option>
              <option value="error">Error</option>
              <option value="warn">Warn</option>
              <option value="notice">Notice</option>
              <option value="info">Info</option>
            </select>
            <select id="log-category-filter">
              <option value="all">All categories</option>
            </select>
            <select id="log-domain-filter">
              <option value="all">All domains</option>
            </select>
            <button class="btn" type="button" id="reload-logs">Reload</button>
            <button class="btn" type="button" id="toggle-raw-logs">Raw</button>
          </div>
          <div class="log-domains" id="log-domains"></div>
          <div class="log-meta row-between">
            <span class="muted" id="log-path"></span>
            <div class="log-counts" id="log-counts"></div>
          </div>
          <div class="log-table-wrap" id="log-table-wrap">
            <table class="log-table" id="log-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Type</th>
                  <th>Category</th>
                  <th>Code</th>
                  <th>Domain</th>
                  <th>Client</th>
                  <th>Message</th>
                </tr>
              </thead>
              <tbody id="log-rows"></tbody>
            </table>
            <p class="muted hidden" id="log-empty">No log entries for this filter.</p>
          </div>
          <pre class="logs code hidden" id="log-view"></pre>
        </div>
      </section>

      <section class="section" id="view-ftp">
        <div class="ftp-layout">
          <div class="stack">
            <div class="card" id="ftp-status-card">
              <div id="ftp-status"><p class="muted">Loading FTP…</p></div>
            </div>
            <div class="card">
              <h2>Accounts</h2>
              <p class="muted">Each account is locked to its home folder. Paths stay inside this stack.</p>
              <div id="ftp-users" class="sites-table-wrap"></div>
            </div>
            <div class="card">
              <div class="row-between">
                <h2>Recent log</h2>
                <span class="muted" id="ftp-log-path"></span>
              </div>
              <pre class="ftp-log" id="ftp-log">No log entries yet.</pre>
            </div>
          </div>
          <div class="stack">
            <div class="card">
              <h2>Listener</h2>
              <form id="ftp-settings-form" class="site-form" autocomplete="off">
                <label>Listen
                  <select id="ftp-bind">
                    <option value="127.0.0.1">This computer only (127.0.0.1)</option>
                    <option value="0.0.0.0">All interfaces</option>
                  </select>
                </label>
                <label>Port
                  <input type="number" id="ftp-port" min="1" max="65535" value="2121" required>
                </label>
                <p class="meta" id="ftp-bind-note">Clients on this PC connect to 127.0.0.1. Port 2121 starts as a normal user. Port 21 needs Administrator.</p>
                <button class="btn primary" type="submit" id="ftp-save-settings">Save listener</button>
              </form>
            </div>
            <div class="card">
              <h2 id="ftp-user-form-title">Add account</h2>
              <form id="ftp-user-form" class="site-form" autocomplete="off">
                <input type="hidden" id="ftp-user-previous" value="">
                <label>Username
                  <input type="text" id="ftp-user-name" maxlength="32" placeholder="web" required>
                </label>
                <label>Password
                  <input type="password" id="ftp-user-pass" autocomplete="new-password" placeholder="Required for a new account">
                </label>
                <label>Home folder
                  <div class="path-row">
                    <input type="text" id="ftp-user-home" placeholder="<?= htmlspecialchars(WEB_ROOT . '/htdocs', ENT_QUOTES, 'UTF-8') ?>" required>
                    <button class="btn" type="button" id="ftp-use-htdocs">Use htdocs</button>
                  </div>
                </label>
                <label class="check-row">
                  <input type="checkbox" id="ftp-user-write" checked>
                  Allow upload, delete, and rename
                </label>
                <label class="check-row">
                  <input type="checkbox" id="ftp-user-enabled" checked>
                  Enabled
                </label>
                <div class="btn-row">
                  <button class="btn primary" type="submit" id="ftp-save-user">Save account</button>
                  <button class="btn" type="button" id="ftp-user-clear">Clear</button>
                </div>
                <p class="meta">Leave the password blank when editing to keep the current one. A relative path such as <code>htdocs/myapp</code> is created if it sits under htdocs.</p>
              </form>
            </div>
          </div>
        </div>
      </section>

      <section class="section" id="view-database">
        <div class="card db-conn-card" id="db-conn-card">
          <div class="row-between">
            <div>
              <h2>Connection</h2>
              <p class="muted" id="db-conn-status">Loading…</p>
            </div>
            <div class="btn-row">
              <button class="btn" type="button" id="db-test-btn">Test</button>
              <button class="btn primary" type="button" id="db-save-btn">Save</button>
            </div>
          </div>
          <form id="db-settings-form" class="db-settings-form" autocomplete="off">
            <label>Host
              <input type="text" id="db-host" value="127.0.0.1" required>
            </label>
            <label>Port
              <input type="number" id="db-port" min="1" max="65535" value="3306" required>
            </label>
            <label>Username
              <input type="text" id="db-user" value="root" required>
            </label>
            <label>Password
              <input type="password" id="db-pass" value="" autocomplete="off">
            </label>
          </form>
        </div>
        <div class="db-layout" id="db-workspace">
          <div class="db-lists">
            <div class="db-side">
              <div class="row-between" style="padding:8px 10px 0">
                <div class="group" style="border:0;padding:0">Databases</div>
                <button class="btn" type="button" id="db-create-btn" title="Create database">New</button>
              </div>
              <div id="db-list"></div>
            </div>
            <div class="db-side tall" id="table-list"></div>
          </div>
          <div class="stack">
            <div class="card">
              <div class="row-between">
                <h2>SQL · <span id="db-current" class="muted">—</span></h2>
                <button class="btn primary" type="button" id="run-sql">Run query</button>
              </div>
              <textarea class="sql-box" id="sql-input" spellcheck="false" placeholder="SELECT * FROM table LIMIT 50;"></textarea>
              <div id="query-result"></div>
            </div>
            <div class="card">
              <h2>Browse</h2>
              <div id="browse-result"><p class="muted">Select a table.</p></div>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>

  <div class="toast" id="toast"></div>
  <script src="assets/app.js?v=14"></script>
</body>
</html>
