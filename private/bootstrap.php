<?php
/**
 * Callback2 — shared bootstrap / helper library.
 *
 * This file lives OUTSIDE the public web root (see README.md). It is only
 * meant to be loaded by index.php after CALLBACK_APP is defined, so a direct
 * request to this file (in case it is ever placed inside a docroot) does
 * nothing.
 *
 * @package   Callback2
 * @version   2.0.0
 */

if (!defined('CALLBACK_APP')) {
    http_response_code(404);
    exit;
}

if (!defined('CB_VERSION')) {
    define('CB_VERSION', '2.0.0');
}

/* -------------------------------------------------------------------------
 * Configuration access
 * ---------------------------------------------------------------------- */

/**
 * Get the loaded configuration array (set by index.php before any handler runs).
 */
function cb_config(): array
{
    $config = $GLOBALS['CB_CONFIG'] ?? null;
    return is_array($config) ? $config : [];
}

/**
 * Read a nested config value with a default.
 *
 * @param string $path Dot notation, e.g. "ns_api.request_timeout".
 */
function cb_cfg(string $path, $default = null)
{
    $node = cb_config();
    foreach (explode('.', $path) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) {
            return $default;
        }
        $node = $node[$key];
    }
    return $node;
}

/* -------------------------------------------------------------------------
 * Logging
 * ---------------------------------------------------------------------- */

/**
 * Log a message to the PHP error log and (optionally) to app.log_file.
 * Never log credentials, tokens or the API key.
 */
