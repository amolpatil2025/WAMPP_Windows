const API = 'api.php';
const state = {
  view: 'overview',
  status: null,
  configId: 'php-fpm',
  db: null,
  table: null,
  page: 1,
  logs: {
    source: 'apache',
    kind: 'error',
    domain: 'all',
    entries: [],
    domains: [],
    raw: false,
  },
  sites: null,
  editingSiteId: null,
  phpini: {
    version: '8.4',
    mode: 'simple',
    data: null,
    levels: {
      error: true,
      warning: true,
      notice: true,
      deprecated: true,
      strict: true,
    },
    preset: 'development',
    dirtyExpr: false,
  },
};

const $ = (sel, el = document) => el.querySelector(sel);
const $$ = (sel, el = document) => [...el.querySelectorAll(sel)];

function toast(msg, isErr = false) {
  const el = $('#toast');
  el.textContent = msg;
  el.classList.toggle('err', !!isErr);
  el.classList.add('show');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => el.classList.remove('show'), 3200);
}

async function api(action, opts = {}) {
  const method = opts.method || (opts.body ? 'POST' : 'GET');
  const url = new URL(API, location.href);
  url.searchParams.set('action', action);
  if (opts.query) {
    Object.entries(opts.query).forEach(([k, v]) => url.searchParams.set(k, v));
  }
  const res = await fetch(url, {
    method,
    headers: opts.body ? { 'Content-Type': 'application/json' } : undefined,
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  });
  const data = await res.json().catch(() => ({ ok: false, message: 'Invalid JSON' }));
  if (!res.ok || data.ok === false) {
    throw new Error(data.message || data.detail || 'Request failed');
  }
  return data;
}

function setBusy(btn, busy) {
  if (!btn) return;
  btn.disabled = !!busy;
  if (busy) {
    btn.dataset.label = btn.textContent;
    btn.textContent = 'Working…';
  } else if (btn.dataset.label) {
    btn.textContent = btn.dataset.label;
  }
}

function pill(up, labelOn = 'Running', labelOff = 'Stopped') {
  return `<span class="pill ${up ? 'up' : 'down'}">${up ? labelOn : labelOff}</span>`;
}

function overviewHealth(s) {
  const phpEntries = Object.values(s.php || {});
  const phpUp = phpEntries.filter(p => p.up).length;
  const phpTotal = phpEntries.length;
  const checks = [
    { key: 'apache', ok: !!s.apache?.up, label: 'Apache' },
    { key: 'mysql', ok: !!s.mysql?.up, label: 'MySQL', optional: true },
    { key: 'mail', ok: !!s.mail?.up, label: 'Mail', optional: !s.mail?.installed },
    { key: 'ftp', ok: !!s.ftp?.up, label: 'FTP', optional: !s.ftp?.installed },
    { key: 'php', ok: phpUp > 0, label: `PHP ${phpUp}/${phpTotal}` },
  ];
  const requiredDown = checks.filter(c => !c.optional && !c.ok);
  const optionalDown = checks.filter(c => c.optional && !c.ok);
  let tone = 'ok';
  let title = 'Everything looks good';
  let sub = '';
  if (requiredDown.length) {
    tone = 'bad';
    title = requiredDown.map(c => c.label).join(', ') + ' stopped';
    sub = 'Start the services below, then refresh.';
  } else if (optionalDown.length || phpUp < phpTotal) {
    tone = 'warn';
    const bits = [];
    if (phpUp < phpTotal) bits.push(`${phpTotal - phpUp} PHP version(s) idle`);
    optionalDown.forEach(c => bits.push(`${c.label} off`));
    title = 'Some parts are idle';
    sub = bits.join(' · ');
  }
  return { tone, title, sub, requiredDown, phpUp, phpTotal, checks };
}

function renderFirewall(d) {
  const box = $('#fw-notice');
  if (!box) return;
  const missing = (d && d.missing) || [];
  box.classList.toggle('hidden', !d || d.checked === false || missing.length === 0);
  const text = $('#fw-notice-text');
  if (text && missing.length) {
    const names = missing.map(m => m.label).join(', ');
    text.textContent = names + ' ' + (missing.length === 1 ? 'is' : 'are') + ' not allowed through Windows Firewall. Choose Allow and accept the Windows prompt so they can run.';
  }
}

async function loadFirewall() {
  try {
    const data = await api('firewall_status');
    renderFirewall(data.data);
  } catch (e) {
    renderFirewall(null);
  }
}

async function allowFirewall(btn) {
  try {
    setBusy(btn, true);
    const data = await api('firewall_allow', { body: {} });
    renderFirewall(data.data);
    toast(data.message || 'Allowed');
  } catch (e) {
    toast(e.message, true);
    await loadFirewall();
  } finally {
    setBusy(btn, false);
  }
}

