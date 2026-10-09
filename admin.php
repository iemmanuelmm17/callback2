<?php
/**
 * Callback2 — public entry point for the per-domain configuration UI.
 *
 * Thin shim, same pattern as index.php: everything (authentication, session,
 * HTML, domain-config storage) lives in the private directory, outside the web
 * root. This file only locates the private app, loads the configuration and
 * hands over to cb_admin_run().
 *
 * The panel is protected by its own admin key (security.admin_key) — it is
 * deliberately NOT reachable with the switch's API key.
 *
 * @package Callback2
 */

if (!defined('CALLBACK_APP')) {
    define('CALLBACK_APP', true);
}

// --- Fail hard but silently: never leak paths/errors to visitors -------------
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('expose_php', '0');
error_reporting(E_ALL);

// --- Locate the private application directory -------------------------------
$privateDir = getenv('CALLBACK_PRIVATE_DIR');
if (!is_string($privateDir) || trim($privateDir) === '') {
    $privateDir = __DIR__ . '/private';
}
$privateDir = rtrim(trim($privateDir), "/\\");

$bootstrap = $privateDir . '/bootstrap.php';
if (!is_file($bootstrap)) {
    @error_log('[callback2] admin: bootstrap not found at ' . $bootstrap);
    http_response_code(500);
    exit('Internal Server Error');
}

require_once $bootstrap;

// --- Load the configuration -------------------------------------------------
$config = cb_load_config($privateDir);
if ($config === null) {
    @error_log('[callback2] admin: configuration not found or invalid (see error log)');
    http_response_code(500);
    exit('Internal Server Error');
}

$GLOBALS['CB_CONFIG'] = $config;

$timezone = (string) ($config['app']['timezone'] ?? 'UTC');
if ($timezone !== '') {
    @date_default_timezone_set($timezone);
}

$maxExecution = (int) ($config['app']['max_execution'] ?? 25);
if ($maxExecution > 0 && function_exists('set_time_limit')) {
    @set_time_limit($maxExecution);
}

$admin = $privateDir . '/admin.php';
if (!is_file($admin)) {
    @error_log('[callback2] admin: admin UI not found at ' . $admin);
    http_response_code(500);
    exit('Internal Server Error');
}

require_once $admin;

try {
    cb_admin_run($config);
} catch (Throwable $exception) {
    @error_log('[callback2] admin: ' . $exception->getMessage()
        . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Internal Server Error';
}