function cb_log(string $message, string $level = 'error'): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] [callback2] ' . $level . ': ' . $message;

    @error_log($line);

    $file = (string) cb_cfg('app.log_file', '');
    if ($file !== '') {
        $dir = dirname($file);
        if (@is_dir($dir) && @is_writable($dir)) {
            @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}

/* -------------------------------------------------------------------------
 * Input sanitising
 *
 * Every value that comes from the switch ($_GET / $_POST / $_REQUEST) must
 * pass through one of these helpers before it is used, logged or printed.
 * The helpers return '' for anything that does not strictly validate.
 * ---------------------------------------------------------------------- */

/**
 * Coerce an input value to a trimmed string. Arrays / objects become ''.
 */
function cb_scalar($value, int $maxLength = 512): string
{
    if (is_array($value) || is_object($value) || is_bool($value)) {
        return '';
    }
    if ($value === null) {
        return '';
    }
    $value = (string) $value;
    if (strlen($value) > $maxLength * 4) {
        return ''; // absurdly large -> reject outright
    }
    return trim($value);
}

/**
 * Plain text (names, labels): strip control characters, collapse whitespace,
 * enforce a maximum length. The result is still escaped for XML on output.
 */
function cb_clean_text($value, int $maxLength = 64): string
{
    $value = cb_scalar($value, $maxLength * 4);
    if ($value === '') {
        return '';
    }
    // Drop C0/C1 control characters (this also removes CR/LF -> no header injection).
    $value = preg_replace('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/u', ' ', $value);
    if ($value === null) {
        return '';
    }
    $value = preg_replace('/\s+/u', ' ', $value);
    if ($value === null) {
        return '';
    }
    $value = trim($value);
    if (function_exists('mb_substr')) {
        return (string) mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
}

/**
 * Digits only (DIDs, DNIS, gathered digits, option-3 style numbers).
 */
function cb_digits($value, int $maxLength = 20): string
{
    $value = cb_scalar($value, $maxLength + 4);
    if ($value === '') {
        return '';
    }
    return preg_match('/^\d{1,' . (int) $maxLength . '}$/', $value) ? $value : '';
}

/**
 * A phone number as it arrives from the switch: optional leading +, digits
 * only. Anything else (spaces, letters, sip: URIs) is rejected.
 */
function cb_phone($value, int $maxLength = 16): string
{
    $value = cb_scalar($value, $maxLength + 4);
    if ($value === '') {
        return '';
    }
    if (!preg_match('/^\+?\d{1,' . (int) $maxLength . '}$/', $value)) {
        return '';
    }
    return $value;
}

/**
 * Validate a NetSapiens domain/hostname (also used for FQDN API servers).
 */
function cb_domain($value): string
{
    $value = cb_scalar($value, 253);
    if ($value === '' || strlen($value) > 253) {
        return '';
    }
    $value = strtolower($value);
    $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
    if (!preg_match('/^' . $label . '(?:\.' . $label . ')*$/', $value)) {
        return '';
    }
    if (strpos($value, '..') !== false) {
        return '';
    }
    return $value;
}

/**
 * Accept a hostname, IPv4/IPv6 literal, or a base URL and return a clean host.
 * Used for the failover server list so operators can paste either form.
 */
function cb_host($value): string
{
    $value = cb_scalar($value, 300);
    if ($value === '') {
        return '';
    }
    $schemePos = stripos($value, '://');
    if ($schemePos !== false) {
        $value = substr($value, $schemePos + 3);
    }
    $slash = strpos($value, '/');
    if ($slash !== false) {
        $value = substr($value, 0, $slash);
    }
    $at = strpos($value, '@');
    if ($at !== false) {
        $value = substr($value, $at + 1);
    }
    if ($value !== '' && $value[0] === '[') {
        $end = strpos($value, ']');
        if ($end !== false) {
            $value = substr($value, 1, $end - 1);
        }
    } elseif (substr_count($value, ':') === 1) {
        $colon = strrpos($value, ':');
        if ($colon !== false) {
            $value = substr($value, 0, $colon); // strip :port
        }
    }
    $value = strtolower(trim($value));
    if ($value === '') {
        return '';
    }
    if (filter_var($value, FILTER_VALIDATE_IP)) {
        return $value;
    }
    return cb_domain($value);
}

/**
 * A NetSapiens account/user identifier (used in API URLs and Prompt names).
 */
function cb_user_token($value, int $maxLength = 64): string
{
    $value = cb_scalar($value, $maxLength + 4);
    if ($value === '') {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,' . (int) ($maxLength - 1) . '}$/', $value)) {
        return '';
    }
    return $value;
}

/**
 * A SIP user part / extension / destination-user value.
 * Allows letters, digits and . _ - + * # (NetSapiens allows things like "User -4").
 */
function cb_extension($value, int $maxLength = 64): string
{
    $value = cb_scalar($value, $maxLength + 4);
    if ($value === '') {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9._+*#\- ]{1,' . (int) $maxLength . '}$/', $value)) {
        return '';
    }
    $value = trim(preg_replace('/\s+/', ' ', $value));
    return $value === '' ? '' : $value;
}

/**
 * Strict SIP user part / forward target: letters, digits and . _ - + * #
 * (no spaces, no separators — a value that reaches a <Forward> must be a
 * single well-formed user part).
 */
function cb_sip_user($value, int $maxLength = 64): string
{
    $value = cb_scalar($value);
    if ($value === '') {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9._+*#\-]{1,' . (int) $maxLength . '}$/', $value)) {
        return '';
    }
    return $value;
}

/**
 * Build a safe SIP URI for <Forward> from an extension or a full sip: URI.
 * Returns '' when the value cannot be safely used.
 */
function cb_sip_uri($value, string $domain): string
{
    $value = cb_scalar($value, 200);
    if ($value === '') {
        return '';
    }
    $lower = strtolower($value);
    if (strpos($lower, 'sip:') === 0) {
        $value = substr($value, 4);
    }
    if (strpos($value, '@') !== false) {
        $parts = explode('@', $value, 2);
        $user = cb_sip_user($parts[0]);
        $host = cb_host($parts[1]);
        if ($user === '' || $host === '') {
            return '';
        }
        return 'sip:' . $user . '@' . $host;
    }
    $extension = cb_sip_user($value);
    $domain = cb_domain($domain);
    if ($extension === '' || $domain === '') {
        return '';
    }
    return 'sip:' . $extension . '@' . $domain;
}

/**
 * Like cb_sip_uri(), but only accepts targets inside $domain — used for the
 * routing values a <Gather action> can carry back to us.
 */
function cb_sip_uri_local($value, string $domain): string
{
    $uri = cb_sip_uri($value, $domain);
    if ($uri === '') {
        return '';
    }
    $at = strrpos($uri, '@');
    if ($at === false) {
        return '';
    }
    return substr($uri, $at + 1) === $domain ? $uri : '';
}

/**
 * Parse a comma separated list (AA option-5 excluded extensions).
 * Returns a lookup set: "100", "100", "007" and "7" all match sensibly.
 */
function cb_excluded_set($value): array
{
    return cb_lookup_set(cb_clean_list($value));
}

/**
 * Build a lookup set from a list of extension values. Numeric values are
 * stored both as written and normalised ("007" and "7" refer to the same
 * extension).
 */
function cb_lookup_set(array $values): array
{
    $set = [];
    foreach ($values as $item) {
        $item = cb_clean_text($item, 64);
        if ($item === '') {
            continue;
        }
        $set[$item] = true;
        if (is_numeric($item)) {
            $set[(string) (int) $item] = true;
        }
    }
    return $set;
}

/**
 * Read one request parameter (POST wins over GET). Cookies are deliberately
 * ignored — they are client controlled and must never drive call routing.
 */
function cb_request_value(string $key, int $maxLength = 512): string
{
    $value = null;
    if (isset($_POST[$key]) && !is_array($_POST[$key])) {
        $value = $_POST[$key];
    } elseif (isset($_GET[$key]) && !is_array($_GET[$key])) {
        $value = $_GET[$key];
    }
    return $value === null ? '' : cb_scalar($value, $maxLength);
}

/**
 * Extract the API key from the request: X-API-Key style headers first (any
 * header ending in API-KEY), then apikey/api_key query or post parameters
 * (the phone switch cannot send headers on a <Gather action>).
 */
function cb_request_api_key(): string
{
    if (!empty(cb_cfg('security.allow_header_key', true))) {
        $candidates = ['HTTP_X_API_KEY', 'HTTP_X_APIKEY', 'HTTP_API_KEY', 'REDIRECT_HTTP_X_API_KEY'];
        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
                return trim($_SERVER[$key]);
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && strpos($key, 'HTTP_') === 0 && substr($key, -7) === 'API_KEY') {
                return trim($value);
            }
        }
    }

    if (!empty(cb_cfg('security.allow_query_key', true))) {
        foreach (['apikey', 'api_key'] as $key) {
            $value = cb_request_value($key, 512);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

/**
 * Comma separated list -> array of clean, non-empty strings.
 */
function cb_clean_list($value, int $maxItems = 200): array
{
    $value = cb_scalar($value, 4096);
    if ($value === '') {
        return [];
    }
    $items = [];
    foreach (explode(',', $value) as $item) {
        $item = cb_clean_text($item, 64);
        if ($item === '') {
            continue;
        }
        $items[] = $item;
        if (count($items) >= $maxItems) {
            break;
        }
    }
    return $items;
}

/**
 * Caller IP (never trusts proxy headers — those are spoofable).
 */
function cb_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/**
 * Check an IP against an optional allow-list of IPs / IPv4 CIDR ranges.
 * An empty list means "allow any".
 */
function cb_ip_allowed(string $ip, array $ranges): bool
{
    if (empty($ranges)) {
        return true;
    }
    if ($ip === '') {
        return false;
    }
    foreach ($ranges as $range) {
        $range = trim((string) $range);
        if ($range === '') {
            continue;
        }
        if (strpos($range, '/') === false) {
            if ($ip === $range) {
                return true;
            }
            continue;
        }
        list($subnet, $bits) = explode('/', $range, 2);
        $bits = (int) $bits;
        $subnetLong = ip2long($subnet);
        $ipLong = ip2long($ip);
        if ($subnetLong === false || $ipLong === false) {
            continue; // IPv6 CIDR not supported here; exact IPs still work
        }
        if ($bits < 0 || $bits > 32) {
            continue;
        }
        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
        if (($ipLong & $mask) === ($subnetLong & $mask)) {
            return true;
        }
    }
    return false;
}

/* -------------------------------------------------------------------------
 * XML output
 * ---------------------------------------------------------------------- */

/**
 * Escape a value for use inside XML text or an attribute.
 */
function cb_xml($value): string
{
    $flags = ENT_QUOTES;
    if (defined('ENT_XML1')) {
        $flags |= ENT_XML1;
    }
    if (defined('ENT_SUBSTITUTE')) {
        $flags |= ENT_SUBSTITUTE;
    }
    return htmlspecialchars((string) $value, $flags, 'UTF-8');
}

/**
 * Validate an audio source from the AA config: the literal "default",
 * an http(s) URL, or a plain prompt file name.
 */
function cb_audio_source($value): string
{
    $value = cb_scalar($value, 300);
    if ($value === '' || strcasecmp($value, 'default') === 0) {
        return 'default';
    }
    if (preg_match('~^https?://[^\s<>"\'\x00-\x1F]+$~i', $value)) {
        return $value;
    }
    if (preg_match('~^[A-Za-z0-9._\-]{1,200}$~', $value)) {
        return $value;
    }
    cb_log('rejected invalid audio source', 'warning');
    return 'default';
}

/**
 * Send one final XML document. Output buffering is started by index.php so
 * stray notices/warnings can never corrupt the XML response.
 */
function cb_emit(string $document): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $GLOBALS['CB_OUTPUT_DONE'] = true;

    // Test hook: the test-suite captures responses through this callable.
    $sink = $GLOBALS['CB_EMIT_HANDLER'] ?? null;
    if (is_callable($sink)) {
        $sink($document);
        return;
    }
    echo $document;
}

function cb_output_forward(string $sipUri): void
{
    $sipUri = trim($sipUri);
    if ($sipUri === '') {
        cb_output_hangup();
        return;
    }
    cb_emit('<Response><Forward>' . cb_xml($sipUri) . '</Forward></Response>');
}

function cb_output_hangup(): void
{
    cb_emit('<Response><Hangup/></Response>');
}

function cb_output_say_hangup(string $text): void
{
    cb_emit(
        '<Response><Say language="en-US" voice="amy">' . cb_xml($text) . '</Say><Hangup/></Response>'
    );
}

/**
 * Build a <Gather> response.
 *
 * @param string $actionUrl Absolute URL the switch should POST the digits to.
 * @param string $audio     'default' to use text-to-speech, otherwise a prompt file/URL.
 * @param string $sayText   Already escaped text-to-speech message. Callers are
 *                          responsible for escaping any interpolated values
 *                          (the string may contain markup such as <say-as>).
 */
function cb_output_gather(string $actionUrl, string $audio, string $sayText, int $numDigits = 6, int $timeout = 5): void
{
    $gather = '<Gather numDigits="' . (int) $numDigits . '" timeout="' . (int) $timeout
        . '" action="' . cb_xml($actionUrl) . '">';

    if ($audio !== '' && strcasecmp($audio, 'default') !== 0) {
        $gather .= '<Play>' . cb_xml($audio) . '</Play>';
    } else {
        $gather .= '<Say language="en-US" voice="amy">' . $sayText . '</Say>';
    }

    $gather .= '</Gather>';
    cb_emit('<Response>' . $gather . '<Hangup/></Response>');
}

/**
 * Last-resort response: used by the shutdown/exception handlers so the switch
 * always receives well-formed XML instead of a truncated document.
 */
function cb_emergency_response(): void
{
    if (!empty($GLOBALS['CB_OUTPUT_DONE'])) {
        return;
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $config = cb_config();
    $domain = cb_domain(cb_scalar($_REQUEST['AccountDomain'] ?? $_GET['domain'] ?? $_REQUEST['domain'] ?? ''));
    $fallback = cb_extension(cb_cfg('callback.fallback_extension', ''));

    if ($fallback !== '' && $domain !== '') {
        cb_output_forward('sip:' . $fallback . '@' . $domain);
        return;
    }
    cb_output_say_hangup('We are unable to complete your call at this time. Please try again later.');
}

/* -------------------------------------------------------------------------
 * URLs and HTTP
 * ---------------------------------------------------------------------- */

/**
 * Correctly encoded query string (rawurlencode both keys and values) that
 * keeps working for XML attribute values once escaped.
 */
function cb_build_query(array $params): string
{
    $pairs = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
    }
    return implode('&', $pairs);
}

/**
 * Public URL of index.php, used for the <Gather action="..."> attribute.
 * Prefers the explicit callback.public_url setting, otherwise derives it from
 * the current request.
 */
function cb_public_base_url(): string
{
    $configured = cb_scalar(cb_cfg('callback.public_url', ''), 500);
    if ($configured !== '') {
        if (preg_match('~^https?://[^\s<>"\']+$~i', $configured)) {
            return $configured;
        }
        cb_log('callback.public_url is not a valid http(s) URL; deriving from request instead', 'warning');
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $scheme = $https ? 'https' : 'http';

    $host = cb_host((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        $host = cb_host((string) ($_SERVER['SERVER_NAME'] ?? ''));
    }

    $path = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $path = preg_replace('~[?#].*$~', '', $path);
    $path = preg_replace('~[^/A-Za-z0-9._\-]~', '', (string) $path);
    if ($path === '') {
        $path = '/index.php';
    }

    if ($host === '') {
        return $path; // relative fallback, same as the legacy behaviour
    }
    return $scheme . '://' . $host . $path;
}

/**
 * Full action URL for a <Gather>, including the action name and API key.
 */
function cb_action_url(string $action, array $params): string
{
    $params = array_merge(['action' => $action], $params);
    $query = cb_build_query($params);
    $base = cb_public_base_url();
    return $query === '' ? $base : $base . '?' . $query;
}

/**
 * Perform an HTTP request. Uses cURL when available and falls back to the
 * stream wrapper so the app still works on minimal PHP builds (no Guzzle).
 *
 * Guarded with function_exists() so the test-suite can stub this out.
 *
 * @param array $options method, headers (name=>value), body, timeout, connect_timeout
 * @return array{ok:bool,status:int,body:string,error:string}
 */
if (!function_exists('cb_http_request')) {
    function cb_http_request(string $url, array $options = []): array
    {
        $method = strtoupper((string) ($options['method'] ?? 'GET'));
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $body = $options['body'] ?? null;
        $timeout = max(1, (int) ($options['timeout'] ?? 10));
        $connectTimeout = max(1, (int) ($options['connect_timeout'] ?? 5));

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $name = trim((string) $name);
            if ($name === '' || preg_match('/[\r\n:]/', $name)) {
                continue;
            }
            $headerLines[] = $name . ': ' . str_replace(["\r", "\n"], '', (string) $value);
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $curlOptions = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => $headerLines,
                CURLOPT_USERAGENT      => 'callback2/' . CB_VERSION,
                CURLOPT_TCP_KEEPALIVE  => 1,
            ];
            if (defined('CURLOPT_ENCODING')) {
                $curlOptions[CURLOPT_ENCODING] = '';
            }
            if ($method === 'POST') {
                $curlOptions[CURLOPT_POST] = true;
                $curlOptions[CURLOPT_POSTFIELDS] = is_string($body) ? $body : http_build_query((array) $body);
            }
            curl_setopt_array($ch, $curlOptions);

            $response = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($errno !== 0 || $response === false) {
                return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl: ' . ($error !== '' ? $error : 'error ' . $errno)];
            }
            return [
                'ok'     => $status >= 200 && $status < 300,
                'status' => $status,
                'body'   => (string) $response,
                'error'  => $status >= 200 && $status < 300 ? '' : 'http ' . $status,
            ];
        }

        // --- stream fallback (no curl extension) ---
        $contextOptions = [
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headerLines),
                'timeout'       => $timeout,
                'ignore_errors' => true,
                'content'       => is_string($body) ? $body : http_build_query((array) $body),
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ];
        $context = stream_context_create($contextOptions);
        $response = @file_get_contents($url, false, $context);

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        if ($response === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => 'stream request failed'];
        }
        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => (string) $response,
            'error'  => $status >= 200 && $status < 300 ? '' : 'http ' . $status,
        ];
    }
}

