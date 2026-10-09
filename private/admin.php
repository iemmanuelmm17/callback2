<?php
/**
 * Callback2 — per-domain configuration UI (admin panel).
 *
 * Lives OUTSIDE the public web root; the public admin.php shim loads this file
 * and calls cb_admin_run(). The panel manages one JSON config file per domain
 * (see domains.php) — failover extension, prompt audio, excluded extensions,
 * lookup window, the callback-AA / call-decider / main-AA extensions and an
 * on/off toggle for the whole feature.
 *
 * Security model:
 *   - separate admin key (security.admin_key), constant-time compare, minimum
 *     16 characters — never the switch's API key;
 *   - optional IP allow-list (security.admin_allowed_ips) and require_https;
 *   - PHP session (strict mode, httponly + SameSite cookie, idle timeout);
 *   - CSRF token on every state-changing POST;
 *   - per-IP throttle after repeated failed sign-ins;
 *   - every sign-in / config change is written to the audit log;
 *   - when no admin key is configured the panel answers 404 (fail closed,
 *     the existence of the panel is not advertised).
 *
 * @package Callback2
 */

if (!defined('CALLBACK_APP')) {
    http_response_code(404);
    exit;
}

/* -------------------------------------------------------------------------
 * Small HTML helpers
 * ---------------------------------------------------------------------- */

/**
 * Escape a value for HTML output.
 */
function h($value): string
{
    $flags = ENT_QUOTES;
    if (defined('ENT_SUBSTITUTE')) {
        $flags |= ENT_SUBSTITUTE;
    }
    return htmlspecialchars((string) $value, $flags, 'UTF-8');
}

function cb_admin_send_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
}

function cb_admin_redirect(string $location): void
{
    if (!headers_sent()) {
        header('Location: ' . $location);
        header('Cache-Control: no-store');
    }
    exit;
}

function cb_admin_flash_html(): string
{
    $flash = cb_scalar($_GET['flash'] ?? '', 40);
    $messages = [
        'welcome'  => ['ok',  'Signed in.'],
        'bye'      => ['ok',  'Signed out.'],
        'saved'    => ['ok',  'Configuration saved. The domain is now driven by this page.'],
        'deleted'  => ['ok',  'Configuration deleted — the domain now uses the legacy AA options again.'],
        'badkey'   => ['err', 'Wrong admin key.'],
        'locked'   => ['err', 'Too many failed attempts — please try again later.'],
        'notfound' => ['err', 'Domain configuration not found.'],
    ];
    if (!isset($messages[$flash])) {
        return '';
    }
    [$class, $text] = $messages[$flash];
    return '<div class="alert ' . $class . '">' . h($text) . '</div>';
}

