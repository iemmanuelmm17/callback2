<?php
/**
 * Callback2 — per-domain configuration store.
 *
 * The admin configuration UI (admin.php) stores one JSON file per domain in a
 * private directory (domains.dir, default: <private dir>/domains). A domain
 * with a config file is fully driven by it — the legacy AA options 0-5 are
 * only consulted for domains WITHOUT a config file.
 *
 * Precedence per setting:  domain config  >  AA options (legacy)  >  global
 * defaults (config.php). The only exception is the "enabled" toggle, which
 * only the domain config can switch off.
 *
 * This file lives OUTSIDE the public web root and is only loaded through
 * bootstrap.php after CALLBACK_APP is defined.
 *
 * @package Callback2
 */

if (!defined('CALLBACK_APP')) {
    http_response_code(404);
    exit;
}

/* -------------------------------------------------------------------------
 * Storage
 * ---------------------------------------------------------------------- */

/**
 * Directory holding the per-domain JSON configs. Created (0700) on demand.
 * A deny-all .htaccess and an empty index.html are dropped inside as a safety
 * net for the case where the directory is ever placed inside a docroot.
 */
function cb_domains_dir(): string
{
    $dir = cb_scalar(cb_cfg('domains.dir', ''), 300);
    if ($dir === '') {
        $dir = __DIR__ . '/domains';
    }
    $dir = rtrim($dir, '/\\');

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "# Created by callback2 — never serve this directory.\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }
        $index = $dir . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
    }
    return $dir;
}

/**
 * Path of one domain's config file.
 */
function cb_domain_config_path(string $domain): string
{
    return cb_domains_dir() . '/' . $domain . '.json';
}

/**
 * Normalise a raw (decoded) config array into the canonical stored shape.
 * Unknown keys are dropped, every value is re-validated. The domain must
 * already be a clean hostname.
 */
function cb_domain_config_normalize(array $raw): array
{
    $message = cb_scalar($raw['message_url'] ?? '', 300);

    return [
        'domain'                   => cb_domain($raw['domain'] ?? ''),
        'enabled'                  => !isset($raw['enabled']) ? true : (bool) $raw['enabled'],
        'failover_extension'       => cb_sip_user($raw['failover_extension'] ?? ''),
        'callback_aa_extension'    => cb_sip_user($raw['callback_aa_extension'] ?? ''),
        'call_decider_extension'   => cb_sip_user($raw['call_decider_extension'] ?? ''),
        'auto_attendant_extension' => cb_sip_user($raw['auto_attendant_extension'] ?? ''),
        'lookup_minutes'           => max(0, min(1440, (int) ($raw['lookup_minutes'] ?? 0))),
        'message_url'              => $message === '' ? '' : cb_audio_source($message),
        'excluded_extensions'      => implode(',', cb_clean_list($raw['excluded_extensions'] ?? '')),
        'updated_at'               => cb_clean_text($raw['updated_at'] ?? '', 40),
        'updated_by'               => cb_clean_text($raw['updated_by'] ?? '', 32),
    ];
}

/**
 * Load one domain's configuration. Returns null when the domain has no config
 * file (legacy AA-options behaviour applies) or the file is unreadable.
 */
function cb_domain_config_load(string $domain): ?array
{
    $domain = cb_domain($domain);
    if ($domain === '') {
        return null;
    }
    $path = cb_domain_config_path($domain);
    if (!is_file($path)) {
        return null;
    }
    $raw = cb_cache_read($path);
    if (!is_array($raw)) {
        cb_log('domain config unreadable or corrupt: ' . $path, 'warning');
        return null;
    }
    if (($raw['domain'] ?? '') !== $domain) {
        cb_log('domain config has a mismatched domain field: ' . $path, 'warning');
        return null;
    }
    $config = cb_domain_config_normalize($raw);
    if ($config['domain'] === '') {
        cb_log('domain config has an invalid domain field: ' . $path, 'warning');
        return null;
    }
    return $config;
}

/**
 * All stored domain configs, keyed by domain, sorted by domain name.
 *
 * @return array<string,array>
 */
function cb_domain_config_list(): array
{
    $out = [];
    foreach (glob(cb_domains_dir() . '/*.json') ?: [] as $file) {
        $raw = cb_cache_read($file);
        if (!is_array($raw)) {
            continue;
        }
        $config = cb_domain_config_normalize($raw);
        if ($config['domain'] === '') {
            continue;
        }
        $out[$config['domain']] = $config;
    }
    ksort($out);
    return $out;
}

/* -------------------------------------------------------------------------
 * Validation (used by the admin UI before anything is written)
 * ---------------------------------------------------------------------- */