/* -------------------------------------------------------------------------
 * Small file cache (OAuth tokens, optional AA config cache)
 * ---------------------------------------------------------------------- */

/**
 * Resolve the cache directory, creating it (0700) when needed.
 */
function cb_cache_dir(string $sub = ''): string
{
    $dir = cb_scalar(cb_cfg('ns_api.token_cache_dir', ''), 300);
    if ($dir === '') {
        $dir = sys_get_temp_dir() . '/callback2-cache';
    }
    $dir = rtrim($dir, '/\\');
    if ($sub !== '') {
        $dir .= '/' . preg_replace('/[^a-z0-9._\-]/i', '', $sub);
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function cb_cache_file(string $prefix, string $key): string
{
    return cb_cache_dir() . '/' . $prefix . '-' . substr(sha1($key), 0, 20) . '.json';
}

/**
 * Read a JSON cache entry. Returns null when missing/corrupt.
 */
function cb_cache_read(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $handle = @fopen($file, 'rb');
    if (!$handle) {
        return null;
    }
    @flock($handle, LOCK_SH);
    $contents = stream_get_contents($handle);
    @flock($handle, LOCK_UN);
    fclose($handle);

    if (!is_string($contents) || $contents === '') {
        return null;
    }
    $data = json_decode($contents, true);
    return is_array($data) ? $data : null;
}

/**
 * Atomically write a JSON cache entry with 0600 permissions.
 */
function cb_cache_write(string $file, array $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    $tmp = @tempnam($dir, 'cb');
    if ($tmp === false) {
        return false;
    }
    $json = json_encode($data);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/* -------------------------------------------------------------------------
 * NS-API credentials + OAuth token
 * ---------------------------------------------------------------------- */

/**
 * Resolve the NS-API credentials, in priority order:
 *   1. config file  (ns_api.credentials)
 *   2. environment  (CDR_CLIENT_ID, CDR_CLIENT_SECRET, CDR_USERNAME, CDR_PASSWORD)
 *   3. legacy constants (CLIENT_ID, CLIENT_SECRET, SUPER_USER, SUPER_PASS)
 *
 * @return array client_id/client_secret/username/password/grant_type or [] when incomplete.
 */
function cb_ns_api_credentials(): array
{
    $cfg = (array) cb_cfg('ns_api.credentials', []);
    $pick = function ($key, $fallback) use ($cfg) {
        $value = $cfg[$key] ?? '';
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        $value = getenv($fallback);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        return '';
    };

    $credentials = [
        'client_id'     => $pick('client_id', 'CDR_CLIENT_ID'),
        'client_secret' => $pick('client_secret', 'CDR_CLIENT_SECRET'),
        'username'      => $pick('username', 'CDR_USERNAME'),
        'password'      => $pick('password', 'CDR_PASSWORD'),
        'grant_type'    => 'password',
    ];

    // Legacy constants from the old config.php (defined globally).
    $legacy = [
        'client_id'     => 'CLIENT_ID',
        'client_secret' => 'CLIENT_SECRET',
        'username'      => 'SUPER_USER',
        'password'      => 'SUPER_PASS',
    ];
    foreach ($legacy as $key => $constant) {
        if ($credentials[$key] === '' && defined($constant)) {
            $value = constant($constant);
            if (is_string($value) && trim($value) !== '') {
                $credentials[$key] = trim($value);
            }
        }
    }

    foreach (['client_id', 'client_secret', 'username', 'password'] as $required) {
        if ($credentials[$required] === '') {
            cb_log('NS-API credentials are incomplete (missing ' . $required . ')');
            return [];
        }
    }
    return $credentials;
}

/**
 * Get an OAuth access token for one API server, using a per-server file cache.
 */
function cb_ns_api_token(string $server, bool $forceRefresh = false): string
{
    $cacheFile = cb_cache_file('token', $server);

    if (!$forceRefresh) {
        $cached = cb_cache_read($cacheFile);
        if ($cached !== null
            && isset($cached['token'], $cached['expiry'])
            && is_string($cached['token'])
            && $cached['token'] !== ''
            && (int) $cached['expiry'] > time() + 5
        ) {
            return $cached['token'];
        }
    }

    $credentials = cb_ns_api_credentials();
    if (empty($credentials)) {
        return '';
    }

    $url = 'https://' . $server . '/ns-api/oauth2/token/';
    $result = cb_http_request($url, [
        'method'          => 'POST',
        'headers'         => [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept'       => 'application/json',
        ],
        'body'            => cb_build_query($credentials),
        'timeout'         => max(1, (int) cb_cfg('ns_api.token_timeout', 8)),
        'connect_timeout' => max(1, (int) cb_cfg('ns_api.connect_timeout', 5)),
    ]);

    if (!$result['ok']) {
        cb_log('OAuth token request failed on ' . $server . ': ' . $result['error']);
        return '';
    }

    $data = json_decode($result['body'], true);
    $token = is_array($data) ? ($data['access_token'] ?? '') : '';
    if (!is_string($token) || $token === '') {
        cb_log('OAuth token response from ' . $server . ' contained no access_token');
        return '';
    }

    $expiresIn = (int) ($data['expires_in'] ?? 3600);
    if ($expiresIn < 60 || $expiresIn > 86400) {
        $expiresIn = 3600;
    }
    cb_cache_write($cacheFile, [
        'token'  => $token,
        'expiry' => time() + $expiresIn - 60,
    ]);

    return $token;
}

/* -------------------------------------------------------------------------
 * NS-API client with failover
 * ---------------------------------------------------------------------- */

/**
 * Talks to the NetSapiens API with server failover.
 *
 * Servers from ns_api.servers are tried in order. A server is skipped for the
 * rest of the request once it fails. A 401/403 triggers one token refresh and
 * retry on the same server before moving on. The total time spent across all
 * attempts is capped by ns_api.total_budget_seconds so a call is never left
 * hanging by a dead API node.
 */
final class CbNsApiClient
{
    /** @var array */
    private $config;
    /** @var string[] */
    private $servers = [];
    /** @var bool[] */
    private $failed = [];
    /** @var string */
    private $lastServer = '';
    /** @var string */
    private $lastError = '';

    public function __construct(array $config)
    {
        $this->config = $config;

        $servers = $config['ns_api']['servers'] ?? [];
        if (is_string($servers)) {
            $servers = [$servers];
        }
        $clean = [];
        foreach ((array) $servers as $server) {
            $host = cb_host($server);
            if ($host !== '') {
                $clean[$host] = true;
            }
        }
        $this->servers = array_keys($clean);

        if (empty($this->servers)) {
            cb_log('no valid NS-API servers configured in ns_api.servers');
        }
    }

    /** @return string[] */
    public function servers(): array
    {
        return $this->servers;
    }

    public function lastServer(): string
    {
        return $this->lastServer;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * GET an NS-API path (relative to /ns-api/) with failover.
     *
     * @return array{ok:bool,data:?array,status:int,server:string,error:string}
     */
    public function get(string $path, array $query = []): array
    {
        $path = ltrim($path, '/');
        $budget = max(2, (int) ($this->config['ns_api']['total_budget_seconds'] ?? 12));
        $deadline = microtime(true) + $budget;

        $requestTimeout = max(2, (int) ($this->config['ns_api']['request_timeout'] ?? 10));
        $connectTimeout = max(1, (int) ($this->config['ns_api']['connect_timeout'] ?? 5));
        $errors = [];

        foreach ($this->servers as $server) {
            if (isset($this->failed[$server])) {
                continue;
            }

            $remaining = (int) floor($deadline - microtime(true));
            if ($remaining < 1) {
                $errors[] = 'total time budget exhausted';
                break;
            }

            $token = cb_ns_api_token($server);
            if ($token === '') {
                $this->failed[$server] = true;
                $errors[] = $server . ': no OAuth token';
                continue;
            }

            $attempt = 0;
            while ($attempt < 2) {
                $attempt++;

                $remaining = (int) floor($deadline - microtime(true));
                if ($remaining < 1) {
                    $errors[] = $server . ': time budget exhausted';
                    break;
                }

                $url = 'https://' . $server . '/ns-api/' . $path;
                if (!empty($query)) {
                    $url .= '?' . cb_build_query($query);
                }

                $result = cb_http_request($url, [
                    'headers'         => [
                        'Accept'        => 'application/json',
                        'Authorization' => 'Bearer ' . $token,
                    ],
                    'timeout'         => min($requestTimeout, $remaining),
                    'connect_timeout' => min($connectTimeout, $remaining),
                ]);

                if ($result['ok']) {
                    $data = json_decode($result['body'], true);
                    if (is_array($data)) {
                        $this->lastServer = $server;
                        return ['ok' => true, 'data' => $data, 'status' => $result['status'], 'server' => $server, 'error' => ''];
                    }
                    $errors[] = $server . ': invalid JSON response';
                    $this->failed[$server] = true;
                    break;
                }

                // Expired/invalid token -> refresh once and retry on the same server.
                if ($attempt === 1 && in_array($result['status'], [401, 403], true)) {
                    $token = cb_ns_api_token($server, true);
                    if ($token !== '') {
                        continue;
                    }
                    $errors[] = $server . ': token refresh failed';
                    $this->failed[$server] = true;
                    break;
                }

                $errors[] = $server . ': ' . $result['error'];
                $this->failed[$server] = true;
                break;
            }
        }

        $this->lastError = implode('; ', array_filter($errors));
        if ($this->lastError !== '') {
            cb_log('NS-API request failed (' . $path . '): ' . $this->lastError);
        }
        return ['ok' => false, 'data' => null, 'status' => 0, 'server' => '', 'error' => $this->lastError];
    }
}

/* -------------------------------------------------------------------------
 * CDR + AA helpers
 * ---------------------------------------------------------------------- */

/**
 * Normalise a CDR API payload into a list of records.
 */
function cb_cdr_records(?array $data): array
{
    if (!is_array($data)) {
        return [];
    }
    foreach (['records', 'data', 'results'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            return array_values(array_filter($data[$key], 'is_array'));
        }
    }
    return array_values(array_filter($data, 'is_array'));
}

/**
 * Parse the AA option-3 value into a lookup window in minutes.
 *
 * Supported forms:   "30" (30 minutes), "45m", "2h", "User -4" / "-4" (legacy
 * hours), "1". Bare values <= 12 keep the legacy meaning of HOURS; anything
 * larger is read as minutes. The result is clamped to 1..$maxMinutes.
 */
function cb_parse_lookup_minutes($value, int $defaultMinutes = 30, int $maxMinutes = 1440): int
{
    $defaultMinutes = max(1, min($defaultMinutes, $maxMinutes));

    $raw = strtolower(cb_scalar($value, 60));
    if ($raw === '') {
        return $defaultMinutes;
    }

    $unit = '';
    if (substr($raw, -1) === 'm' || substr($raw, -1) === 'h') {
        $unit = substr($raw, -1);
    }

    if (!preg_match('/([+\-]?\d+)/', $raw, $match)) {
        return $defaultMinutes;
    }
    $number = abs((int) $match[1]);
    if ($number === 0) {
        return $defaultMinutes;
    }

    if ($unit === 'm') {
        $minutes = $number;
    } elseif ($unit === 'h') {
        $minutes = $number * 60;
    } else {
        $minutes = $number <= 12 ? $number * 60 : $number; // legacy: small bare numbers were hours
    }

    return max(1, min($minutes, $maxMinutes));
}

/**
 * Prompt name lookup table: account user "1234" -> Prompt_123401 style suffix.
 */
function cb_generate_prompt_identifier(string $accountUser): string
{
    $length = strlen($accountUser);
    if ($length === 2) {
        return $accountUser . '0001';
    }
    if ($length === 3) {
        return $accountUser . '001';
    }
    if ($length === 4) {
        return $accountUser . '01';
    }
    return $accountUser . '1';
}

/**
 * Wrap an extension in <say-as interpret-as="digits"> when it is numeric.
 * The returned string is meant to be embedded in an already escaped message.
 */
function cb_say_digits_hint(string $extension): string
{
    if ($extension !== '' && preg_match('/^\d{1,12}$/', $extension)) {
        return '<say-as interpret-as="digits">' . cb_xml($extension) . '</say-as>';
    }
    return cb_xml($extension);
}

/* -------------------------------------------------------------------------
 * API key validation (used by index.php)
 * ---------------------------------------------------------------------- */

/**
 * Constant-time API key check. Rejects empty/short configured keys entirely.
 */
function cb_api_key_valid(string $provided, array $config): bool
{
    $expected = (string) ($config['security']['api_key'] ?? '');
    if (strlen($expected) < 16) {
        return false;
    }
    if ($provided === '' || strlen($provided) > 512) {
        return false;
    }
    return hash_equals($expected, $provided);
}

/**
 * Resolve the requested action name to a handler key.
 *
 * @return string "callback", "gather", or "" when the action is unknown.
 */
function cb_resolve_action($raw): string
{
    $action = strtolower(cb_scalar($raw, 40));
    if ($action === '') {
        return 'callback';
    }
    $aliases = [
        'cb'                => 'callback',
        'main'              => 'callback',
        'callbackfeaturev2' => 'gather',
        'featurev2'         => 'gather',
        'digits'            => 'gather',
    ];
    if (isset($aliases[$action])) {
        $action = $aliases[$action];
    }
    return in_array($action, ['callback', 'gather'], true) ? $action : '';
}

/**
 * Authorise the current request (method, TLS, IP allow-list, API key).
 *
 * @return array{ok:bool,status:int,reason:string}
 */
function cb_authorize_request(array $config): array
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $allowedMethods = array_map('strtoupper', (array) cb_cfg('security.allowed_methods', ['GET', 'POST']));
    if (!in_array($method, $allowedMethods, true)) {
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

    if (!cb_ip_allowed(cb_client_ip(), (array) cb_cfg('security.allowed_ips', []))) {
        return ['ok' => false, 'status' => 403, 'reason' => 'IP ' . cb_client_ip() . ' not allowed'];
    }

    $expected = (string) cb_cfg('security.api_key', '');
    if (strlen($expected) < 16) {
        return ['ok' => false, 'status' => 500, 'reason' => 'security.api_key is missing or shorter than 16 characters'];
    }

    if (!cb_api_key_valid(cb_request_api_key(), $config)) {
        return ['ok' => false, 'status' => 403, 'reason' => 'missing or invalid API key'];
    }

    return ['ok' => true, 'status' => 200, 'reason' => 'ok'];
}