function cb_admin_page(string $title, string $body): string
{
    $nav = '';
    if (cb_admin_is_logged_in()) {
        $nav = '<nav>'
            . '<a href="admin.php">Domains</a>'
            . '<a href="admin.php?page=new">Add domain</a>'
            . '<form method="post" action="admin.php" class="inline">'
            . '<input type="hidden" name="action" value="logout">'
            . '<input type="hidden" name="csrf" value="' . h(cb_admin_csrf_token()) . '">'
            . '<button type="submit" class="link danger">Log out</button>'
            . '</form>'
            . '</nav>';
    }

    return '<!DOCTYPE html>'
        . '<html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' — Callback2 admin</title>'
        . '<style>'
        . 'body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#f4f5f7;color:#1c1e21}'
        . '.wrap{max-width:980px;margin:0 auto;padding:24px 16px 64px}'
        . 'header.top{background:#2b3a4a;color:#fff;padding:12px 16px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}'
        . 'header.top .brand{font-weight:700}'
        . 'header.top nav{display:flex;gap:14px;align-items:center;flex-wrap:wrap}'
        . 'header.top a{color:#cfe3ff;text-decoration:none}'
        . 'header.top a:hover{text-decoration:underline}'
        . 'header.top form.inline{margin:0}'
        . 'button.link{background:none;border:none;color:#ffb3b3;cursor:pointer;font:inherit;padding:0}'
        . 'button.link:hover{text-decoration:underline}'
        . '.card{background:#fff;border:1px solid #dcdfe4;border-radius:8px;padding:20px;margin-top:16px}'
        . 'h1{font-size:20px;margin:0 0 4px}'
        . 'h2{font-size:16px;margin:20px 0 8px}'
        . '.muted{color:#65676b;font-size:13px}'
        . 'table{border-collapse:collapse;width:100%;margin-top:12px;font-size:14px}'
        . 'th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #e4e6eb;vertical-align:top}'
        . 'th{background:#f0f2f5;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#65676b}'
        . '.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600}'
        . '.badge.on{background:#d7f0dc;color:#1c6e2d}'
        . '.badge.off{background:#f0d7d7;color:#8a1f1f}'
        . '.alert{padding:10px 14px;border-radius:6px;margin:12px 0;font-size:14px}'
        . '.alert.ok{background:#e7f4e9;border:1px solid #b7dfc0;color:#1c6e2d}'
        . '.alert.err{background:#fdeaea;border:1px solid #f0b8b8;color:#8a1f1f}'
        . '.alert.warn{background:#fff6dd;border:1px solid #ecd58a;color:#7a5b00}'
        . '.field{display:block;margin:14px 0}'
        . '.field .label{display:block;font-weight:600;font-size:13px;margin-bottom:4px}'
        . '.field .help{display:block;color:#65676b;font-size:12px;margin-top:4px}'
        . '.field input[type=text],.field input[type=password],.field input[type=number]{width:100%;max-width:420px;padding:8px 10px;border:1px solid #ccd0d5;border-radius:6px;font:inherit;box-sizing:border-box}'
        . '.field.checkbox{display:flex;align-items:center;gap:8px}'
        . '.field.checkbox .label{margin:0}'
        . '.actions{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap}'
        . '.button{display:inline-block;padding:8px 16px;border-radius:6px;border:1px solid #ccd0d5;background:#fff;color:#1c1e21;text-decoration:none;font:inherit;cursor:pointer}'
        . '.button.primary{background:#2b6cb0;border-color:#2b6cb0;color:#fff}'
        . '.button.danger{background:#fff;border-color:#d98a8a;color:#8a1f1f}'
        . '.button:hover{filter:brightness(.97)}'
        . 'code{background:#eef0f3;padding:1px 5px;border-radius:4px;font-size:12px}'
        . '</style></head><body>'
        . '<header class="top"><span class="brand">Callback2 admin</span>' . $nav . '</header>'
        . '<div class="wrap">' . cb_admin_flash_html() . $body . '</div>'
        . '</body></html>';
}

/* -------------------------------------------------------------------------
 * Authentication helpers (pure logic — unit tested without a web server)
 * ---------------------------------------------------------------------- */

/**
 * Constant-time admin key check. Rejects empty/short configured keys entirely.
 */
function cb_admin_key_valid(string $provided, array $config): bool
{
    $expected = (string) ($config['security']['admin_key'] ?? '');
    if (strlen($expected) < 16) {
        return false;
    }
    if ($provided === '' || strlen($provided) > 512) {
        return false;
    }
    return hash_equals($expected, $provided);
}

/**
 * Authorise access to the admin interface itself (method, TLS, IP allow-list,
 * admin key configured). The key itself is checked at sign-in.
 *
 * @return array{ok:bool,status:int,reason:string}
 */