/**
 * Validate raw admin-form input for one domain.
 *
 * @return array{ok:bool,errors:string[],warnings:string[],config:array}
 *         "ok" is false when any error is present (nothing may be saved).
 *         Warnings (e.g. routing loops) are shown but do not block saving.
 */
function cb_domain_config_validate(string $domain, array $input): array
{
    $errors = [];
    $warnings = [];

    $domain = cb_domain($domain);
    if ($domain === '') {
        $errors[] = 'A valid domain is required (e.g. 11123.widetelco.com).';
    }

    $config = [
        'domain'                   => $domain,
        'enabled'                  => !empty($input['enabled']),
        'failover_extension'       => '',
        'callback_aa_extension'    => '',
        'call_decider_extension'   => '',
        'auto_attendant_extension' => '',
        'lookup_minutes'           => 0,
        'message_url'              => '',
        'excluded_extensions'      => '',
    ];

    // Routing targets must be usable as SIP user parts (no spaces etc.).
    foreach ([
        'failover_extension'       => 'Failover extension',
        'callback_aa_extension'    => 'Callback AA extension',
        'call_decider_extension'   => 'Call decider extension',
        'auto_attendant_extension' => 'Main auto attendant extension',
    ] as $key => $label) {
        $raw = cb_scalar($input[$key] ?? '', 80);
        if ($raw === '') {
            continue;
        }
        $clean = cb_sip_user($raw);
        if ($clean === '') {
            $errors[] = $label . ': "' . cb_clean_text($raw, 64) . '" is not a valid extension.';
        } else {
            $config[$key] = $clean;
        }
    }

    // Lookup window (minutes). Empty = fall back to the global default.
    $lookupRaw = cb_scalar($input['lookup_minutes'] ?? '', 10);
    if ($lookupRaw !== '') {
        $lookup = cb_digits($lookupRaw, 4);
        if ($lookup === '') {
            $errors[] = 'Lookup window must be a number of minutes (1-1440).';
        } elseif ((int) $lookup < 1 || (int) $lookup > 1440) {
            $errors[] = 'Lookup window must be between 1 and 1440 minutes.';
        } else {
            $config['lookup_minutes'] = (int) $lookup;
        }
    }

    // Prompt audio: "default", an http(s) URL or a plain prompt file name.
    $messageRaw = cb_scalar($input['message_url'] ?? '', 300);
    if ($messageRaw !== '') {
        if (strcasecmp($messageRaw, 'default') === 0) {
            $config['message_url'] = 'default';
        } elseif (preg_match('~^https?://[^\s<>"\'\x00-\x1F]+$~i', $messageRaw)) {
            $config['message_url'] = $messageRaw;
        } elseif (preg_match('~^[A-Za-z0-9._\-]{1,200}$~', $messageRaw)) {
            $config['message_url'] = $messageRaw;
        } else {
            $errors[] = 'Prompt audio must be "default", an http(s) URL or a prompt file name.';
        }
    }

    // Excluded extensions (comma separated).
    $excludedRaw = cb_scalar($input['excluded_extensions'] ?? '', 2048);
    if ($excludedRaw !== '') {
        $items = [];
        foreach (explode(',', $excludedRaw) as $item) {
            $item = cb_sip_user(cb_clean_text($item, 64));
            if ($item === '') {
                $errors[] = 'Excluded extensions: "' . cb_clean_text($item, 64) . '" is not a valid extension.';
            } else {
                $items[$item] = true;
            }
        }
        $config['excluded_extensions'] = implode(',', array_keys($items));
    }

    // Loop safety: the failover target is where calls go when the callback
    // feature cannot handle them. Routing that back into the callback feature
    // (or into the call decider that routes into it) loops the call.
    if ($config['failover_extension'] !== '') {
        if ($config['callback_aa_extension'] !== ''
            && $config['failover_extension'] === $config['callback_aa_extension']) {
            $errors[] = 'The failover extension must not be the callback AA — '
                . 'the call would loop straight back into the callback feature.';
        }
        if ($config['call_decider_extension'] !== ''
            && $config['failover_extension'] === $config['call_decider_extension']) {
            $warnings[] = 'The failover extension is the call decider. If the call decider '
                . 'routes back into the callback feature, calls can loop while the API is '
                . 'unavailable — prefer a downstream target (main AA / after-hours).';
        }
    }

    return [
        'ok'       => empty($errors) && $domain !== '',
        'errors'   => $errors,
        'warnings' => $warnings,
        'config'   => $config,
    ];
}

