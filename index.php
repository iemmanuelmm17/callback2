<?php
/**
 * Callback2 — public entry point.
 *
 * This is the ONLY file that needs to be reachable from the web. It:
 *   1. loads the private application (bootstrap + handlers + config) from a
 *      directory outside the web root (CALLBACK_PRIVATE_DIR),
 *   2. authenticates the request with an API key (X-API-Key header, or the
 *      apikey query parameter for phone switches that cannot send headers),
 *   3. routes the request to the callback handler (default) or the <Gather>
 *      digit handler (action=gather),
 *   4. guarantees that the switch always receives well-formed XML — any
 *      unexpected error is turned into a safe forward/hangup response.
 *
 * Deployment and configuration are documented in README.md.
 *
 * @package Callback2
 */

if (!defined('CALLBACK_APP')) {
    define('CALLBACK_APP', true);
}
if (!defined('CALLBACK_ROOT')) {
    define('CALLBACK_ROOT', __DIR__);
}

// --- Fail hard but silently: never leak paths/errors to the switch ----------
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('expose_php', '0');
error_reporting(E_ALL);

if (!headers_sent()) {
    header('Content-Type: text/xml; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

ob_start();

/**
 * Minimal response used before the application (and its config) is loaded.
 */
function cb_index_boot_failure(string $message, int $status = 500): void
{
    @error_log('[callback2] boot failure: ' . $message);
    if (!headers_sent()) {
        http_response_code($status);
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    echo '<Response><Hangup/></Response>';
    exit;
}

// --- Locate the private application directory -------------------------------
$privateDir = getenv('CALLBACK_PRIVATE_DIR');
if (!is_string($privateDir) || trim($privateDir) === '') {
    $privateDir = __DIR__ . '/private';
}
$privateDir = rtrim(trim($privateDir), "/\\");

$bootstrap = $privateDir . '/bootstrap.php';
if (!is_file($bootstrap)) {
    cb_index_boot_failure('bootstrap not found at ' . $bootstrap);
}

require_once $bootstrap;

// --- Load the configuration -------------------------------------------------
$configPath = getenv('CALLBACK_CONFIG');
if (!is_string($configPath) || trim($configPath) === '') {
    $configPath = $privateDir . '/config.php';
}

$config = null;
if (is_file($configPath)) {
    $loaded = require $configPath;
    if (is_array($loaded)) {
        $config = $loaded;
    }
}

/*
 * Backwards compatibility: the old config.php only defined constants
 * (CLIENT_ID, SUPER_USER, ...) and returned nothing. Rebuild a config array
 * from those constants so existing installs keep working during migration.
 */
if ($config === null) {
    $legacyServers = [];
    foreach (['NS_API_SERVERS', 'NS_SERVERS', 'NS_SERVER', 'SERVER'] as $constant) {
        if (!defined($constant)) {
            continue;
        }
        $value = constant($constant);
        if (is_string($value) && trim($value) !== '') {
            $legacyServers = [trim($value)];
            break;
        }
        if (is_array($value)) {
            $legacyServers = $value;
            break;
        }
    }

    $legacyKey = '';
    foreach (['CALLBACK_API_KEY', 'API_KEY'] as $constant) {
        if (defined($constant) && is_string(constant($constant)) && trim(constant($constant)) !== '') {
            $legacyKey = trim(constant($constant));
            break;
        }
    }

    if (!empty($legacyServers) || $legacyKey !== '') {
        $config = [
            'security' => ['api_key' => $legacyKey],
            'ns_api'   => ['servers' => $legacyServers, 'credentials' => []],
        ];
        @error_log('[callback2] using legacy constant based config; please migrate to '
            . $privateDir . '/config.php (see config.example.php)');
    }
}

if ($config === null) {
    cb_index_boot_failure('configuration not found at ' . $configPath);
}

// --- Merge defaults so every key exists ------------------------------------
$config = array_replace_recursive([
    'app' => [
        'timezone'      => 'UTC',
        'log_file'      => '',
        'access_log'    => false,
        'max_execution' => 25,
    ],
    'security' => [
        'api_key'          => '',
        'allowed_ips'      => [],
        'allowed_methods'  => ['GET', 'POST'],
        'allow_header_key' => true,
        'allow_query_key'  => true,
        'require_https'    => false,
    ],
    'ns_api' => [
        'servers'              => [],
        'request_timeout'      => 10,
        'connect_timeout'      => 5,
        'token_timeout'        => 8,
        'total_budget_seconds' => 12,
        'token_cache_dir'      => '',
        'credentials'          => [],
    ],
    'callback' => [
        'default_lookup_minutes'         => 30,
        'max_lookup_minutes'             => 1440,
        'aa_cache_seconds'               => 60,
        'fallback_extension'             => '',
        'default_call_decider_extension' => '',
        'default_callback_aa_extension'  => '',
        'default_auto_attendant_extension' => '',
        'default_message_url'            => 'default',
        'default_excluded_extensions'    => '',
        'public_url'                     => '',
        'legacy_domain'                  => '',
        'legacy_audio'                   => 'jewishcallbackivr2.wav',
        'legacy_gather_timeout'          => 5,
    ],
], $config);

$GLOBALS['CB_CONFIG'] = $config;

$timezone = (string) ($config['app']['timezone'] ?? 'UTC');
if ($timezone !== '') {
    @date_default_timezone_set($timezone);
}

$maxExecution = (int) ($config['app']['max_execution'] ?? 25);
if ($maxExecution > 0 && function_exists('set_time_limit')) {
    @set_time_limit($maxExecution);
}

// --- Last line of defence: always produce a valid XML response --------------
set_exception_handler(function ($exception) {
    cb_log('uncaught exception: ' . $exception->getMessage()
        . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
    cb_emergency_response();
});

register_shutdown_function(function () {
    $error = error_get_last();
    if (is_array($error)
        && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)
    ) {
        cb_log('fatal error: ' . $error['message'] . ' @ ' . $error['file'] . ':' . $error['line']);
        cb_emergency_response();
    }
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
});

// --- Request authentication -------------------------------------------------
header('Content-Type: text/xml; charset=utf-8');

$authorization = cb_authorize_request($config);
if (!$authorization['ok']) {
    cb_log('request rejected (' . $authorization['status'] . '): ' . $authorization['reason']
        . ' from ' . (cb_client_ip() !== '' ? cb_client_ip() : 'unknown'), 'warning');
    http_response_code($authorization['status']);
    echo '<Response><Hangup/></Response>';
    exit;
}

$providedKey = cb_request_api_key();

// --- Route ------------------------------------------------------------------
$action = cb_resolve_action(cb_request_value('action', 40));

$handlers = [
    'callback' => [
        'file'   => $privateDir . '/cb.php',
        'runner' => 'run_callback',
    ],
    'gather'   => [
        'file'   => $privateDir . '/callbackfeaturev2.php',
        'runner' => 'run_gather_handler',
    ],
];

if ($action === '' || !isset($handlers[$action])) {
    cb_log('unknown action requested', 'warning');
    http_response_code(404);
    echo '<Response><Hangup/></Response>';
    exit;
}

$handler = $handlers[$action];
if (!is_file($handler['file'])) {
    cb_index_boot_failure('handler not found at ' . $handler['file']);
}

require_once $handler['file'];

if (!empty($config['app']['access_log'])) {
    cb_log('action=' . $action
        . ' ip=' . (cb_client_ip() !== '' ? cb_client_ip() : 'unknown')
        . ' did=' . cb_clean_text(cb_request_value('did', 40), 40)
        . ' digits=' . cb_digits(cb_request_value('Digits', 20), 12), 'info');
}

try {
    call_user_func($handler['runner'], $config, $providedKey);
} catch (Throwable $exception) {
    cb_log('handler "' . $action . '" failed: ' . $exception->getMessage()
        . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
    cb_emergency_response();
}

// A handler must always answer; if it did not, hang up instead of leaving the
// switch with an empty (invalid) document.
if (empty($GLOBALS['CB_OUTPUT_DONE'])) {
    cb_log('handler "' . $action . '" produced no response', 'warning');
    cb_output_hangup();
}