function cb_admin_authorize(array $config): array
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST'], true)) {
        return ['ok' => false, 'status' => 405, 'reason' => 'method ' . $method . ' not allowed'];
    }

    if (!empty(cb_cfg('security.require_https', false))) {
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        if (!$isHttps) {
            return ['ok' => false, 'status' => 403, 'reason' => 'plain HTTP request rejected'];
        }
    }

    if (!cb_ip_allowed(cb_client_ip(), (array) cb_cfg('security.admin_allowed_ips', []))) {
        return ['ok' => false, 'status' => 403, 'reason' => 'IP ' . cb_client_ip() . ' not allowed'];
    }

    $expected = (string) cb_cfg('security.admin_key', '');
    if (strlen($expected) < 16) {
        // Fail closed and stay quiet: do not advertise that the panel exists.
        return ['ok' => false, 'status' => 404, 'reason' => 'admin interface is not enabled'];
    }

    return ['ok' => true, 'status' => 200, 'reason' => 'ok'];
}

/* -------------------------------------------------------------------------
 * Sign-in throttle (per IP, file based)
 * ---------------------------------------------------------------------- */

function cb_admin_throttle_file(string $ip): string
{
    return cb_cache_file('admin-throttle', $ip);
}

/**
 * True while the IP is inside its lockout window.
 */