/* -------------------------------------------------------------------------
 * Write / delete / audit
 * ---------------------------------------------------------------------- */

/**
 * Append one line to the admin audit log (JSON per line, 0600).
 */
function cb_domain_audit(string $action, string $domain, string $admin, string $detail): void
{
    if (!cb_cfg('domains.audit_log', true)) {
        return;
    }
    $line = json_encode([
        'ts'     => gmdate('c'),
        'action' => cb_clean_text($action, 32),
        'domain' => cb_domain($domain),
        'admin'  => cb_clean_text($admin, 32),
        'ip'     => cb_client_ip(),
        'detail' => cb_clean_text($detail, 200),
    ]);
    if ($line === false) {
        return;
    }
    $file = cb_domains_dir() . '/audit.log';
    if (@file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false) {
        @chmod($file, 0600);
    }
}

/**
 * Save one domain's configuration (atomic write, 0600) + audit entry.
 */
function cb_domain_config_save(string $domain, array $config, string $admin): bool
{
    $domain = cb_domain($domain);
    if ($domain === '') {
        return false;
    }
    $path = cb_domain_config_path($domain);
    $existed = is_file($path);

    $config['domain']     = $domain;
    $config['updated_at'] = gmdate('c');
    $config['updated_by'] = cb_clean_text($admin, 32) !== '' ? cb_clean_text($admin, 32) : 'admin';

    if (!cb_atomic_write_json($path, $config, 0600)) {
        cb_log('failed to write domain config ' . $path, 'error');
        return false;
    }

    cb_domain_audit('save', $domain, $admin, ($existed ? 'updated' : 'created')
        . ' enabled=' . (!empty($config['enabled']) ? '1' : '0')
        . ' failover=' . (string) ($config['failover_extension'] ?? ''));
    return true;
}

/**
 * Delete one domain's configuration. The domain then falls back to the legacy
 * AA-options behaviour. Returns false when there was nothing to delete.
 */
function cb_domain_config_delete(string $domain, string $admin): bool
{
    $domain = cb_domain($domain);
    if ($domain === '') {
        return false;
    }
    $path = cb_domain_config_path($domain);
    if (!is_file($path)) {
        return false;
    }
    if (!@unlink($path)) {
        cb_log('failed to delete domain config ' . $path, 'error');
        return false;
    }
    cb_domain_audit('delete', $domain, $admin, 'config removed; legacy AA options apply again');
    return true;
}

/* -------------------------------------------------------------------------
 * Settings resolution (domain config > AA options > defaults)
 * ---------------------------------------------------------------------- */

/**
 * Merge the three configuration layers into one effective settings array.
 *
 * @param array|null   $domainCfg  Normalised domain config (null = not configured).
 * @param array|null   $aaOptions  Legacy AA options from cb_fetch_aa_options()
 *                                (null when a domain config exists — the AA
 *                                options are not even fetched in that case).
 * @param array        $defaults   Global defaults from config.php.
 *
 * @return array{enabled:bool,failover_extension:string,callback_aa:string,
 *               call_decider:string,auto_attendant:string,lookup_minutes:int,
 *               message_url:string,excluded:array,sources:array,has_domain_config:bool}
 */