function renderStatus() {
  const s = state.status;
  if (!s) return;

  const health = overviewHealth(s);
  const healthEl = $('#ov-health');
  const dot = $('#ov-health-dot');
  if (healthEl) {
    healthEl.dataset.tone = health.tone;
    $('#ov-health-title').textContent = health.title;
    $('#ov-health-sub').textContent = health.sub;
    const chips = $('#ov-health-chips');
    if (chips) {
      chips.innerHTML = (health.checks || []).map(c =>
        `<span class="health-chip ${c.ok ? 'is-on' : 'is-off'}">${escapeHtml(c.label)}</span>`
      ).join('');
    }
    if (dot) dot.dataset.tone = health.tone;
  }

  const startNeeded = $('#ov-start-needed');
  if (startNeeded) {
    const needApache = !s.apache?.up;
    const needMail = s.mail?.installed !== false && !s.mail?.up;
    const needFtp = s.ftp?.installed !== false && !s.ftp?.up;
    const needPhp = Object.values(s.php || {}).some(p => !p.up);
    const show = needApache || needMail || needFtp || needPhp;
    startNeeded.hidden = !show;
    startNeeded.textContent = 'Start stopped services';
    startNeeded.onclick = async () => {
      try {
        setBusy(startNeeded, true);
        if (needApache) await api('start_apache');
        if (needMail) await api('start_mail');
        if (needFtp) await api('start_ftp');
        if (needPhp) await api('start_php');
        await refreshStatus();
        toast('Stopped services started');
      } catch (e) {
        toast(e.message, true);
      } finally {
        setBusy(startNeeded, false);
      }
    };
  }

  fillPhpSelects(s);

  if (s.php_download?.active || s.php_download?.status === 'done' || s.php_download?.status === 'error') {
    renderPhpDownloadProgress(s.php_download);
  }

  const mail = s.mail || {};
  const ftp = s.ftp || {};
  const services = [
    {
      id: 'apache',
      name: 'Apache',
      desc: 'Web server for your sites',
      up: !!s.apache?.up,
      detail: `Port ${s.apache?.port || 80}`,
      actions: s.apache?.up
        ? [
            { action: 'restart_apache', label: 'Restart', className: 'warn' },
            { action: 'stop_apache', label: 'Stop', className: 'danger' },
          ]
        : [{ action: 'start_apache', label: 'Start', className: 'primary' }],
      links: [{ href: 'http://localhost/', label: 'Open localhost' }],
    },
    {
      id: 'mysql',
      name: 'MySQL',
      desc: 'Database (phpMyAdmin / panel SQL)',
      up: !!s.mysql?.up,
      detail: s.mysql?.up
        ? (s.mysql.version ? `v${s.mysql.version} · ${s.mysql.user}@${s.mysql.host}:${s.mysql.port}` : `${s.mysql.user}@${s.mysql.host}:${s.mysql.port}`)
        : `${s.mysql?.user || 'root'}@${s.mysql?.host || '127.0.0.1'}:${s.mysql?.port || 3306}`,
      actions: [],
      links: [
        { goto: 'database', label: 'Configure' },
        ...(s.mysql?.up ? [{ href: 'http://localhost/phpmyadmin/', label: 'phpMyAdmin' }] : []),
      ],
      note: s.mysql?.up ? '' : (s.mysql?.error || 'Start MySQL/MariaDB, or fix host/user/password in Database.'),
    },
    {
      id: 'mail',
      name: 'Mailpit',
      desc: 'Catches PHP mail locally',
      up: !!mail.up,
      detail: mail.installed === false
        ? 'Not installed (mailpit/ under install root)'
        : `SMTP :${mail.smtp_port || 1025} · UI :${mail.ui_port || 8025}`,
      actions: mail.installed === false
        ? []
        : mail.up
          ? [{ action: 'stop_mail', label: 'Stop', className: 'danger' }]
          : [{ action: 'start_mail', label: 'Start', className: 'primary' }],
      links: mail.installed === false
        ? []
        : [{ href: mail.ui_url || 'http://127.0.0.1:8025/', label: 'Open inbox' }],
    },
    {
      id: 'ftp',
      name: 'FTP',
      desc: 'Upload into site folders',
      up: !!ftp.up,
      detail: ftp.installed === false
        ? 'php.exe not found'
        : ftp.bind === '0.0.0.0'
          ? `All interfaces :${ftp.port || 2121}`
          : `This PC only :${ftp.port || 2121}`,
      actions: ftp.installed === false
        ? []
        : ftp.up
          ? [{ action: 'stop_ftp', label: 'Stop', className: 'danger' }]
          : [{ action: 'start_ftp', label: 'Start', className: 'primary' }],
      links: [{ goto: 'ftp', label: 'Accounts' }],
      note: ftp.port_busy
        ? `Port ${ftp.port} is in use by another program.`
        : (ftp.bind === '0.0.0.0' && ftp.lan_ip ? `LAN ftp://${ftp.lan_ip}:${ftp.port || 2121}/` : ''),
    },
  ];

  const svcEl = $('#ov-services');
  if (svcEl) {
    svcEl.innerHTML = services.map(svc => `
      <div class="ov-service ${svc.up ? 'is-up' : 'is-down'}">
        <div class="ov-service-status" aria-hidden="true"></div>
        <div class="ov-service-body">
          <div class="ov-service-top">
            <div>
              <div class="ov-service-name">${escapeHtml(svc.name)} ${pill(svc.up)}</div>
              <div class="ov-service-desc">${escapeHtml(svc.desc)}</div>
            </div>
            <div class="ov-service-detail">${escapeHtml(svc.detail)}</div>
          </div>
          ${svc.note ? `<p class="ov-service-note">${escapeHtml(svc.note)}</p>` : ''}
          <div class="ov-service-actions">
            ${svc.actions.map(a =>
              `<button class="btn ${a.className || ''}" type="button" data-action="${escapeAttr(a.action)}">${escapeHtml(a.label)}</button>`
            ).join('')}
            ${svc.links.map(l => l.goto
              ? `<button class="btn" type="button" data-goto="${escapeAttr(l.goto)}">${escapeHtml(l.label)}</button>`
              : `<a class="btn" href="${escapeAttr(l.href)}" target="_blank" rel="noopener">${escapeHtml(l.label)}</a>`
            ).join('')}
          </div>
        </div>
      </div>
    `).join('');
    svcEl.querySelectorAll('[data-action]').forEach(b => {
      b.addEventListener('click', () => runAction(b.dataset.action, b));
    });
    svcEl.querySelectorAll('[data-goto]').forEach(b => {
      b.addEventListener('click', () => showView(b.dataset.goto));
    });
  }

  const phpEntries = Object.entries(s.php || {});
  const phpCards = phpEntries.length ? phpEntries.map(([key, p]) => {
    const stopDisabled = p.is_default ? 'disabled title="Default PHP for localhost — cannot stop"' : '';
    const hosts = [
      ...(p.is_default ? (s.localhost_domains || []).map(h => ({ host: h, tip: 'localhost default' })) : []),
      ...(p.hosts || []).map(h => ({ host: h, tip: p.label })),
    ];
    return `
    <div class="ov-php ${p.up ? 'is-up' : 'is-down'}${p.is_default ? ' is-default' : ''}">
      <div class="ov-php-head">
        <div>
          <div class="ov-php-title">
            ${escapeHtml(p.label)}
            ${p.is_default ? '<span class="pill up">default</span>' : ''}
            ${pill(p.up)}
          </div>
          <div class="ov-php-meta">FastCGI :${p.port}${p.is_default ? ' · serves localhost' : ''}${p.folder ? ' · ' + escapeHtml(p.folder) : ''}</div>
        </div>
        <div class="btn-row">
          ${p.up
            ? `<button class="btn danger" type="button" data-php-stop="${escapeAttr(key)}" ${stopDisabled}>Stop</button>`
            : `<button class="btn primary" type="button" data-php-start="${escapeAttr(key)}">Start</button>`}
          <button class="btn" type="button" data-php-ini="${escapeAttr(key)}">php.ini</button>
        </div>
      </div>
      <div class="ov-php-hosts">
        ${hosts.map(h =>
          `<a href="http://${escapeAttr(h.host)}/" target="_blank" rel="noopener">${escapeHtml(h.host)}</a>`
        ).join('') || '<span class="muted">No hosts</span>'}
      </div>
    </div>`;
  }).join('') : '<p class="muted">No <code>php*</code> folders with php-cgi.exe found. Download a build below.</p>';
  const phpEl = $('#php-cards');
  if (phpEl) {
    phpEl.innerHTML = phpCards;
    $$('#php-cards [data-php-start]').forEach(b =>
      b.addEventListener('click', () => phpVersionAction('start_php_version', b.dataset.phpStart, b)));
    $$('#php-cards [data-php-stop]').forEach(b =>
      b.addEventListener('click', () => phpVersionAction('stop_php_version', b.dataset.phpStop, b)));
    $$('#php-cards [data-php-ini]').forEach(b =>
      b.addEventListener('click', () => {
        state.phpini.version = b.dataset.phpIni;
        showView('php');
      }));
  }

  const tools = [
    { href: 'http://localhost/', label: 'localhost', tip: `PHP ${s.default_php}` },
    { href: mail.ui_url || 'http://127.0.0.1:8025/', label: 'Mail inbox', tip: 'Mailpit' },
    { href: 'http://localhost/mail-test/', label: 'Mail test', tip: 'Send a sample' },
    { href: 'http://localhost/phpmyadmin/', label: 'phpMyAdmin', tip: s.mysql?.up ? 'Database UI' : 'MySQL offline' },
  ];
  const toolsEl = $('#ov-tools');
  if (toolsEl) {
    toolsEl.innerHTML = tools.map(t =>
      `<a class="ov-tool" href="${escapeAttr(t.href)}" target="_blank" rel="noopener">
        <strong>${escapeHtml(t.label)}</strong>
        <span>${escapeHtml(t.tip)}</span>
      </a>`
    ).join('');
  }

  const allHosts = [
    ...(s.localhost_domains || []).map(h => ({ host: h, label: `default PHP ${s.default_php}` })),
    ...Object.values(s.php || {}).flatMap(p => p.hosts.map(h => ({ host: h, label: p.label }))),
  ];
  const sitesEl = $('#site-links');
  if (sitesEl) {
    sitesEl.innerHTML = allHosts.map(x =>
      `<a class="ov-site" href="http://${escapeAttr(x.host)}/" target="_blank" rel="noopener">
        <strong>${escapeHtml(x.host)}</strong>
        <span>${escapeHtml(x.label)}</span>
      </a>`
    ).join('');
  }
}

function fillPhpSelects(s) {
  const entries = Object.entries(s.php || {});
  const opts = entries.map(([key, p]) =>
    `<option value="${escapeAttr(key)}">${escapeHtml(p.label || ('PHP ' + key))}</option>`
  ).join('');
  const def = s.default_php || (entries[0] && entries[0][0]) || '';
  ['#default-php-select', '#phpini-version'].forEach(sel => {
    const el = $(sel);
    if (!el) return;
    const prev = el.value;
    el.innerHTML = opts || '<option value="">No PHP installed</option>';
    if (sel === '#default-php-select' && def) el.value = def;
    else if (prev && [...el.options].some(o => o.value === prev)) el.value = prev;
    else if (def) el.value = def;
  });
}

function renderPhpDownloadProgress(dl) {
  const el = $('#php-download-progress');
  if (!el || !dl) return;
  if (!dl.active && dl.status !== 'done' && dl.status !== 'error' && dl.status !== 'queued') {
    el.classList.add('hidden');
    return;
  }
  el.classList.remove('hidden');
  const pct = Math.max(0, Math.min(100, Number(dl.percent) || 0));
  const tone = dl.status === 'error' ? 'bad' : (dl.status === 'done' ? 'ok' : 'run');
  el.innerHTML = `
    <div class="php-dl-bar" data-tone="${tone}"><span style="width:${pct}%"></span></div>
    <div class="php-dl-msg">${escapeHtml(dl.message || dl.status || '')}${dl.version ? ' · PHP ' + escapeHtml(dl.version) : ''}</div>`;
}

async function loadPhpReleases(btn) {
  try {
    setBusy(btn, true);
    const data = await api('php_available');
    renderPhpReleases(data.releases || []);
    if (data.download) renderPhpDownloadProgress(data.download);
  } catch (e) {
    toast(e.message, true);
    const box = $('#php-releases');
    if (box) box.innerHTML = `<p class="muted">${escapeHtml(e.message)}</p>`;
  } finally {
    setBusy(btn, false);
  }
}