function cb_admin_throttled(string $ip): bool
{
    if ($ip === '') {
        return false; // cannot key a throttle without an IP; the key check still applies
    }
    $file = cb_admin_throttle_file($ip);
    $data = cb_cache_read($file);
    if (!is_array($data)) {
        return false;
    }
    $lockedUntil = (int) ($data['locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        return true;
    }
    if ($lockedUntil > 0) {
        @unlink($file); // lockout expired — start fresh
    }
    return false;
}

/**
 * Record one failed sign-in; locks the IP out after admin_max_failures.
 */
function cb_admin_throttle_failure(string $ip): void
{
    if ($ip === '') {
        return;
    }
    $file = cb_admin_throttle_file($ip);
    $data = cb_cache_read($file);
    $failures = (is_array($data) ? (int) ($data['failures'] ?? 0) : 0) + 1;
    $max = max(1, (int) cb_cfg('security.admin_max_failures', 5));
    $lockout = max(60, (int) cb_cfg('security.admin_lockout_seconds', 900));

    $entry = ['failures' => $failures, 'last_failure' => time(), 'locked_until' => 0];
    if ($failures >= $max) {
        $entry['locked_until'] = time() + $lockout;
        cb_log('admin sign-in throttled for IP ' . $ip . ' (' . $failures . ' failures)', 'warning');
    }
    cb_cache_write($file, $entry);
}

function cb_admin_throttle_clear(string $ip): void
{
    if ($ip === '') {
        return;
    }
    $file = cb_admin_throttle_file($ip);
    if (is_file($file)) {
        @unlink($file);
    }
}

/* -------------------------------------------------------------------------
 * Session + CSRF
 * ---------------------------------------------------------------------- */

/**
 * CSRF token for the current session (lazily created).
 */
function cb_admin_csrf_token(): string
{
    if (empty($_SESSION['cb_admin_csrf']) || !is_string($_SESSION['cb_admin_csrf'])) {
        $_SESSION['cb_admin_csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['cb_admin_csrf'];
}

function cb_admin_csrf_valid(string $token): bool
{
    $expected = $_SESSION['cb_admin_csrf'] ?? '';
    if (!is_string($expected) || $expected === '' || $token === '' || strlen($token) > 128) {
        return false;
    }
    return hash_equals($expected, $token);
}

function cb_admin_is_logged_in(): bool
{
    return !empty($_SESSION['cb_admin_auth']);
}

/**
 * Start the admin session with hardened cookie settings and enforce the idle
 * timeout (security.admin_session_seconds).
 */
function cb_admin_session_start(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    if ($https) {
        @ini_set('session.cookie_secure', '1');
    }
    session_name('CBADMINSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
    if (!@session_start()) {
        cb_log('admin session could not be started', 'error');
        http_response_code(500);
        exit('Internal Server Error');
    }

    // Idle timeout.
    $ttl = max(60, (int) cb_cfg('security.admin_session_seconds', 1800));
    if (!empty($_SESSION['cb_admin_auth'])) {
        $seen = (int) ($_SESSION['cb_admin_seen'] ?? 0);
        if ($seen > 0 && (time() - $seen) > $ttl) {
            cb_domain_audit('session-expired', '', 'admin', 'ip=' . cb_client_ip());
            $_SESSION = [];
            @session_regenerate_id(true);
        }
    }
    $_SESSION['cb_admin_seen'] = time();
}

/**
 * Verify the admin key and open an authenticated session.
 */
function cb_admin_login(string $key, array $config): bool
{
    if (!cb_admin_key_valid($key, $config)) {
        return false;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }
    $_SESSION['cb_admin_auth'] = true;
    $_SESSION['cb_admin_seen'] = time();
    $_SESSION['cb_admin_csrf'] = bin2hex(random_bytes(16));
    cb_admin_throttle_clear(cb_client_ip());
    cb_domain_audit('login', '', 'admin', 'ip=' . cb_client_ip());
    return true;
}

function cb_admin_logout(): void
{
    cb_domain_audit('logout', '', 'admin', 'ip=' . cb_client_ip());
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_destroy();
    }
}

/* -------------------------------------------------------------------------
 * Pages
 * ---------------------------------------------------------------------- */

function cb_admin_render_login(): void
{
    cb_admin_send_headers();
    $body = '<div class="card" style="max-width:420px;margin:40px auto">'
        . '<h1>Sign in</h1>'
        . '<p class="muted">Per-domain callback configuration.</p>'
        . '<form method="post" action="admin.php">'
        . '<input type="hidden" name="action" value="login">'
        . '<input type="hidden" name="csrf" value="' . h(cb_admin_csrf_token()) . '">'
        . '<label class="field"><span class="label">Admin key</span>'
        . '<input type="password" name="admin_key" autofocus required autocomplete="current-password">'
        . '</label>'
        . '<div class="actions"><button type="submit" class="button primary">Sign in</button></div>'
        . '</form></div>';
    echo cb_admin_page('Sign in', $body);
    exit;
}

function cb_admin_render_list(): void
{
    cb_admin_send_headers();
    $domains = cb_domain_config_list();

    $rows = '';
    foreach ($domains as $domain => $cfg) {
        $enabled = !empty($cfg['enabled']);
        $editUrl = 'admin.php?page=edit&amp;domain=' . h(rawurlencode($domain));
        $delUrl  = 'admin.php?page=confirm-delete&amp;domain=' . h(rawurlencode($domain));
        $rows .= '<tr>'
            . '<td><a href="' . $editUrl . '">' . h($domain) . '</a></td>'
            . '<td>' . ($enabled ? '<span class="badge on">enabled</span>' : '<span class="badge off">disabled</span>') . '</td>'
            . '<td>' . ($cfg['failover_extension'] !== '' ? h($cfg['failover_extension']) : '—') . '</td>'
            . '<td>' . ($cfg['lookup_minutes'] > 0 ? ((int) $cfg['lookup_minutes']) . ' min' : 'default') . '</td>'
            . '<td>' . ($cfg['excluded_extensions'] !== '' ? h($cfg['excluded_extensions']) : '—') . '</td>'
            . '<td class="muted">' . h($cfg['updated_at'])
            . ($cfg['updated_by'] !== '' ? ' by ' . h($cfg['updated_by']) : '') . '</td>'
            . '<td><a class="button" href="' . $editUrl . '">Edit</a> '
            . '<a class="button danger" href="' . $delUrl . '">Delete</a></td>'
            . '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="7" class="muted">No domains configured yet. '
            . 'Domains without a config keep using the legacy AA options (0-5).</td></tr>';
    }

    $body = '<h1>Domains</h1>'
        . '<p class="muted">One configuration per domain. A configured domain is fully driven by its '
        . 'config page; the legacy AA options only apply to domains without one.</p>'
        . '<div class="card"><table>'
        . '<tr><th>Domain</th><th>Feature</th><th>Failover ext</th><th>Lookup</th><th>Excluded</th><th>Updated</th><th></th></tr>'
        . $rows
        . '</table>'
        . '<div class="actions"><a class="button primary" href="admin.php?page=new">Add domain</a></div>'
        . '</div>';
    echo cb_admin_page('Domains', $body);
    exit;
}

function cb_admin_render_form(string $domain, ?array $values = null, array $errors = [], array $warnings = []): void
{
    cb_admin_send_headers();

    $editing = $domain !== '';
    if ($values === null) {
        $existing = $editing ? cb_domain_config_load($domain) : null;
        $values = $existing ?? [
            'domain'                   => $domain,
            'enabled'                  => true,
            'failover_extension'       => '',
            'callback_aa_extension'    => '',
            'call_decider_extension'   => '',
            'auto_attendant_extension' => '',
            'lookup_minutes'           => '',
            'message_url'              => '',
            'excluded_extensions'      => '',
        ];
    }

    $boxes = '';
    foreach ($errors as $error) {
        $boxes .= '<div class="alert err">' . h($error) . '</div>';
    }
    foreach ($warnings as $warning) {
        $boxes .= '<div class="alert warn">' . h($warning) . '</div>';
    }
    if ($editing && cb_domain_config_load($domain) === null) {
        $boxes .= '<div class="alert warn">No configuration saved for this domain yet — the legacy '
            . 'AA options (0-5) currently drive it. Saving here makes this page the source of truth '
            . 'and stops the AA options from being read for this domain.</div>';
    }

    $fieldText = function (string $name, string $label, string $help) use ($values): string {
        return '<label class="field"><span class="label">' . h($label) . '</span>'
            . '<input type="text" name="' . h($name) . '" value="' . h($values[$name] ?? '') . '">'
            . '<span class="help">' . h($help) . '</span></label>';
    };

    $domainField = $editing
        ? '<label class="field"><span class="label">Domain</span>'
            . '<input type="text" value="' . h($domain) . '" disabled>'
            . '<input type="hidden" name="domain" value="' . h($domain) . '">'
            . '<span class="help">The NetSapiens domain (AccountDomain) this configuration applies to.</span></label>'
        : '<label class="field"><span class="label">Domain</span>'
            . '<input type="text" name="domain" value="' . h($values['domain'] ?? '') . '" placeholder="11123.widetelco.com" required>'
            . '<span class="help">The NetSapiens domain (AccountDomain) this configuration applies to.</span></label>';

    $checked = !empty($values['enabled']) ? ' checked' : '';

    $body = '<h1>' . ($editing ? 'Edit ' . h($domain) : 'Add domain') . '</h1>'
        . $boxes
        . '<div class="card"><form method="post" action="admin.php">'
        . '<input type="hidden" name="action" value="save">'
        . '<input type="hidden" name="csrf" value="' . h(cb_admin_csrf_token()) . '">'
        . $domainField
        . '<label class="field checkbox">'
        . '<input type="checkbox" name="enabled" value="1"' . $checked . '>'
        . '<span class="label">Callback feature enabled for this domain</span>'
        . '</label>'
        . '<h2>Routing</h2>'
        . $fieldText('failover_extension', 'Failover extension',
            'Where calls go when the callback feature cannot handle them (API outage, or nothing to offer) '
            . 'and when the caller presses 2 / times out. Use a downstream target — your main AA or '
            . 'after-hours — NOT the callback AA and NOT the call decider that routes into the callback feature.')
        . $fieldText('callback_aa_extension', 'Callback AA extension',
            'The "Callback AA" extension (legacy AA option 0). Calls from these extensions are never offered a callback.')
        . $fieldText('call_decider_extension', 'Call decider extension',
            'The "CallDecider" extension (legacy AA option 1) — the extension that routes between the '
            . 'callback feature, after-hours and your main AA.')
        . $fieldText('auto_attendant_extension', 'Main auto attendant extension',
            'The customer\'s regular main auto attendant (legacy AA option 2). Used as the hand-off target '
            . 'when the feature is disabled and no failover extension is set.')
        . '<h2>Callback behaviour</h2>'
        . '<label class="field"><span class="label">Lookup window (minutes)</span>'
        . '<input type="text" name="lookup_minutes" value="' . h($values['lookup_minutes'] ?? '') . '" placeholder="30" inputmode="numeric">'
        . '<span class="help">How far back to search the CDRs for a recent conversation (1-1440). '
        . 'Leave empty for the global default (30 minutes).</span></label>'
        . $fieldText('message_url', 'Prompt audio',
            'Audio played with the "Press 1 / Press 2" prompt: <code>default</code> for text-to-speech, '
            . 'an http(s) URL, or a prompt file name (legacy AA option 4).')
        . $fieldText('excluded_extensions', 'Excluded extensions',
            'Comma separated extensions that must never be offered a callback (legacy AA option 5), e.g. <code>9001, 9002</code>.')
        . '<div class="actions">'
        . '<button type="submit" class="button primary">Save</button>'
        . '<a class="button" href="admin.php">Cancel</a>'
        . '</div>'
        . '</form></div>';

    echo cb_admin_page($editing ? 'Edit domain' : 'Add domain', $body);
    exit;
}

function cb_admin_render_confirm_delete(string $domain): void
{
    cb_admin_send_headers();
    if ($domain === '' || cb_domain_config_load($domain) === null) {
        cb_admin_redirect('admin.php?flash=notfound');
    }
    $body = '<div class="card" style="max-width:560px;margin:40px auto">'
        . '<h1>Delete configuration?</h1>'
        . '<p>Delete the configuration for <strong>' . h($domain) . '</strong>? '
        . 'The domain will fall back to the legacy AA options (0-5) and global defaults.</p>'
        . '<form method="post" action="admin.php">'
        . '<input type="hidden" name="action" value="delete">'
        . '<input type="hidden" name="domain" value="' . h($domain) . '">'
        . '<input type="hidden" name="csrf" value="' . h(cb_admin_csrf_token()) . '">'
        . '<div class="actions">'
        . '<button type="submit" class="button danger">Delete</button>'
        . '<a class="button" href="admin.php">Cancel</a>'
        . '</div></form></div>';
    echo cb_admin_page('Delete domain', $body);
    exit;
}

/* -------------------------------------------------------------------------
 * POST handlers
 * ---------------------------------------------------------------------- */

function cb_admin_handle_login(array $config): void
{
    $csrf = cb_scalar($_POST['csrf'] ?? '', 64);
    if (!cb_admin_csrf_valid($csrf)) {
        cb_log('admin login with invalid CSRF token from ' . cb_client_ip(), 'warning');
        cb_admin_send_headers();
        http_response_code(403);
        echo cb_admin_page('Forbidden', '<p>Invalid session token — please try again.</p>');
        exit;
    }
    $key = is_string($_POST['admin_key'] ?? null) ? (string) $_POST['admin_key'] : '';
    if (cb_admin_login($key, $config)) {
        cb_admin_redirect('admin.php?flash=welcome');
    }
    cb_admin_throttle_failure(cb_client_ip());
    cb_domain_audit('login-failed', '', 'admin', 'ip=' . cb_client_ip());
    cb_admin_redirect('admin.php?page=login&flash=badkey');
}

function cb_admin_handle_save(array $config): void
{
    $domain = cb_domain(cb_scalar($_POST['domain'] ?? '', 253));
    if ($domain === '') {
        cb_admin_render_form('', $_POST, ['A valid domain is required (e.g. 11123.widetelco.com).']);
    }
    $validation = cb_domain_config_validate($domain, $_POST);
    if (!$validation['ok']) {
        cb_admin_render_form($domain, $_POST, $validation['errors'], $validation['warnings']);
    }
    if (!cb_domain_config_save($domain, $validation['config'], 'admin')) {
        cb_log('admin: failed to write config for ' . $domain, 'error');
        cb_admin_render_form($domain, $_POST, [
            'Could not write the configuration file — check that the domains directory is writable by the web server.',
        ], $validation['warnings']);
    }
    cb_admin_redirect('admin.php?page=edit&domain=' . rawurlencode($domain) . '&flash=saved');
}

function cb_admin_handle_delete(): void
{
    $domain = cb_domain(cb_scalar($_POST['domain'] ?? '', 253));
    if ($domain === '' || cb_domain_config_load($domain) === null) {
        cb_admin_redirect('admin.php?flash=notfound');
    }
    cb_domain_config_delete($domain, 'admin');
    cb_admin_redirect('admin.php?flash=deleted');
}

/* -------------------------------------------------------------------------
 * Runner — called by the public admin.php shim. Never returns.
 * ---------------------------------------------------------------------- */

function cb_admin_run(array $config): void
{
    // Authorise the interface itself (method / TLS / IP / key configured).
    $auth = cb_admin_authorize($config);
    if (!$auth['ok']) {
        cb_log('admin interface rejected (' . $auth['status'] . '): ' . $auth['reason']
            . ' from ' . (cb_client_ip() !== '' ? cb_client_ip() : 'unknown'), 'warning');
        http_response_code($auth['status']);
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo $auth['status'] === 404 ? 'Not Found' : 'Forbidden';
        exit;
    }

    cb_admin_session_start($config);

    if (cb_admin_throttled(cb_client_ip())) {
        cb_log('admin request while throttled from ' . cb_client_ip(), 'warning');
        http_response_code(429);
        cb_admin_send_headers();
        echo cb_admin_page('Too many attempts',
            '<p>Too many failed sign-in attempts. Please try again later.</p>');
        exit;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $page   = cb_scalar($_GET['page'] ?? $_POST['page'] ?? '', 24);
    $action = cb_scalar($_POST['action'] ?? '', 24);

    // --- POST actions --------------------------------------------------------
    if ($method === 'POST') {
        if ($action === 'login') {
            cb_admin_handle_login($config);
        }
        if (!cb_admin_is_logged_in()) {
            cb_admin_redirect('admin.php?page=login');
        }
        if (!cb_admin_csrf_valid(cb_scalar($_POST['csrf'] ?? '', 64))) {
            cb_log('admin POST with invalid CSRF token from ' . cb_client_ip(), 'warning');
            http_response_code(403);
            cb_admin_send_headers();
            echo cb_admin_page('Forbidden', '<p>Invalid session token — please sign in again.</p>');
            exit;
        }
        if ($action === 'save') {
            cb_admin_handle_save($config);
        }
        if ($action === 'delete') {
            cb_admin_handle_delete();
        }
        if ($action === 'logout') {
            cb_admin_logout();
            cb_admin_redirect('admin.php?page=login&flash=bye');
        }
        cb_log('admin: unknown POST action "' . $action . '"', 'warning');
        http_response_code(404);
        cb_admin_send_headers();
        echo cb_admin_page('Not Found', '<p>Unknown action.</p>');
        exit;
    }

    // --- GET pages -----------------------------------------------------------
    if (!cb_admin_is_logged_in()) {
        cb_admin_render_login();
    }

    switch ($page) {
        case '':
        case 'list':
            cb_admin_render_list();
            break;
        case 'new':
            cb_admin_render_form('');
            break;
        case 'edit':
            $domain = cb_domain(cb_scalar($_GET['domain'] ?? '', 253));
            if ($domain === '') {
                http_response_code(404);
                cb_admin_send_headers();
                echo cb_admin_page('Not Found', '<p>Unknown domain.</p>');
                exit;
            }
            cb_admin_render_form($domain);
            break;
        case 'confirm-delete':
            cb_admin_render_confirm_delete(cb_domain(cb_scalar($_GET['domain'] ?? '', 253)));
            break;
        default:
            http_response_code(404);
            cb_admin_send_headers();
            echo cb_admin_page('Not Found', '<p>Unknown page.</p>');
            break;
    }
    exit;
}