function cb_resolve_settings(?array $domainCfg, ?array $aaOptions, array $defaults): array
{
    $settings = [
        'enabled'            => true,
        'failover_extension' => '',
        'callback_aa'        => '',
        'call_decider'       => '',
        'auto_attendant'     => '',
        'lookup_minutes'     => max(1, (int) ($defaults['lookup_minutes'] ?? 30)),
        'message_url'        => 'default',
        'excluded'           => [],
        'sources'            => [],
        'has_domain_config'  => is_array($domainCfg),
    ];

    // String fields: domain config -> AA options -> defaults.
    $pickString = function (string $domainKey, string $aaKey, string $defaultKey) use ($domainCfg, $aaOptions, $defaults) {
        if (is_array($domainCfg) && isset($domainCfg[$domainKey]) && is_string($domainCfg[$domainKey]) && $domainCfg[$domainKey] !== '') {
            return [$domainCfg[$domainKey], 'domain-config'];
        }
        if (is_array($aaOptions) && isset($aaOptions[$aaKey]) && $aaOptions[$aaKey] !== '' && $aaOptions[$aaKey] !== null) {
            return [(string) $aaOptions[$aaKey], 'aa-options'];
        }
        return [(string) ($defaults[$defaultKey] ?? ''), 'defaults'];
    };

    $stringFields = [
        'failover_extension' => ['failover_extension', 'failover_extension', 'failover_extension'],
        'callback_aa'        => ['callback_aa_extension', 'callback_aa', 'callback_aa'],
        'call_decider'       => ['call_decider_extension', 'call_decider', 'call_decider'],
        'auto_attendant'     => ['auto_attendant_extension', 'auto_attendant', 'auto_attendant'],
        'message_url'        => ['message_url', 'message_url', 'message_url'],
    ];
    foreach ($stringFields as $target => $keys) {
        [$domainKey, $aaKey, $defaultKey] = $keys;
        [$value, $source] = $pickString($domainKey, $aaKey, $defaultKey);
        $settings[$target] = $value;
        $settings['sources'][$target] = $source;
    }

    // Routing targets must stay usable as SIP user parts; the audio source
    // must stay a "default"/URL/file value.
    foreach (['failover_extension', 'callback_aa', 'call_decider', 'auto_attendant'] as $key) {
        $settings[$key] = cb_extension($settings[$key]);
    }
    $settings['message_url'] = cb_audio_source($settings['message_url']);

    // Lookup window (minutes), clamped to the configured maximum.
    $maxMinutes = max(1, (int) cb_cfg('callback.max_lookup_minutes', 1440));
    if (is_array($domainCfg) && (int) ($domainCfg['lookup_minutes'] ?? 0) > 0) {
        $settings['lookup_minutes'] = max(1, min((int) $domainCfg['lookup_minutes'], $maxMinutes));
        $settings['sources']['lookup_minutes'] = 'domain-config';
    } elseif (is_array($aaOptions) && (int) ($aaOptions['lookup_minutes'] ?? 0) > 0) {
        $settings['lookup_minutes'] = max(1, min((int) $aaOptions['lookup_minutes'], $maxMinutes));
        $settings['sources']['lookup_minutes'] = 'aa-options';
    } else {
        $settings['lookup_minutes'] = max(1, min((int) ($defaults['lookup_minutes'] ?? 30), $maxMinutes));
        $settings['sources']['lookup_minutes'] = 'defaults';
    }

    // Excluded extensions (lookup set).
    if (is_array($domainCfg) && ($domainCfg['excluded_extensions'] ?? '') !== '') {
        $settings['excluded'] = cb_excluded_set($domainCfg['excluded_extensions']);
        $settings['sources']['excluded'] = 'domain-config';
    } elseif (is_array($aaOptions) && !empty($aaOptions['excluded']) && is_array($aaOptions['excluded'])) {
        $settings['excluded'] = $aaOptions['excluded'];
        $settings['sources']['excluded'] = 'aa-options';
    } else {
        $settings['excluded'] = is_array($defaults['excluded'] ?? null) ? $defaults['excluded'] : [];
        $settings['sources']['excluded'] = 'defaults';
    }

    // Enabled toggle — only the domain config can switch the feature off.
    if (is_array($domainCfg)) {
        $settings['enabled'] = !isset($domainCfg['enabled']) || (bool) $domainCfg['enabled'];
        $settings['sources']['enabled'] = 'domain-config';
    } else {
        $settings['enabled'] = true;
        $settings['sources']['enabled'] = 'defaults';
    }

    return $settings;
}

/* -------------------------------------------------------------------------
 * Hand-off (failover) target
 * ---------------------------------------------------------------------- */

/**
 * The extension a call should be sent to when the callback feature cannot (or
 * must not) handle it. Order:
 *
 *   1. the domain's failover extension (the downstream target — main AA /
 *      after-hours — configured on the config page),
 *   2. when $preferMainAa is set (feature disabled): the main auto attendant,
 *   3. the call decider (legacy behaviour),
 *   4. the global callback.fallback_extension from config.php.
 *
 * The result must be a strict SIP user part (no spaces) so it can be used in
 * a <Forward> and in the <Gather action> URL.
 */
function cb_handoff_extension(array $settings, bool $preferMainAa = false): string
{
    $extension = cb_sip_user($settings['failover_extension'] ?? '');
    if ($extension === '' && $preferMainAa) {
        $extension = cb_sip_user($settings['auto_attendant'] ?? '');
    }
    if ($extension === '') {
        $extension = cb_sip_user($settings['call_decider'] ?? '');
    }
    if ($extension === '') {
        $extension = cb_sip_user(cb_cfg('callback.fallback_extension', ''));
    }
    return $extension;
}

/**
 * Full sip: URI of the hand-off target, or '' when nothing is configured.
 */
function cb_handoff_target(string $domain, array $settings, bool $preferMainAa = false): string
{
    $extension = cb_handoff_extension($settings, $preferMainAa);
    if ($extension === '') {
        return '';
    }
    return cb_sip_uri($extension, $domain);
}