function renderPhpReleases(list) {
  const box = $('#php-releases');
  if (!box) return;
  if (!list.length) {
    box.innerHTML = '<p class="muted">No Thread-Safe x86 builds found.</p>';
    return;
  }
  box.innerHTML = `<table class="sites-table">
    <thead><tr><th>Version</th><th>Build</th><th>Size</th><th>Status</th><th></th></tr></thead>
    <tbody>
      ${list.map(r => {
        let status = 'Available';
        let action = `<button class="btn primary" type="button" data-php-dl="${escapeAttr(r.version)}">Download</button>`;
        if (r.installed) {
          status = 'Installed';
          action = `<button class="btn primary" type="button" data-php-start="${escapeAttr(r.key)}">Start</button>`;
        } else if (r.installed_other) {
          status = `Have ${escapeHtml(r.installed_folder || r.key)}`;
          action = `<button class="btn" type="button" data-php-dl="${escapeAttr(r.version)}">Get ${escapeHtml(r.version)}</button>`;
        }
        return `<tr>
          <td class="mono">PHP ${escapeHtml(r.version)}</td>
          <td class="mono">${escapeHtml(r.build)}</td>
          <td>${escapeHtml(r.size || '—')}</td>
          <td>${status}</td>
          <td class="actions">${action}</td>
        </tr>`;
      }).join('')}
    </tbody>
  </table>`;
  box.querySelectorAll('[data-php-dl]').forEach(b => {
    b.addEventListener('click', () => startPhpDownload(b.dataset.phpDl, b));
  });
  box.querySelectorAll('[data-php-start]').forEach(b => {
    b.addEventListener('click', () => phpVersionAction('start_php_version', b.dataset.phpStart, b));
  });
}

async function startPhpDownload(version, btn) {
  try {
    setBusy(btn, true);
    const data = await api('php_download', { body: { version } });
    toast(data.message || 'Download started');
    renderPhpDownloadProgress(data.download);
    pollPhpDownload();
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

let phpDlTimer = null;
function pollPhpDownload() {
  clearInterval(phpDlTimer);
  phpDlTimer = setInterval(async () => {
    try {
      const data = await api('php_download_status');
      const dl = data.download || {};
      renderPhpDownloadProgress(dl);
      if (data.data) {
        state.status = data.data;
        renderStatus();
      }
      if (!dl.active) {
        clearInterval(phpDlTimer);
        phpDlTimer = null;
        if (dl.status === 'done') {
          toast(dl.message || 'PHP installed');
          loadPhpReleases();
          // Ensure php74 / localhost74 / … are in the Windows hosts file (may prompt UAC).
          try {
            const h = await api('sites_sync_hosts', { method: 'POST' });
            if (h.hosts && h.hosts.ok === false) showHostsHint(h.hosts);
            else if (h.hosts?.added?.length) toast('Hosts added: ' + h.hosts.added.join(', '));
          } catch (_) { /* ignore */ }
          if (dl.key) {
            await phpVersionAction('start_php_version', dl.key, null);
          }
        } else if (dl.status === 'error') {
          toast(dl.error || dl.message || 'Download failed', true);
        }
      }
    } catch (_) { /* ignore poll errors */ }
  }, 1500);
}

async function rescanPhp(btn) {
  try {
    setBusy(btn, true);
    const data = await api('php_refresh');
    if (data.data) {
      state.status = data.data;
      renderStatus();
    }
    if (data.hosts) showHostsHint(data.hosts);
    toast(data.message || 'Rescanned');
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

async function phpVersionAction(action, version, btn) {
  try {
    if (btn) setBusy(btn, true);
    const data = await api(action, {
      method: 'POST',
      body: { version },
      query: { version },
    });
    if (data.data) {
      state.status = data.data;
      renderStatus();
    }
    toast(data.message || 'Done');
  } catch (e) {
    toast(e.message, true);
  } finally {
    if (btn) setBusy(btn, false);
  }
}

async function saveDefaultPhp(btn) {
  const version = $('#default-php-select').value;
  try {
    setBusy(btn, true);
    const data = await api('set_default_php', { body: { version } });
    if (data.data) {
      state.status = data.data;
      renderStatus();
    }
    toast(data.message || 'Default PHP updated');
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

async function refreshStatus() {
  const data = await api('status');
  state.status = data.data;
  renderStatus();
}

async function runAction(action, btn) {
  try {
    setBusy(btn, true);
    const data = await api(action);
    if (data.data) {
      state.status = data.data;
      renderStatus();
    } else {
      await refreshStatus();
    }
    toast(data.message || 'Done');
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

function showView(name) {
  state.view = name;
  $$('.nav button').forEach(b => b.classList.toggle('active', b.dataset.view === name));
  $$('.section').forEach(s => s.classList.toggle('active', s.id === 'view-' + name));
  const titles = {
        overview: ['Overview', 'See what’s running and start what you need'],
    sites: ['Sites', 'Add domains, local folders, and PHP versions'],
    php: ['PHP Config', 'Friendly php.ini controls — errors, limits, toggles'],
    config: ['Configuration editor', 'Edit Apache and PHP ini files'],
    logs: ['Logs', 'Parsed Apache / PHP logs by version, type, and message'],
    database: ['Database', 'Connection settings, browse schemas, and run SQL'],
    ftp: ['FTP', 'Plain FTP into folders inside this stack'],
  };
  const t = titles[name] || titles.overview;
  $('#page-title').textContent = t[0];
  $('#page-sub').textContent = t[1];

  if (name === 'config') loadConfig();
  if (name === 'php') loadPhpIni();
  if (name === 'logs') loadLogs();
  if (name === 'sites') loadSites();
  if (name === 'database') loadDatabases().catch(e => toast(e.message, true));
  if (name === 'ftp') loadFtp().catch(e => toast(e.message, true));
  if (name === 'overview') {
    if (!$('#php-releases')?.children.length) loadPhpReleases().catch(() => {});
  }
}

async function loadConfig() {
  const id = $('#config-select').value;
  state.configId = id;
  try {
    const data = await api('config_get', { query: { id } });
    $('#config-path').textContent = data.path;
    $('#config-editor').value = data.content;
  } catch (e) {
    toast(e.message, true);
  }
}

async function saveConfig(btn) {
  try {
    setBusy(btn, true);
    const data = await api('config_save', {
      body: { id: state.configId, content: $('#config-editor').value },
    });
    toast(data.message + (data.apache_test ? ' · ' + data.apache_test.trim() : ''));
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

async function loadLogs() {
  const source = state.logs.source;
  const kind = state.logs.kind;
  try {
    const data = await api('logs', { query: { source, kind, limit: '250' } });
    state.logs.entries = data.entries || [];
    state.logs.domains = data.domains || [];
    $('#log-path').textContent = (data.label || '') + ' · ' +
      (data.exists ? data.path : data.path + ' (missing)') +
      (data.size ? ' · ' + formatBytes(data.size) : '') +
      ' · ' + (data.total || 0) + ' lines';
    renderLogCounts(data.counts || {});
    renderDomainChips(data.domains || [], data.domains_seen || []);
    fillCategoryFilter(state.logs.entries);
    renderLogRows();
    $('#log-view').textContent = data.content || '(empty)';
    if (data.sources) renderLogSourceTabs(data.sources);
    syncLogKindTabs(source);
  } catch (e) {
    toast(e.message, true);
  }
}

function syncLogKindTabs(source) {
  const phpOnly = /^php\d/.test(source || '');
  $$('#log-kind-tabs .log-kind').forEach(btn => {
    if (btn.dataset.kind === 'php') {
      btn.classList.toggle('hidden', !phpOnly);
    }
  });
  if (!phpOnly && state.logs.kind === 'php') {
    state.logs.kind = 'error';
    $$('#log-kind-tabs .log-kind').forEach(b => b.classList.toggle('active', b.dataset.kind === 'error'));
  }
}

function renderLogSourceTabs(sources) {
  const wrap = $('#log-source-tabs');
  if (!wrap || !sources) return;
  const order = Object.keys(sources);
  wrap.innerHTML = order.map(sid => {
    const label = sources[sid].label || sid;
    const active = (state.logs.source || 'apache') === sid ? ' active' : '';
    return `<button type="button" class="log-tab${active}" data-source="${escapeAttr(sid)}">${escapeHtml(label)}</button>`;
  }).join('');
  wrap.querySelectorAll('.log-tab').forEach(b => {
    b.addEventListener('click', () => setLogSource(b.dataset.source));
  });
}

function renderLogCounts(counts) {
  const order = ['error', 'warn', 'notice', 'info', 'other'];
  const total = Object.values(counts).reduce((a, b) => a + (b || 0), 0);
  $('#log-counts').innerHTML = `<span class="log-count">${total} shown</span>` + order
    .filter(k => (counts[k] || 0) > 0)
    .map(k => `<span class="log-count ${k}">${k} ${counts[k]}</span>`)
    .join('');
}

function renderDomainChips(domains, seen) {
  const wrap = $('#log-domains');
  const list = (domains || []).filter(d => d && d !== '*');
  if (!list.length) {
    wrap.innerHTML = '<span class="muted" style="font-size:0.8rem">Apache main log — not tied to a PHP host</span>';
    return;
  }
  const seenSet = new Set((seen || []).map(s => String(s).toLowerCase()));
  wrap.innerHTML = `<span class="muted" style="font-size:0.75rem;margin-right:4px">Domains</span>` +
    `<button type="button" class="log-domain-chip ${!state.logs.domain || state.logs.domain === 'all' ? 'active' : ''}" data-domain="all">All</button>` +
    list.map(d => {
      const hit = seenSet.has(String(d).toLowerCase());
      return `<button type="button" class="log-domain-chip ${state.logs.domain === d ? 'active' : ''}" data-domain="${escapeAttr(d)}">${escapeHtml(d)}${hit ? '' : ''}</button>`;
    }).join('');
  wrap.querySelectorAll('[data-domain]').forEach(btn => {
    btn.addEventListener('click', () => {
      state.logs.domain = btn.dataset.domain;
      $('#log-domain-filter').value = btn.dataset.domain;
      $$('#log-domains .log-domain-chip').forEach(c => c.classList.toggle('active', c.dataset.domain === btn.dataset.domain));
      renderLogRows();
    });
  });
  // sync select options
  const sel = $('#log-domain-filter');
  const cur = state.logs.domain || 'all';
  sel.innerHTML = '<option value="all">All domains</option>' +
    list.map(d => `<option value="${escapeAttr(d)}">${escapeHtml(d)}</option>`).join('');
  sel.value = list.includes(cur) || cur === 'all' ? cur : 'all';
}

function fillCategoryFilter(entries) {
  const cats = [...new Set(entries.map(e => e.category).filter(Boolean))].sort();
  const sel = $('#log-category-filter');
  const prev = sel.value || 'all';
  sel.innerHTML = '<option value="all">All categories</option>' +
    cats.map(c => `<option value="${escapeAttr(c)}">${escapeHtml(c)}</option>`).join('');
  sel.value = cats.includes(prev) ? prev : 'all';
}

function filteredLogEntries() {
  const q = ($('#log-search').value || '').trim().toLowerCase();
  let level = $('#log-level-filter').value;
  const cat = $('#log-category-filter').value;
  const domain = $('#log-domain-filter').value || state.logs.domain || 'all';
  return state.logs.entries.filter(e => {
    let lv = e.level || 'other';
    if (lv === 'warning') lv = 'warn';
    if (level !== 'all' && lv !== level) return false;
    if (cat !== 'all' && e.category !== cat) return false;
    if (domain !== 'all') {
      const d = (e.domain || '').toLowerCase();
      // For error logs without Host, keep rows when viewing that PHP version's file
      if (d && d !== domain.toLowerCase()) return false;
      if (!d && state.logs.kind === 'access') return false;
    }
    if (!q) return true;
    const hay = [e.time, e.level, e.category, e.code, e.client, e.domain, e.message, e.path, e.raw]
      .join(' ').toLowerCase();
    return hay.includes(q);
  });
}

function renderLogRows() {
  const rows = filteredLogEntries();
  const body = $('#log-rows');
  const empty = $('#log-empty');
  if (!rows.length) {
    body.innerHTML = '';
    empty.classList.remove('hidden');
    return;
  }
  empty.classList.add('hidden');
  body.innerHTML = rows.map(e => {
    let lv = e.level || 'info';
    if (lv === 'warning') lv = 'warn';
    const rowClass = lv === 'error' ? 'log-row-error' : (lv === 'warn' ? 'log-row-warn' : '');
    return `<tr class="${rowClass}">
      <td class="col-time">${escapeHtml(shortLogTime(e.time))}</td>
      <td class="col-type"><span class="log-level ${escapeAttr(lv)}">${escapeHtml(lv)}</span></td>
      <td class="col-cat">${escapeHtml(e.category || '—')}</td>
      <td class="col-code">${escapeHtml(e.code || '—')}</td>
      <td class="col-domain" title="${escapeAttr(e.domain || '')}">${escapeHtml(e.domain || '—')}</td>
      <td class="col-client" title="${escapeAttr(e.client || '')}">${escapeHtml(e.client || '—')}</td>
      <td class="col-msg">${escapeHtml(e.message || e.raw || '')}</td>
    </tr>`;
  }).join('');
}

function shortLogTime(t) {
  if (!t) return '—';
  const m1 = t.match(/(\w{3})\s+(\d{1,2})\s+(\d{2}:\d{2}:\d{2})/);
  if (m1) return `${m1[1]} ${m1[2]} ${m1[3]}`;
  const m2 = t.match(/(\d{2}\/\w{3}\/\d{4}):(\d{2}:\d{2}:\d{2})/);
  if (m2) return `${m2[1]} ${m2[2]}`;
  return t.length > 22 ? t.slice(0, 22) : t;
}

function formatBytes(n) {
  n = Number(n) || 0;
  if (n < 1024) return n + ' B';
  if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
  return (n / (1024 * 1024)).toFixed(1) + ' MB';
}

function setLogSource(source) {
  state.logs.source = source;
  state.logs.domain = 'all';
  $$('#log-source-tabs .log-tab').forEach(b => b.classList.toggle('active', b.dataset.source === source));
  syncLogKindTabs(source);
  loadLogs();
}

function setLogKind(kind) {
  state.logs.kind = kind;
  $$('#log-kind-tabs .log-kind').forEach(b => b.classList.toggle('active', b.dataset.kind === kind));
  loadLogs();
}

async function loadDbSettings() {
  try {
    const data = await api('db_settings_get');
    const s = data.settings || {};
    if ($('#db-host')) $('#db-host').value = s.host || '127.0.0.1';
    if ($('#db-port')) $('#db-port').value = s.port || 3306;
    if ($('#db-user')) $('#db-user').value = s.user || 'root';
    if ($('#db-pass') && document.activeElement !== $('#db-pass')) $('#db-pass').value = s.pass || '';
    const st = $('#db-conn-status');
    if (st) {
      if (data.connected) {
        st.textContent = `Connected · ${data.version || 'MySQL'} · ${s.user}@${s.host}:${s.port}`;
        st.dataset.tone = 'ok';
      } else {
        st.textContent = data.error || 'Not connected';
        st.dataset.tone = 'bad';
      }
    }
    return data.connected;
  } catch (e) {
    const st = $('#db-conn-status');
    if (st) {
      st.textContent = e.message;
      st.dataset.tone = 'bad';
    }
    return false;
  }
}

function collectDbForm() {
  return {
    host: ($('#db-host')?.value || '').trim(),
    port: Number($('#db-port')?.value || 3306),
    user: ($('#db-user')?.value || '').trim(),
    pass: $('#db-pass')?.value || '',
  };
}

async function saveDbSettings(btn) {
  try {
    setBusy(btn, true);
    const data = await api('db_settings_save', { body: collectDbForm() });
    toast(data.message || 'Saved');
    if (data.data) {
      state.status = data.data;
      renderStatus();
    }
    await loadDbSettings();
    if (data.connected) await loadDatabases();
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

async function testDbSettings(btn) {
  try {
    setBusy(btn, true);
    const data = await api('db_settings_test', { body: collectDbForm() });
    toast(data.message || 'OK');
    const st = $('#db-conn-status');
    if (st) {
      st.textContent = data.message || 'Connected';
      st.dataset.tone = 'ok';
    }
  } catch (e) {
    toast(e.message, true);
    const st = $('#db-conn-status');
    if (st) {
      st.textContent = e.message;
      st.dataset.tone = 'bad';
    }
  } finally {
    setBusy(btn, false);
  }
}

async function createDatabase(btn) {
  const name = prompt('New database name');
  if (!name) return;
  try {
    setBusy(btn, true);
    const data = await api('db_create', { body: { name: name.trim() } });
    toast(data.message || 'Created');
    await loadDatabases();
    if (data.name) await selectDatabase(data.name);
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

async function loadDatabases() {
  try {
    const connected = await loadDbSettings();
    if (!connected) {
      const side = $('#db-list');
      if (side) side.innerHTML = '<p class="muted" style="padding:12px">Fix the connection above, then refresh.</p>';
      $('#table-list').innerHTML = '';
      return;
    }
    const data = await api('db_list');
    const side = $('#db-list');
    side.innerHTML = data.databases.map(db =>
      `<button type="button" data-db="${escapeAttr(db)}" class="${state.db === db ? 'active' : ''}">${escapeHtml(db)}</button>`
    ).join('') || '<p class="muted" style="padding:12px">No databases</p>';
    side.querySelectorAll('[data-db]').forEach(btn => {
      btn.addEventListener('click', () => selectDatabase(btn.dataset.db));
    });
    if (!state.db && data.databases.length) {
      const preferred = data.databases.find(d => d !== 'mysql') || data.databases[0];
      await selectDatabase(preferred);
    } else if (state.db) {
      await loadTables();
    }
  } catch (e) {
    toast(e.message, true);
    const side = $('#db-list');
    if (side) side.innerHTML = `<p class="muted" style="padding:12px">${escapeHtml(e.message)}</p>`;
  }
}

async function selectDatabase(db) {
  state.db = db;
  state.table = null;
  state.page = 1;
  $$('#db-list [data-db]').forEach(b => b.classList.toggle('active', b.dataset.db === db));
  $('#db-current').textContent = db;
  await loadTables();
}

async function loadTables() {
  if (!state.db) return;
  try {
    const data = await api('db_tables', { query: { db: state.db } });
    const wrap = $('#table-list');
    wrap.innerHTML = '<div class="group">Tables</div>' + (data.tables.map(t =>
      `<button type="button" data-table="${escapeAttr(t.name)}" class="${state.table === t.name ? 'active' : ''}">${escapeHtml(t.name)}</button>`
    ).join('') || '<div class="muted" style="padding:12px">No tables</div>');
    wrap.querySelectorAll('[data-table]').forEach(btn => {
      btn.addEventListener('click', () => browseTable(btn.dataset.table));
    });
    if (state.table) {
      await browseTable(state.table);
    } else {
      $('#browse-result').innerHTML = '<p class="muted">Select a table or run a query.</p>';
    }
  } catch (e) {
    toast(e.message, true);
  }
}

function renderTable(columns, rows) {
  if (!columns.length) return '<p class="muted">No columns</p>';
  const head = columns.map(c => `<th>${escapeHtml(c)}</th>`).join('');
  const body = rows.map(r => '<tr>' + columns.map(c => {
    const v = r[c];
    const text = v === null ? 'NULL' : String(v);
    return `<td title="${escapeAttr(text)}">${escapeHtml(text)}</td>`;
  }).join('') + '</tr>').join('');
  return `<div class="table-wrap"><table class="data"><thead><tr>${head}</tr></thead><tbody>${body || '<tr><td colspan="' + columns.length + '">No rows</td></tr>'}</tbody></table></div>`;
}

async function browseTable(table) {
  state.table = table;
  $$('#table-list [data-table]').forEach(b => b.classList.toggle('active', b.dataset.table === table));
  try {
    const data = await api('db_browse', {
      query: { db: state.db, table, page: String(state.page), limit: '50' },
    });
    const pages = Math.max(1, Math.ceil(data.total / data.limit));
    $('#browse-result').innerHTML = `
      <div class="row-between" style="margin-bottom:10px">
        <div><strong>${escapeHtml(table)}</strong> <span class="muted">${data.total} rows · page ${data.page}/${pages}</span></div>
        <div class="btn-row">
          <button class="btn" type="button" id="prev-page" ${data.page <= 1 ? 'disabled' : ''}>Prev</button>
          <button class="btn" type="button" id="next-page" ${data.page >= pages ? 'disabled' : ''}>Next</button>
          <button class="btn" type="button" id="show-structure">Structure</button>
        </div>
      </div>
      ${renderTable(data.columns, data.rows)}`;
    $('#prev-page')?.addEventListener('click', () => { state.page = Math.max(1, state.page - 1); browseTable(table); });
    $('#next-page')?.addEventListener('click', () => { state.page += 1; browseTable(table); });
    $('#show-structure')?.addEventListener('click', () => showStructure(table));
  } catch (e) {
    toast(e.message, true);
  }
}

async function showStructure(table) {
  try {
    const data = await api('db_structure', { query: { db: state.db, table } });
    const cols = ['Field', 'Type', 'Null', 'Key', 'Default', 'Extra'];
    const rows = data.columns.map(c => ({
      Field: c.Field, Type: c.Type, Null: c.Null, Key: c.Key, Default: c.Default, Extra: c.Extra,
    }));
    $('#browse-result').innerHTML = `
      <div class="row-between" style="margin-bottom:10px">
        <strong>Structure · ${escapeHtml(table)}</strong>
        <button class="btn" type="button" id="back-browse">Back to data</button>
      </div>
      ${renderTable(cols, rows)}
      <h3 style="margin-top:16px">CREATE</h3>
      <pre class="logs code">${escapeHtml(data.create)}</pre>`;
    $('#back-browse')?.addEventListener('click', () => browseTable(table));
  } catch (e) {
    toast(e.message, true);
  }
}

async function runQuery(btn) {
  const sql = $('#sql-input').value.trim();
  if (!sql) return toast('Enter SQL', true);
  try {
    setBusy(btn, true);
    const data = await api('db_query', { body: { db: state.db || '', sql } });
    const blocks = data.results.map((r, i) => {
      if (r.type === 'resultset') {
        return `<div class="card" style="margin-top:10px"><div class="meta">Result ${i + 1} · ${r.row_count} rows · ${data.ms} ms</div>${renderTable(r.columns, r.rows)}</div>`;
      }
      return `<div class="card" style="margin-top:10px">OK · affected ${r.affected} · ${data.ms} ms</div>`;
    }).join('') || `<div class="card">OK · ${data.ms} ms</div>`;
    $('#query-result').innerHTML = blocks;
    toast('Query finished');
    if (state.db) loadTables();
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
function escapeAttr(s) {
  return escapeHtml(s).replace(/'/g, '&#39;');
}

async function loadSites() {
  try {
    const data = await api('sites_list');
    state.sites = data.data;
    fillSitePhpOptions();
    renderCustomSites();
    renderSystemSites();
  } catch (e) {
    toast(e.message, true);
  }
}

function fillSitePhpOptions() {
  const sel = $('#site-php');
  if (!sel || !state.sites) return;
  const cur = sel.value;
  sel.innerHTML = (state.sites.php_versions || []).map(v =>
    `<option value="${escapeAttr(v.id)}">${escapeHtml(v.label)}</option>`
  ).join('');
  sel.value = cur || state.sites.default_php || '8.4';
}

function renderSystemSites() {
  const wrap = $('#system-sites');
  if (!wrap || !state.sites) return;
  wrap.innerHTML = (state.sites.system || []).map(s => `
    <div class="system-site">
      <div class="row-between">
        <strong>${escapeHtml(s.name)}</strong>
        <a class="btn" href="${escapeAttr(s.url)}" target="_blank" rel="noopener">Open</a>
      </div>
      <div class="meta mono">${escapeHtml((s.domains || []).join(' · '))}</div>
      <div class="meta">${escapeHtml(s.root)} · PHP ${escapeHtml(s.php)}</div>
    </div>
  `).join('');
}

function renderCustomSites() {
  const wrap = $('#custom-sites-table');
  if (!wrap || !state.sites) return;
  const rows = state.sites.custom || [];
  if (!rows.length) {
    wrap.innerHTML = '<p class="muted" style="padding:14px">No custom sites yet. Use the form to add a domain and folder.</p>';
    return;
  }
  wrap.innerHTML = `<table class="sites-table">
    <thead>
      <tr>
        <th>Site</th>
        <th>Domain</th>
        <th>Path</th>
        <th>PHP</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      ${rows.map(s => `
        <tr>
          <td><strong>${escapeHtml(s.name)}</strong></td>
          <td class="mono">
            <a href="${escapeAttr(s.url)}" target="_blank" rel="noopener">${escapeHtml(s.domain)}</a>
            ${(s.aliases || []).length ? `<div class="meta">${escapeHtml(s.aliases.join(', '))}</div>` : ''}
          </td>
          <td class="mono">${escapeHtml(s.root)}${s.root_exists ? '' : ' <span class="site-badge off">missing</span>'}</td>
          <td>${escapeHtml(s.php_label || s.php)}</td>
          <td><span class="site-badge ${s.enabled ? 'on' : 'off'}">${s.enabled ? 'enabled' : 'disabled'}</span></td>
          <td class="actions">
            <a class="btn" href="${escapeAttr(s.url)}" target="_blank" rel="noopener">Open</a>
            <button class="btn" type="button" data-site-edit="${escapeAttr(s.id)}">Edit</button>
            <button class="btn danger" type="button" data-site-del="${escapeAttr(s.id)}">Delete</button>
          </td>
        </tr>
      `).join('')}
    </tbody>
  </table>`;
  wrap.querySelectorAll('[data-site-edit]').forEach(b => b.addEventListener('click', () => editSite(b.dataset.siteEdit)));
  wrap.querySelectorAll('[data-site-del]').forEach(b => b.addEventListener('click', () => deleteSite(b.dataset.siteDel, b)));
}

function resetSiteForm() {
  state.editingSiteId = null;
  $('#site-form-title').textContent = 'Add site';
  $('#site-id').value = '';
  $('#site-name').value = '';
  $('#site-domain').value = '';
  $('#site-aliases').value = '';
  $('#site-root').value = '';
  $('#site-enabled').checked = true;
  $('#site-create-folder').checked = true;
  if (state.sites?.default_php) $('#site-php').value = state.sites.default_php;
  $('#site-save-btn').textContent = 'Save site';
}

function editSite(id) {
  const site = (state.sites?.custom || []).find(s => s.id === id);
  if (!site) return;
  state.editingSiteId = id;
  $('#site-form-title').textContent = 'Edit site';
  $('#site-id').value = site.id;
  $('#site-name').value = site.name || '';
  $('#site-domain').value = site.domain || '';
  $('#site-aliases').value = (site.aliases || []).join(', ');
  $('#site-root').value = site.root || '';
  $('#site-php').value = site.php || state.sites.default_php;
  $('#site-enabled').checked = !!site.enabled;
  $('#site-create-folder').checked = false;
  $('#site-save-btn').textContent = 'Update site';
  $('#site-form-card')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function suggestSitePath() {
  const domain = ($('#site-domain').value || $('#site-name').value || 'site').trim().toLowerCase();
  const slug = domain.replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'site';
  const base = (state.sites?.htdocs || '').replace(/\\/g, '/') || 'htdocs';
  $('#site-root').value = base.replace(/\/$/, '') + '/' + slug;
  if (!$('#site-domain').value && slug) {
    $('#site-domain').value = slug.includes('.') ? slug : slug + '.test';
  }
}

function showHostsHint(hosts) {
  const box = $('#hosts-hint');
  if (!box) return;
  if (!hosts) {
    box.classList.add('hidden');
    return;
  }
  const needManual = hosts.ok === false;
  const missing = (hosts.missing || []).join(', ');
  const added = (hosts.added || []).join(', ');
  box.classList.remove('hidden');
  box.innerHTML = `
    <strong>${needManual ? 'Hosts need attention' : 'Hosts file'}</strong>
    <div class="meta">${escapeHtml(hosts.message || '')}</div>
    ${added ? `<div class="meta">Added: ${escapeHtml(added)}</div>` : ''}
    ${missing && needManual ? `<div class="meta">Missing: ${escapeHtml(missing)}</div>` : ''}
    <pre>${escapeHtml(hosts.hosts_block || '')}</pre>
    <div class="btn-row" style="margin-top:8px">
      <button class="btn" type="button" id="copy-hosts-btn">Copy hosts block</button>
      <button class="btn primary" type="button" id="retry-hosts-btn">Write hosts (Admin)</button>
    </div>`;
  $('#copy-hosts-btn')?.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(hosts.hosts_block || '');
      toast('Hosts block copied');
    } catch {
      toast('Copy failed', true);
    }
  });
  $('#retry-hosts-btn')?.addEventListener('click', () => syncHosts($('#retry-hosts-btn')));
}

async function syncHosts(btn) {
  try {
    setBusy(btn, true);
    const data = await api('sites_sync_hosts', { method: 'POST' });
    showHostsHint(data.hosts);
    toast(data.message || (data.synced ? 'Hosts synced' : 'Hosts not written'), !data.synced);
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

async function saveSite(ev) {
  ev.preventDefault();
  const btn = $('#site-save-btn');
  const payload = {
    id: $('#site-id').value || undefined,
    name: $('#site-name').value.trim(),
    domain: $('#site-domain').value.trim(),
    aliases: $('#site-aliases').value.trim(),
    root: $('#site-root').value.trim(),
    php: $('#site-php').value,
    enabled: $('#site-enabled').checked,
    create_folder: $('#site-create-folder').checked,
  };
  try {
    setBusy(btn, true);
    const data = await api('sites_save', { body: payload });
    state.sites = data.data;
    fillSitePhpOptions();
    renderCustomSites();
    renderSystemSites();
    showHostsHint(data.hosts);
    resetSiteForm();
    toast(data.message || 'Saved');
    setTimeout(() => refreshStatus().catch(() => {}), 2500);
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

async function deleteSite(id, btn) {
  if (!confirm('Delete this site from Apache? Folder on disk is kept.')) return;
  try {
    setBusy(btn, true);
    const data = await api('sites_delete', { body: { id } });
    state.sites = data.data;
    renderCustomSites();
    showHostsHint(data.hosts);
    if (state.editingSiteId === id) resetSiteForm();
    toast(data.message || 'Deleted');
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

const PHPINI_LEVEL_META = {
  error: { label: 'Error', tone: 'error' },
  warning: { label: 'Warning', tone: 'warn' },
  notice: { label: 'Notice', tone: 'notice' },
  deprecated: { label: 'Deprecated', tone: 'notice' },
  strict: { label: 'Strict', tone: 'info' },
};

function setPhpMode(mode) {
  state.phpini.mode = mode;
  $$('[data-php-mode]').forEach(b => b.classList.toggle('active', b.dataset.phpMode === mode));
  const adv = mode === 'advanced';
  $$('.php-adv-only').forEach(el => el.classList.toggle('hidden', !adv));
}

function syncPhpLevelUi() {
  const levels = state.phpini.levels;
  $$('#phpini-levels .level-chip').forEach(btn => {
    const on = !!levels[btn.dataset.level];
    btn.classList.toggle('on', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
  });

  const live = $('#phpini-level-live');
  if (live) {
    live.innerHTML = Object.entries(PHPINI_LEVEL_META).map(([key, meta]) => {
      const on = !!levels[key];
      return `<span class="level-badge ${on ? meta.tone : 'off'}">${meta.label}${on ? '' : ' off'}</span>`;
    }).join('');
  }

  $$('#phpini-presets .preset-btn').forEach(b => {
    b.classList.toggle('active', b.dataset.preset === state.phpini.preset);
  });

  if (!state.phpini.dirtyExpr && $('#phpini-error_reporting')) {
    // keep expression field in sync when chips drive it
  }
}

function applyPresetToLevels(presetId, presets) {
  const meta = presets?.[presetId];
  if (!meta?.levels) return;
  state.phpini.levels = { ...meta.levels };
  state.phpini.preset = presetId;
  state.phpini.dirtyExpr = false;
  if ($('#phpini-error_reporting')) {
    $('#phpini-error_reporting').value = meta.expression || '';
  }
  syncPhpLevelUi();
}

function detectPresetFromLevels(presets) {
  if (!presets) return 'custom';
  const cur = state.phpini.levels;
  for (const [id, meta] of Object.entries(presets)) {
    const lv = meta.levels || {};
    if (['error', 'warning', 'notice', 'deprecated', 'strict'].every(k => !!lv[k] === !!cur[k])) {
      return id;
    }
  }
  return 'custom';
}

function renderPhpIni(data) {
  state.phpini.data = data;
  state.phpini.version = data.version;
  $('#phpini-version').value = data.version;
  $('#phpini-path').textContent = data.path || '';
  $('#phpini-hint').textContent = data.restart_hint || '';

  const s = data.settings || {};
  const boolMap = [
    'display_errors',
    'display_startup_errors',
    'log_errors',
    'html_errors',
    'expose_php',
    'file_uploads',
    'allow_url_fopen',
    'short_open_tag',
  ];
  boolMap.forEach(key => {
    const el = $('#phpini-' + key);
    if (el) el.checked = !!(s[key] && s[key].value);
  });

  const textMap = {
    memory_limit: 'phpini-memory_limit',
    max_execution_time: 'phpini-max_execution_time',
    post_max_size: 'phpini-post_max_size',
    upload_max_filesize: 'phpini-upload_max_filesize',
    'date.timezone': 'phpini-date_timezone',
    error_log: 'phpini-error_log',
    error_reporting: 'phpini-error_reporting',
  };
  Object.entries(textMap).forEach(([key, id]) => {
    const el = $('#' + id);
    if (el) el.value = (s[key] && s[key].value) || '';
  });

  const eu = data.error_ui || {};
  state.phpini.levels = {
    error: true,
    warning: true,
    notice: true,
    deprecated: true,
    strict: true,
    ...(eu.levels || {}),
  };
  state.phpini.preset = eu.preset || 'custom';
  state.phpini.dirtyExpr = false;

  const presetsWrap = $('#phpini-presets');
  const presets = eu.presets || {};
  presetsWrap.innerHTML = Object.entries(presets).map(([id, p]) => `
    <button type="button" class="preset-btn ${state.phpini.preset === id ? 'active' : ''}" data-preset="${escapeAttr(id)}">
      ${escapeHtml(p.label)}
      <small>${escapeHtml(p.hint || '')}</small>
    </button>
  `).join('') + `
    <button type="button" class="preset-btn ${state.phpini.preset === 'custom' ? 'active' : ''}" data-preset="custom">
      Custom
      <small>Pick levels below</small>
    </button>`;

  presetsWrap.querySelectorAll('[data-preset]').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.preset;
      if (id === 'custom') {
        state.phpini.preset = 'custom';
        syncPhpLevelUi();
        return;
      }
      applyPresetToLevels(id, presets);
    });
  });

  syncPhpLevelUi();
  setPhpMode(state.phpini.mode);
}

async function loadPhpIni() {
  const version = $('#phpini-version')?.value || state.phpini.version || '8.4';
  try {
    const data = await api('phpini_get', { query: { version } });
    renderPhpIni(data);
  } catch (e) {
    toast(e.message, true);
  }
}

function collectPhpIniPayload() {
  const version = $('#phpini-version').value;
  const payload = {
    version,
    display_errors: $('#phpini-display_errors').checked,
    display_startup_errors: $('#phpini-display_startup_errors').checked,
    log_errors: $('#phpini-log_errors').checked,
    html_errors: $('#phpini-html_errors').checked,
    expose_php: $('#phpini-expose_php').checked,
    file_uploads: $('#phpini-file_uploads').checked,
    allow_url_fopen: $('#phpini-allow_url_fopen').checked,
    short_open_tag: $('#phpini-short_open_tag').checked,
    memory_limit: $('#phpini-memory_limit').value.trim(),
    max_execution_time: $('#phpini-max_execution_time').value.trim(),
    post_max_size: $('#phpini-post_max_size').value.trim(),
    upload_max_filesize: $('#phpini-upload_max_filesize').value.trim(),
    'date.timezone': $('#phpini-date_timezone').value.trim(),
    error_log: $('#phpini-error_log').value.trim(),
    error_levels: { ...state.phpini.levels },
  };
  const expr = ($('#phpini-error_reporting')?.value || '').trim();
  if (state.phpini.mode === 'advanced' && state.phpini.dirtyExpr && expr) {
    payload.error_reporting = expr;
    delete payload.error_levels;
  }
  return payload;
}

async function savePhpIni(btn) {
  try {
    setBusy(btn, true);
    const data = await api('phpini_save', { body: collectPhpIniPayload() });
    if (data.data) renderPhpIni(data.data);
    toast((data.message || 'Saved') + (data.restart_hint ? ' · restart PHP to apply' : ''));
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

function resetFtpUserForm() {
  $('#ftp-user-previous').value = '';
  $('#ftp-user-name').value = '';
  $('#ftp-user-pass').value = '';
  $('#ftp-user-home').value = state.ftpRoot ? state.ftpRoot + '/htdocs' : 'htdocs';
  $('#ftp-user-write').checked = true;
  $('#ftp-user-enabled').checked = true;
  $('#ftp-user-form-title').textContent = 'Add account';
  syncFtpPassRequired();
}

function syncFtpPassRequired() {
  const isNew = ($('#ftp-user-previous')?.value || '') === '';
  const pass = $('#ftp-user-pass');
  if (!pass) return;
  pass.required = isNew;
  pass.placeholder = isNew ? 'Required for a new account' : 'Leave blank to keep the current password';
}

function syncFtpBindNote() {
  const note = $('#ftp-bind-note');
  if (!note || !$('#ftp-bind')) return;
  note.textContent = $('#ftp-bind').value === '0.0.0.0'
    ? 'Other computers on your network use ftp://<LAN IP>:2121/. Allow php.exe if Windows Firewall asks. Port 2121 starts as a normal user. Port 21 needs Administrator.'
    : 'Only this PC can connect, at ftp://127.0.0.1:2121/. Port 2121 starts as a normal user. Port 21 needs Administrator.';
}

function ftpAccessHtml(d) {
  const port = d.port || 2121;
  const global = d.bind === '0.0.0.0';
  const lans = Array.isArray(d.lan_ips) && d.lan_ips.length ? d.lan_ips : (d.lan_ip ? [d.lan_ip] : []);
  const rows = [{ label: 'This PC', host: '127.0.0.1', url: `ftp://127.0.0.1:${port}/` }];
  if (global) {
    if (!lans.length) {
      rows.push({ label: 'LAN', host: '', url: `ftp://<LAN IP>:${port}/` });
    } else {
      lans.forEach((ip, i) => {
        rows.push({
          label: lans.length > 1 ? `LAN ${i + 1}` : 'LAN',
          host: ip,
          url: `ftp://${ip}:${port}/`,
        });
      });
    }
  }
  const clients = d.clients || {};
  const buttons = (row) => {
    if (!row.host) return '';
    const bits = [
      `<button class="btn" type="button" data-ftp-copy="${escapeAttr(row.url)}">Copy</button>`,
      `<button class="btn" type="button" data-ftp-open="explorer" data-ftp-host="${escapeAttr(row.host)}">Explorer</button>`,
    ];
    if (clients.filezilla) {
      bits.push(`<button class="btn" type="button" data-ftp-open="filezilla" data-ftp-host="${escapeAttr(row.host)}">FileZilla</button>`);
    }
    if (clients.winscp) {
      bits.push(`<button class="btn" type="button" data-ftp-open="winscp" data-ftp-host="${escapeAttr(row.host)}">WinSCP</button>`);
    }
    return `<div class="btn-row">${bits.join('')}</div>`;
  };
  const hint = global
    ? 'From another computer on this network, open the LAN address in Explorer, FileZilla, or WinSCP. Use the account below. Set encryption to plain FTP only, and allow php.exe if Windows Firewall asks.'
    : `LAN is off. Set Listen to All interfaces, then other computers can use ftp://<LAN IP>:${port}/.`;
  return `<div class="ftp-access">
    ${rows.map(row => `<div class="ftp-access-row">
      <span class="ftp-access-label">${escapeHtml(row.label)}</span>
      <code class="ftp-access-url">${escapeHtml(row.url)}</code>
      ${buttons(row)}
    </div>`).join('')}
    <p class="meta">${escapeHtml(hint)}</p>
  </div>`;
}

async function copyFtpUrl(url) {
  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(url);
    } else {
      const ta = document.createElement('textarea');
      ta.value = url;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
    }
    toast('Copied ' + url);
  } catch (e) {
    toast(e.message || 'Could not copy', true);
  }
}

async function openFtpClient(client, host, btn) {
  try {
    setBusy(btn, true);
    const data = await api('ftp_open', { body: { client, host } });
    toast(data.message || 'Opened');
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

function renderFtp(d) {
  if (!d) return;
  state.ftpRoot = d.stack_root || '';
  state.ftpUsers = d.users || [];
  const account = state.ftpUsers.find(u => u.enabled) || null;
  const status = $('#ftp-status');
  if (status) {
    status.innerHTML = `
      <div class="row-between">
        <div>
          <div class="ov-service-name">FTP ${pill(!!d.up)}</div>
          <p class="muted">${escapeHtml(d.bind === '0.0.0.0'
            ? `Plain FTP. Other computers use ftp://<LAN IP>:${d.port || 2121}/`
            : 'Plain FTP. Listening on this computer only.')}</p>
        </div>
        <div class="btn-row">
          ${d.installed === false ? '' : d.up
            ? `<button class="btn" type="button" data-ftp-action="restart">Restart</button>
               <button class="btn danger" type="button" data-ftp-action="stop">Stop</button>`
            : `<button class="btn primary" type="button" data-ftp-action="start">Start</button>`}
        </div>
      </div>
      ${ftpAccessHtml(d)}
      <dl class="ftp-facts">
        <dt>Port</dt><dd>${escapeHtml(d.port)}</dd>
        <dt>Passive</dt><dd>${escapeHtml(d.pasv_min)}–${escapeHtml(d.pasv_max)}</dd>
        <dt>Account</dt><dd>${account ? escapeHtml(account.name) : 'Add an account'}</dd>
        <dt>Password</dt><dd>${account && account.initial_password ? escapeHtml(account.initial_password) : 'Set on the account'}</dd>
      </dl>
      ${d.port_busy ? `<p class="ov-service-note">Port ${escapeHtml(d.port)} is in use by another program.</p>` : ''}
      ${d.config_ok === false ? '<p class="ov-service-note">ftp/ftp.json could not be read.</p>' : ''}
      ${d.installed === false ? '<p class="ov-service-note">php.exe was not found, so the FTP server cannot start.</p>' : ''}
    `;
  }

  const typingSettings = document.activeElement && document.activeElement.closest && document.activeElement.closest('#ftp-settings-form');
  if (!typingSettings && $('#ftp-bind')) {
    $('#ftp-bind').value = d.bind || '0.0.0.0';
    $('#ftp-port').value = d.port || 2121;
    syncFtpBindNote();
  }

  const usersEl = $('#ftp-users');
  if (usersEl) {
    if (!state.ftpUsers.length) {
      usersEl.innerHTML = '<p class="muted" style="padding:12px">No accounts yet.</p>';
    } else {
      usersEl.innerHTML = `<table class="sites-table">
        <thead><tr><th>User</th><th>Home</th><th>Access</th><th>Password</th><th></th></tr></thead>
        <tbody>
          ${state.ftpUsers.map(u => `<tr>
            <td class="mono">${escapeHtml(u.name)}</td>
            <td class="mono">${escapeHtml(u.home)}</td>
            <td>${u.enabled ? (u.write ? 'Upload' : 'Read only') : 'Disabled'}</td>
            <td class="mono">${u.initial_password ? escapeHtml(u.initial_password) : 'Saved'}</td>
            <td class="actions">
              <button class="btn" type="button" data-ftp-edit="${escapeAttr(u.name)}">Edit</button>
              <button class="btn danger" type="button" data-ftp-delete="${escapeAttr(u.name)}">Delete</button>
            </td>
          </tr>`).join('')}
        </tbody>
      </table>`;
    }
  }

  const logEl = $('#ftp-log');
  if (logEl) logEl.textContent = (d.log || '').trim() || 'No log entries yet.';
  const logPath = $('#ftp-log-path');
  if (logPath) logPath.textContent = d.log_path || '';
  if (!$('#ftp-user-home').value) {
    $('#ftp-user-home').value = state.ftpRoot ? state.ftpRoot + '/htdocs' : 'htdocs';
  }
}

async function loadFtp() {
  const data = await api('ftp_get');
  renderFtp(data.data);
}

async function runFtpAction(action, btn) {
  try {
    setBusy(btn, true);
    const data = await api(action);
    if (data.data && data.data.apache) {
      state.status = data.data;
      renderStatus();
    }
    toast(data.message || 'Done');
    await loadFtp();
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

function fillFtpUser(name) {
  const user = (state.ftpUsers || []).find(u => u.name === name);
  if (!user) return;
  $('#ftp-user-previous').value = user.name;
  $('#ftp-user-name').value = user.name;
  $('#ftp-user-pass').value = '';
  $('#ftp-user-home').value = user.home;
  $('#ftp-user-write').checked = !!user.write;
  $('#ftp-user-enabled').checked = !!user.enabled;
  $('#ftp-user-form-title').textContent = 'Edit account';
  syncFtpPassRequired();
  $('#ftp-user-name').focus();
}

async function saveFtpSettings(ev) {
  ev.preventDefault();
  const btn = $('#ftp-save-settings');
  try {
    setBusy(btn, true);
    const data = await api('ftp_save_settings', {
      body: { bind: $('#ftp-bind').value, port: Number($('#ftp-port').value) },
    });
    toast(data.message || 'Saved');
    renderFtp(data.data);
    await refreshStatus();
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

async function saveFtpUser(ev) {
  ev.preventDefault();
  const btn = $('#ftp-save-user');
  try {
    setBusy(btn, true);
    const data = await api('ftp_save_user', {
      body: {
        previous: $('#ftp-user-previous').value,
        name: $('#ftp-user-name').value.trim(),
        password: $('#ftp-user-pass').value,
        home: $('#ftp-user-home').value.trim(),
        write: $('#ftp-user-write').checked,
        enabled: $('#ftp-user-enabled').checked,
      },
    });
    toast(data.message || 'Saved');
    resetFtpUserForm();
    renderFtp(data.data);
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

async function deleteFtpUser(name, btn) {
  if (!confirm('Remove FTP account ' + name + '?')) return;
  try {
    setBusy(btn, true);
    const data = await api('ftp_delete_user', { body: { name } });
    toast(data.message || 'Removed');
    if ($('#ftp-user-previous').value === name) resetFtpUserForm();
    renderFtp(data.data);
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

async function restartSelectedPhp(btn) {
  const version = $('#phpini-version').value;
  try {
    setBusy(btn, true);
    const data = await api('phpini_restart', { body: { version }, query: { version } });
    if (data.data) {
      state.status = data.data;
      renderStatus();
    }
    toast(data.message || 'PHP restarted');
  } catch (e) {
    toast(e.message, true);
  } finally {
    setBusy(btn, false);
  }
}

function bind() {
  $$('.nav button').forEach(b => {
    if (!b.dataset.view) return;
    b.addEventListener('click', () => showView(b.dataset.view));
  });
  $$('[data-action]').forEach(b => b.addEventListener('click', () => runAction(b.dataset.action, b)));
  $('#config-select').addEventListener('change', loadConfig);
  $('#save-config').addEventListener('click', () => saveConfig($('#save-config')));
  $('#reload-config').addEventListener('click', loadConfig);
  $('#reload-logs').addEventListener('click', loadLogs);
  $('#save-default-php')?.addEventListener('click', () => saveDefaultPhp($('#save-default-php')));
  $('#site-form')?.addEventListener('submit', saveSite);
  $('#site-new-btn')?.addEventListener('click', () => { resetSiteForm(); $('#site-name')?.focus(); });
  $('#site-sync-hosts-btn')?.addEventListener('click', () => syncHosts($('#site-sync-hosts-btn')));
  $('#site-reset-btn')?.addEventListener('click', resetSiteForm);
  $('#site-suggest-path')?.addEventListener('click', suggestSitePath);
  $('#site-domain')?.addEventListener('blur', () => {
    if (!$('#site-root').value) suggestSitePath();
  });
  $$('#log-kind-tabs .log-kind').forEach(b => b.addEventListener('click', () => setLogKind(b.dataset.kind)));
  $('#php-load-releases')?.addEventListener('click', () => loadPhpReleases($('#php-load-releases')));
  $('#php-rescan')?.addEventListener('click', () => rescanPhp($('#php-rescan')));
  $('#db-save-btn')?.addEventListener('click', () => saveDbSettings($('#db-save-btn')));
  $('#db-test-btn')?.addEventListener('click', () => testDbSettings($('#db-test-btn')));
  $('#db-create-btn')?.addEventListener('click', () => createDatabase($('#db-create-btn')));
  $('#db-settings-form')?.addEventListener('submit', (e) => { e.preventDefault(); saveDbSettings($('#db-save-btn')); });
  $('#log-search').addEventListener('input', renderLogRows);
  $('#log-level-filter').addEventListener('change', renderLogRows);
  $('#log-category-filter').addEventListener('change', renderLogRows);
  $('#log-domain-filter').addEventListener('change', () => {
    state.logs.domain = $('#log-domain-filter').value;
    $$('#log-domains .log-domain-chip').forEach(c => c.classList.toggle('active', c.dataset.domain === state.logs.domain));
    renderLogRows();
  });
  $('#toggle-raw-logs').addEventListener('click', () => {
    state.logs.raw = !state.logs.raw;
    $('#log-view').classList.toggle('hidden', !state.logs.raw);
    $('#log-table-wrap').classList.toggle('hidden', state.logs.raw);
    $('#toggle-raw-logs').textContent = state.logs.raw ? 'Table' : 'Raw';
  });
  $('#run-sql').addEventListener('click', () => runQuery($('#run-sql')));
  const onRefresh = async (e) => {
    try {
      setBusy(e.currentTarget, true);
      await refreshStatus();
      await loadFirewall();
      toast('Status refreshed');
    } catch (err) {
      toast(err.message, true);
    } finally {
      setBusy(e.currentTarget, false);
    }
  };
  $('#refresh-status')?.addEventListener('click', onRefresh);
  $('#refresh-status-ov')?.addEventListener('click', onRefresh);
  $('#fw-allow')?.addEventListener('click', () => allowFirewall($('#fw-allow')));

  // PHP Config
  $$('[data-php-mode]').forEach(b => b.addEventListener('click', () => setPhpMode(b.dataset.phpMode)));
  $('#phpini-version')?.addEventListener('change', () => {
    state.phpini.version = $('#phpini-version').value;
    loadPhpIni();
  });
  $('#phpini-reload')?.addEventListener('click', loadPhpIni);
  $('#phpini-save')?.addEventListener('click', () => savePhpIni($('#phpini-save')));
  $('#phpini-restart')?.addEventListener('click', () => restartSelectedPhp($('#phpini-restart')));
  $('#view-ftp')?.addEventListener('click', (e) => {
    const actionBtn = e.target.closest('[data-ftp-action]');
    if (actionBtn) {
      const map = { start: 'start_ftp', stop: 'stop_ftp', restart: 'restart_ftp' };
      runFtpAction(map[actionBtn.dataset.ftpAction], actionBtn);
      return;
    }
    const copy = e.target.closest('[data-ftp-copy]');
    if (copy) {
      copyFtpUrl(copy.dataset.ftpCopy);
      return;
    }
    const open = e.target.closest('[data-ftp-open]');
    if (open) {
      openFtpClient(open.dataset.ftpOpen, open.dataset.ftpHost, open);
      return;
    }
    const edit = e.target.closest('[data-ftp-edit]');
    if (edit) {
      fillFtpUser(edit.dataset.ftpEdit);
      return;
    }
    const del = e.target.closest('[data-ftp-delete]');
    if (del) deleteFtpUser(del.dataset.ftpDelete, del);
  });
  $('#ftp-settings-form')?.addEventListener('submit', saveFtpSettings);
  $('#ftp-user-form')?.addEventListener('submit', saveFtpUser);
  $('#ftp-user-clear')?.addEventListener('click', resetFtpUserForm);
  $('#ftp-use-htdocs')?.addEventListener('click', () => {
    $('#ftp-user-home').value = state.ftpRoot ? state.ftpRoot + '/htdocs' : 'htdocs';
  });
  $('#ftp-bind')?.addEventListener('change', syncFtpBindNote);
  syncFtpPassRequired();
  $$('#phpini-levels .level-chip').forEach(btn => {
    btn.addEventListener('click', () => {
      const key = btn.dataset.level;
      state.phpini.levels[key] = !state.phpini.levels[key];
      const presets = state.phpini.data?.error_ui?.presets;
      state.phpini.preset = detectPresetFromLevels(presets);
      state.phpini.dirtyExpr = false;
      // Rebuild expression from chips via save path; preview in field
      const exprEl = $('#phpini-error_reporting');
      if (exprEl && presets) {
        const match = Object.values(presets).find(p => {
          const lv = p.levels || {};
          return ['error', 'warning', 'notice', 'deprecated', 'strict']
            .every(k => !!lv[k] === !!state.phpini.levels[k]);
        });
        if (match) exprEl.value = match.expression;
        else exprEl.value = '(custom levels)';
      }
      syncPhpLevelUi();
    });
  });
  $('#phpini-error_reporting')?.addEventListener('input', () => {
    state.phpini.dirtyExpr = true;
    state.phpini.preset = 'custom';
    syncPhpLevelUi();
  });
}

document.addEventListener('DOMContentLoaded', async () => {
  bind();
  showView('overview');
  try {
    await refreshStatus();
    await loadFirewall();
    if (state.status?.default_php) {
      state.phpini.version = state.status.default_php;
      if ($('#phpini-version')) $('#phpini-version').value = state.status.default_php;
    }
    loadPhpReleases().catch(() => {});
    if (state.status?.php_download?.active) pollPhpDownload();
  } catch (e) {
    toast(e.message, true);
  }
  setInterval(() => {
    if (state.view === 'overview') refreshStatus().catch(() => {});
  }, 8000);
});
