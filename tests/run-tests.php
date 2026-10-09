<?php
/**
 * Callback2 — offline test-suite.
 *
 * Runs the real handlers against a stubbed NS-API so behaviour (failover,
 * sanitising, XML output, lookup windows) can be verified without a phone
 * system or network access.
 *
 * Usage:
 *     php tests/run-tests.php
 *
 * @package Callback2
 */

// Refuse to run from a web SAPI.
if (in_array(PHP_SAPI, ['apache2handler', 'apache2filter', 'fpm-fcgi', 'cgi-fcgi', 'litespeed', 'cli-server'], true)) {
    http_response_code(404);
    exit;
}

define('CALLBACK_APP', true);
error_reporting(E_ALL);
@ini_set('display_errors', '1');

/* -------------------------------------------------------------------------
 * HTTP stub — defined BEFORE bootstrap.php, which only declares the real
 * cb_http_request() when it does not exist yet.
 * ---------------------------------------------------------------------- */

$GLOBALS['CB_TEST_HTTP_CALLS'] = [];
$GLOBALS['T'] = [
    'fail_token_servers'    => [],
    'fail_api_servers'      => [],
    'unauthorized_attempts' => 0,
    'aa'                    => null,
    'cdr_caller'            => [],
    'cdr_dialled'           => [],
    'token_calls'           => [],
];

function cb_test_reset_http(): void
{
    $GLOBALS['CB_TEST_HTTP_CALLS'] = [];
    $GLOBALS['T']['fail_token_servers'] = [];
    $GLOBALS['T']['fail_api_servers'] = [];
    $GLOBALS['T']['unauthorized_attempts'] = 0;
    $GLOBALS['T']['aa'] = null;
    $GLOBALS['T']['cdr_caller'] = [];
    $GLOBALS['T']['cdr_dialled'] = [];
    $GLOBALS['T']['token_calls'] = [];
}

function cb_test_ok(string $body, int $status = 200): array
{
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $body, 'error' => ''];
}

function cb_test_fail(string $error, int $status = 0): array
{
    return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $error];
}

function cb_http_request(string $url, array $options = []): array
{
    $GLOBALS['CB_TEST_HTTP_CALLS'][] = ['url' => $url, 'options' => $options];

    $host = (string) parse_url($url, PHP_URL_HOST);
    $path = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    if (strpos($path, '/oauth2/token') !== false) {
        if (in_array($host, (array) $GLOBALS['T']['fail_token_servers'], true)) {
            return cb_test_fail('curl: connect timeout');
        }
        $GLOBALS['T']['token_calls'][] = $host;
        return cb_test_ok(json_encode(['access_token' => 'token-' . $host, 'expires_in' => 3600]));
    }

    if (in_array($host, (array) $GLOBALS['T']['fail_api_servers'], true)) {
        return cb_test_fail('curl: connection refused');
    }
    if ((int) $GLOBALS['T']['unauthorized_attempts'] > 0) {
        $GLOBALS['T']['unauthorized_attempts']--;
        return cb_test_fail('http 401', 401);
    }

    if (strpos($path, '/autoattendants/') !== false) {
        if ($GLOBALS['T']['aa'] === null) {
            return cb_test_fail('http 500', 500);
        }
        return cb_test_ok(json_encode($GLOBALS['T']['aa']));
    }

    if (strpos($path, '/cdrs') !== false) {
        if (isset($query['caller'])) {
            return cb_test_ok(json_encode($GLOBALS['T']['cdr_caller']));
        }
        if (isset($query['dialled'])) {
            return cb_test_ok(json_encode($GLOBALS['T']['cdr_dialled']));
        }
        return cb_test_ok('[]');
    }

    return cb_test_fail('http 404', 404);
}

require __DIR__ . '/../private/bootstrap.php';
require __DIR__ . '/../private/cb.php';
require __DIR__ . '/../private/callbackfeaturev2.php';
require __DIR__ . '/../private/admin.php';

/* -------------------------------------------------------------------------
 * Tiny assertion framework
 * ---------------------------------------------------------------------- */

$GLOBALS['CB_TEST_STATS'] = ['pass' => 0, 'fail' => 0];
$GLOBALS['CB_TEST_SECTION'] = '';

function t_section(string $name): void
{
    $GLOBALS['CB_TEST_SECTION'] = $name;
    echo "\n" . $name . "\n" . str_repeat('-', strlen($name)) . "\n";
}

function t_ok(bool $condition, string $label): void
{
    if ($condition) {
        $GLOBALS['CB_TEST_STATS']['pass']++;
        echo "  PASS  " . $label . "\n";
        return;
    }
    $GLOBALS['CB_TEST_STATS']['fail']++;
    echo "  FAIL  " . $label . "\n";
}

function t_same($expected, $actual, string $label): void
{
    t_ok(
        $expected === $actual,
        $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')
    );
}

function t_contains(string $needle, string $haystack, string $label): void
{
    $found = strpos($haystack, $needle) !== false;
    t_ok($found, $label . ($found ? '' : ' — missing "' . $needle . '"'));
    if (!$found) {
        echo '        actual: ' . substr(preg_replace('/\s+/', ' ', $haystack), 0, 400) . "\n";
    }
}

function t_not_contains(string $needle, string $haystack, string $label): void
{
    $found = strpos($haystack, $needle) !== false;
    t_ok(!$found, $label . ($found ? ' — unexpectedly found "' . $needle . '"' : ''));
    if ($found) {
        echo '        actual: ' . substr(preg_replace('/\s+/', ' ', $haystack), 0, 400) . "\n";
    }
}

function t_well_formed(string $xml, string $label): void
{
    if (class_exists('DOMDocument') && function_exists('libxml_use_internal_errors')) {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        t_ok($loaded, $label . ' (well-formed XML)');
        return;
    }
    t_ok(strpos($xml, '<Response>') === 0 && strpos($xml, '</Response>') !== false, $label . ' (basic check)');
}

/* -------------------------------------------------------------------------
 * Fixtures
 * ---------------------------------------------------------------------- */

function t_config(array $overrides = []): array
{
    $config = [
        'app' => ['timezone' => 'UTC', 'log_file' => '', 'access_log' => false, 'max_execution' => 25],
        'security' => [
            'api_key' => '0123456789abcdef0123456789abcdef',
            'allowed_ips' => [],
            'allowed_methods' => ['GET', 'POST'],
            'allow_header_key' => true,
            'allow_query_key' => true,
            'require_https' => false,
            'admin_key' => 'admin-secret-key-0123456789',
            'admin_allowed_ips' => [],
            'admin_session_seconds' => 1800,
            'admin_max_failures' => 5,
            'admin_lockout_seconds' => 900,
        ],
        'ns_api' => [
            'servers' => ['nms-primary.example.net', 'nms-secondary.example.net'],
            'request_timeout' => 10,
            'connect_timeout' => 5,
            'token_timeout' => 8,
            'total_budget_seconds' => 12,
            'token_cache_dir' => $GLOBALS['CB_TEST_CACHE_DIR'],
            'credentials' => [
                'client_id' => 'cid',
                'client_secret' => 'secret',
                'username' => 'user',
                'password' => 'pass',
            ],
        ],
        'callback' => [
            'default_lookup_minutes' => 30,
            'max_lookup_minutes' => 1440,
            'aa_cache_seconds' => 0,
            'fallback_extension' => '',
            'default_call_decider_extension' => '',
            'default_callback_aa_extension' => '',
            'default_auto_attendant_extension' => '',
            'default_message_url' => 'default',
            'default_excluded_extensions' => '',
            'public_url' => '',
            'legacy_domain' => '',
            'legacy_audio' => 'jewishcallbackivr2.wav',
            'legacy_gather_timeout' => 5,
        ],
        'domains' => [
            'dir' => $GLOBALS['CB_TEST_DOMAINS_DIR'],
            'audit_log' => true,
        ],
    ];
    return array_replace_recursive($config, $overrides);
}

function t_aa_fixture(array $overrides = []): array
{
    $options = [
        'option-0' => ['destination-user' => '9000'], // callback AA
        'option-1' => ['destination-user' => '9001'], // call decider
        'option-2' => ['destination-user' => '9002'], // auto attendant
        'option-3' => ['destination-user' => ''],     // lookup window
        'option-4' => ['audio' => ['file-access-url' => 'default']],
        'option-5' => ['audio' => ['file-script-text' => '']],
    ];
    return ['auto-attendant' => array_replace_recursive($options, $overrides)];
}

/**
 * Run one handler the way index.php would, returning the emitted document.
 */
function t_run(string $mode, array $request, array $config, string $apiKey = 'test-key', bool $https = true): string
{
    $_GET = [];
    $_POST = [];
    foreach ($request as $key => $value) {
        $_GET[$key] = $value;
    }
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = '10.1.2.3';
    $_SERVER['HTTP_HOST'] = 'cb.example.com';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    if ($https) {
        $_SERVER['HTTPS'] = 'on';
    } else {
        unset($_SERVER['HTTPS']);
    }

    $GLOBALS['CB_CONFIG'] = $config;
    $GLOBALS['CB_OUTPUT_DONE'] = false;

    $captured = '';
    $GLOBALS['CB_EMIT_HANDLER'] = function ($document) use (&$captured) {
        $captured .= $document;
    };

    try {
        if ($mode === 'gather') {
            run_gather_handler($config, $apiKey);
        } else {
            run_callback($config, $apiKey);
        }
    } finally {
        $GLOBALS['CB_EMIT_HANDLER'] = null;
    }

    return $captured;
}

function t_http_calls(string $needle): array
{
    return array_values(array_filter($GLOBALS['CB_TEST_HTTP_CALLS'], function ($call) use ($needle) {
        return strpos($call['url'], $needle) !== false;
    }));
}

function t_last_http_url(string $needle): string
{
    $calls = t_http_calls($needle);
    return empty($calls) ? '' : (string) end($calls)['url'];
}

function t_cdr_query(string $param): array
{
    foreach (array_reverse($GLOBALS['CB_TEST_HTTP_CALLS']) as $call) {
        if (strpos($call['url'], '/cdrs') === false) {
            continue;
        }
        $query = t_query_of($call['url']);
        if (isset($query[$param])) {
            return $query;
        }
    }
    return [];
}

function t_query_of(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    return $query;
}

function t_reset_cache(): void
{
    foreach (glob($GLOBALS['CB_TEST_CACHE_DIR'] . '/*') ?: [] as $file) {
        @unlink($file);
    }
}

$GLOBALS['CB_TEST_CACHE_DIR'] = sys_get_temp_dir() . '/callback2-tests-' . getmypid();
@mkdir($GLOBALS['CB_TEST_CACHE_DIR'], 0700, true);
t_reset_cache();

$GLOBALS['CB_TEST_DOMAINS_DIR'] = sys_get_temp_dir() . '/callback2-domains-' . getmypid();
@mkdir($GLOBALS['CB_TEST_DOMAINS_DIR'], 0700, true);
foreach (glob($GLOBALS['CB_TEST_DOMAINS_DIR'] . '/*') ?: [] as $file) {
    @unlink($file);
}

/* -------------------------------------------------------------------------
 * 1. Input sanitising helpers
 * ---------------------------------------------------------------------- */

t_section('1. Sanitising helpers');

t_same('+14155551234', cb_phone('+14155551234'), 'phone accepts +E.164');
t_same('4155551234', cb_normalize_number('+14155551234', 'cb.example.com'), 'normalizer strips + and leading country code');
t_same('sip:2002@cb.example.com', cb_normalize_number('2002', 'cb.example.com'), 'normalizer expands short extensions');
t_same('', cb_phone('1234 OR 1=1'), 'phone rejects SQL-ish payload');
t_same('', cb_phone('sip:100@x.com'), 'phone rejects sip URI (handled elsewhere)');
t_same('', cb_domain('evil.com<script>'), 'domain rejects markup');
t_same('', cb_domain("good.com\r\nX-Injected: 1"), 'domain rejects CRLF injection');
t_same('', cb_domain('../etc/passwd'), 'domain rejects path traversal');
t_same('11123.widetelco', cb_domain('11123.WideTelco'), 'domain lowercases NetSapiens domains');
t_same('', cb_user_token('../../etc/passwd'), 'user token rejects traversal');
t_same('1234', cb_user_token('1234'), 'user token accepts extensions');
t_same('', cb_extension("100\n200"), 'extension rejects newlines');
t_same('1234', cb_extension('1234'), 'extension accepts digits');
t_same('', cb_sip_uri('<script>', 'cb.example.com'), 'sip uri rejects markup');
t_same('', cb_sip_uri('100 200', 'cb.example.com'), 'sip uri rejects spaces in the user part');
t_same('', cb_sip_uri_local('sip:2002@evil.example.net', 'cb.example.com'), 'local sip uri rejects foreign hosts');
t_same('sip:2002@cb.example.com', cb_sip_uri_local('sip:2002@cb.example.com', 'cb.example.com'), 'local sip uri accepts our own domain');
t_same('sip:1234@cb.example.com', cb_sip_uri('1234', 'cb.example.com'), 'sip uri builds from extension');
t_same('sip:1234@other.net', cb_sip_uri('sip:1234@other.net', 'cb.example.com'), 'sip uri keeps a valid explicit host');
t_same('', cb_sip_uri('sip:1234@bad host', 'cb.example.com'), 'sip uri rejects invalid host');
t_same('Front Desk', cb_clean_text("Front\r\nDesk\x00"), 'clean text strips control characters');
t_same('&lt;script&gt;', cb_xml('<script>'), 'xml escaping');
t_same('', cb_digits('12a'), 'digits rejects letters');
t_same('123456', cb_digits('123456', 6), 'digits enforces length');

/* -------------------------------------------------------------------------
 * 2. Lookup window parsing (default 30 minutes)
 * ---------------------------------------------------------------------- */

t_section('2. Lookup window parsing');

t_same(30, cb_parse_lookup_minutes('', 30), 'empty -> default 30 minutes');
t_same(30, cb_parse_lookup_minutes(null, 30), 'null -> default 30 minutes');
t_same(45, cb_parse_lookup_minutes('45', 30), 'bare 45 -> 45 minutes');
t_same(45, cb_parse_lookup_minutes('45m', 30), 'suffix m -> minutes');
t_same(120, cb_parse_lookup_minutes('2h', 30), 'suffix h -> hours');
t_same(360, cb_parse_lookup_minutes('6', 30), 'bare 6 -> legacy hours (360 minutes)');
t_same(240, cb_parse_lookup_minutes('-4', 30), 'legacy -4 -> 4 hours');
t_same(240, cb_parse_lookup_minutes('User -4', 30), 'legacy "User -4" -> 4 hours');
t_same(1440, cb_parse_lookup_minutes('5000', 30), 'clamped to max_lookup_minutes');
t_same(30, cb_parse_lookup_minutes('garbage', 30), 'garbage -> default');
t_same(720, cb_parse_lookup_minutes('12', 30), 'bare 12 -> 12 hours (legacy boundary)');
t_same(13, cb_parse_lookup_minutes('13', 30), 'bare 13 -> 13 minutes');

/* -------------------------------------------------------------------------
 * 3. Prompt identifier + API key validation + IP allow-list
 * ---------------------------------------------------------------------- */

t_section('3. Identifiers, API key, IP allow-list');

t_same('120001', cb_generate_prompt_identifier('12'), 'prompt identifier for 2 char user');
t_same('123001', cb_generate_prompt_identifier('123'), 'prompt identifier for 3 char user');
t_same('123401', cb_generate_prompt_identifier('1234'), 'prompt identifier for 4 char user');
t_same('123451', cb_generate_prompt_identifier('12345'), 'prompt identifier for longer user');

$keyConfig = ['security' => ['api_key' => '0123456789abcdef0123456789abcdef']];
t_ok(cb_api_key_valid('0123456789abcdef0123456789abcdef', $keyConfig), 'api key: correct key accepted');
t_ok(!cb_api_key_valid('0123456789abcdef0123456789abcdee', $keyConfig), 'api key: wrong key rejected');
t_ok(!cb_api_key_valid('', $keyConfig), 'api key: empty rejected');
t_ok(!cb_api_key_valid('0123456789abcdef0123456789abcdef', ['security' => ['api_key' => 'short']]), 'api key: too-short configured key rejected');

t_ok(cb_ip_allowed('10.1.2.3', ['10.0.0.0/8']), 'ip: inside CIDR');
t_ok(!cb_ip_allowed('192.168.1.5', ['10.0.0.0/8']), 'ip: outside CIDR');
t_ok(cb_ip_allowed('203.0.113.9', ['203.0.113.9']), 'ip: exact match');
t_ok(cb_ip_allowed('203.0.113.9', []), 'ip: empty allow-list allows any');
t_ok(cb_ip_allowed('10.0.0.1', ['0.0.0.0/0']), 'ip: /0 matches everything');

t_ok(cb_numbers_match('14155551234', '4155551234'), 'numbers match with country code');
t_ok(!cb_numbers_match('4155551234', '4155559999'), 'numbers do not match');
t_ok(!cb_numbers_match('', ''), 'empty numbers never match');

/* -------------------------------------------------------------------------
 * 4. Main callback flow
 * ---------------------------------------------------------------------- */

t_section('4. Main callback flow');

// 4a. No caller number -> straight to the call decider, no CDR lookups.
cb_test_reset_http();
t_reset_cache();
$GLOBALS['T']['aa'] = t_aa_fixture();
$out = t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsDnis' => '5551000',
], t_config());
t_same('<Response><Forward>sip:9001@cb.example.com</Forward></Response>', $out, '4a. empty NmsAni -> forward to call decider');
t_well_formed($out, '4a.');
t_ok(empty(t_http_calls('/cdrs')), '4a. no CDR lookup without a caller number');

// 4b. Recent conversation match -> gather with say-as digits.
cb_test_reset_http();
$GLOBALS['T']['aa'] = t_aa_fixture();
$GLOBALS['T']['cdr_caller'] = [
    ['call-term-user' => '9000', 'call-talking-duration-seconds' => 30], // AA leg, ordinal missing -> skipped
    ['call-term-user' => '1234', 'call-leg-ordinal-index' => '1', 'call-talking-duration-seconds' => 12],
];
$out = t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '4155551234',
    'NmsDnis' => '5551000',
], t_config());
t_contains('<Gather numDigits="6" timeout="5"', $out, '4b. gather produced');
t_contains('<say-as interpret-as="digits">1234</say-as>', $out, '4b. say-as digits for the extension');
t_contains('action="https://cb.example.com/index.php?action=gather&amp;did=1234&amp;cdext=9001&amp;domain=cb.example.com&amp;apikey=test-key"', $out, '4b. action URL carries did/cdext/domain/apikey');
t_well_formed($out, '4b.');
t_ok(strpos($out, '&amp;apikey=') !== false, '4b. API key is XML-escaped in the action URL');

// 4c. Lookup window is 30 minutes by default.
$cdrUrl = t_last_http_url('/cdrs');
$query = t_query_of($cdrUrl);
t_ok(isset($query['datetime-start'], $query['datetime-end']), '4c. CDR request has a datetime window');
t_same(1800, strtotime($query['datetime-end']) - strtotime($query['datetime-start']), '4c. default window is exactly 30 minutes');
t_same('4155551234', $query['caller'] ?? '', '4c. caller search uses the sanitised ANI');

// 4d. Option 3 overrides the window ("90" -> 90 minutes).
cb_test_reset_http();
$GLOBALS['T']['aa'] = t_aa_fixture(['option-3' => ['destination-user' => '90']]);
$GLOBALS['T']['cdr_caller'] = [];
$GLOBALS['T']['cdr_dialled'] = [];
t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '4155551234',
    'NmsDnis' => '5551000',
], t_config());
$query = t_query_of(t_last_http_url('/cdrs'));
t_same(5400, strtotime($query['datetime-end']) - strtotime($query['datetime-start']), '4d. option 3 "90" -> 90 minute window');

// 4e. Recent conversation excluded by option 5 -> no gather, fall through.
cb_test_reset_http();
$GLOBALS['T']['aa'] = t_aa_fixture(['option-5' => ['audio' => ['file-script-text' => '1234']]]);
$GLOBALS['T']['cdr_caller'] = [
    ['call-term-user' => '1234', 'call-leg-ordinal-index' => '1', 'call-talking-duration-seconds' => 12],
];
$GLOBALS['T']['cdr_dialled'] = [];
$out = t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '4155551234',
    'NmsDnis' => '5551000',
], t_config());
t_same('<Response><Forward>sip:9001@cb.example.com</Forward></Response>', $out, '4e. excluded extension is not offered');

// 4f. Main (dialled) lookup match with a long call -> reconnect prompt.
cb_test_reset_http();
$GLOBALS['T']['aa'] = t_aa_fixture();
$GLOBALS['T']['cdr_caller'] = [];
$GLOBALS['T']['cdr_dialled'] = [[
    'call-orig-caller-id' => '5551000',
    'call-orig-user' => '2002',
    'call-orig-from-name' => 'Front Desk & <Bar>',
    'call-talking-duration-seconds' => '42',
]];
$out = t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '4155551234',
    'NmsDnis' => '5551000',
], t_config());
t_contains('Would you like to reconnect to Front Desk &amp; &lt;Bar&gt;?', $out, '4f. reconnect prompt with escaped name');
t_not_contains('<Bar>', $out, '4f. raw name markup is escaped');
t_contains('did=2002', $out, '4f. gather targets the originating user');
t_well_formed($out, '4f.');

// 4g. Main lookup with a short call -> "Are you returning" (+ country code tolerance).
cb_test_reset_http();
$GLOBALS['T']['aa'] = t_aa_fixture();
$GLOBALS['T']['cdr_dialled'] = [[
    'call-orig-caller-id' => '14155551000', // leading country code -> still matches the 10 digit DID
    'call-orig-user' => '2002',
    'call-orig-from-name' => 'Sales',
    'call-talking-duration-seconds' => '0',
]];
$out = t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '4155551234',
    'NmsDnis' => '4155551000',
], t_config());
t_contains('Are you returning Sales call?', $out, '4g. missed-call wording');
t_well_formed($out, '4g.');

// 4h. Main lookup on a different DID -> no match.
cb_test_reset_http();
$GLOBALS['T']['aa'] = t_aa_fixture();
$GLOBALS['T']['cdr_dialled'] = [[
    'call-orig-caller-id' => '5559999',
    'call-orig-user' => '2002',
    'call-orig-from-name' => 'Sales',
    'call-talking-duration-seconds' => '42',
]];
$out = t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '4155551234',
    'NmsDnis' => '5551000',
], t_config());
t_same('<Response><Forward>sip:9001@cb.example.com</Forward></Response>', $out, '4h. unrelated CDR -> call decider');

// 4i. Excluded extension in the main lookup -> call decider.
cb_test_reset_http();
$GLOBALS['T']['aa'] = t_aa_fixture(['option-5' => ['audio' => ['file-script-text' => '2002']]]);
$GLOBALS['T']['cdr_dialled'] = [[
    'call-orig-caller-id' => '5551000',
    'call-orig-user' => '2002',
    'call-orig-from-name' => 'Sales',
    'call-talking-duration-seconds' => '42',
]];
$out = t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '4155551234',
    'NmsDnis' => '5551000',
], t_config());
t_same('<Response><Forward>sip:9001@cb.example.com</Forward></Response>', $out, '4i. excluded origination user -> call decider');

// 4j. Short internal caller -> sip:user@domain used for lookups.
cb_test_reset_http();
$GLOBALS['T']['aa'] = t_aa_fixture();
$GLOBALS['T']['cdr_caller'] = [];
$GLOBALS['T']['cdr_dialled'] = [];
t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '2002',
    'NmsDnis' => '5551000',
], t_config());
$query = t_cdr_query('caller');
t_same('sip:2002@cb.example.com', $query['caller'] ?? '', '4j. internal caller searched as sip URI');

// 4k. NmsAni sent as a sip URI from the same domain.
cb_test_reset_http();
$GLOBALS['T']['aa'] = t_aa_fixture();
t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => 'sip:2002@cb.example.com',
    'NmsDnis' => '5551000',
], t_config());
$query = t_cdr_query('caller');
t_same('sip:2002@cb.example.com', $query['caller'] ?? '', '4k. sip: ANI from the same domain accepted');

// 4l. 11 digit ANI gets the leading 1 stripped.
cb_test_reset_http();
$GLOBALS['T']['aa'] = t_aa_fixture();
t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '14155551234',
    'NmsDnis' => '5551000',
], t_config());
$query = t_cdr_query('caller');
t_same('4155551234', $query['caller'] ?? '', '4l. leading 1 stripped');

// 4m. AA prompt path + AA cache behaviour.
cb_test_reset_http();
t_reset_cache();
$GLOBALS['T']['aa'] = t_aa_fixture();
$config = t_config(['callback' => ['aa_cache_seconds' => 60]]);
t_run('callback', ['AccountUser' => '1234', 'AccountDomain' => 'cb.example.com', 'NmsAni' => '4155551234', 'NmsDnis' => '5551000'], $config);
t_same(1, count(t_http_calls('/autoattendants/')), '4m. AA config fetched once');
t_contains('/users/1234/autoattendants/Prompt_123401', t_last_http_url('/autoattendants/'), '4m. prompt identifier path');
cb_test_reset_http();
t_run('callback', ['AccountUser' => '1234', 'AccountDomain' => 'cb.example.com', 'NmsAni' => '4155551234', 'NmsDnis' => '5551000'], $config);
t_same(0, count(t_http_calls('/autoattendants/')), '4m. second call served from the AA cache');

// 4n. Explicit callback.public_url is used for the Gather action.
cb_test_reset_http();
$GLOBALS['T']['aa'] = t_aa_fixture();
$GLOBALS['T']['cdr_caller'] = [
    ['call-term-user' => '1234', 'call-leg-ordinal-index' => '1', 'call-talking-duration-seconds' => 12],
];
$out = t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '4155551234',
    'NmsDnis' => '5551000',
], t_config(['callback' => ['public_url' => 'https://cb.example.com/routing/index.php']]));
t_contains('action="https://cb.example.com/routing/index.php?action=gather', $out, '4n. configured public_url used for the action');
t_well_formed($out, '4n.');

// 4o. Without TLS the derived action URL stays http.
cb_test_reset_http();
$GLOBALS['T']['aa'] = t_aa_fixture();
$GLOBALS['T']['cdr_caller'] = [
    ['call-term-user' => '1234', 'call-leg-ordinal-index' => '1', 'call-talking-duration-seconds' => 12],
];
$out = t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '4155551234',
    'NmsDnis' => '5551000',
], t_config(), 'test-key', false);
t_contains('action="http://cb.example.com/index.php?action=gather', $out, '4o. derived action URL follows the request scheme');

/* -------------------------------------------------------------------------
 * 5. Failover
 * ---------------------------------------------------------------------- */

t_section('5. Failover');

// 5a. Primary API server down -> secondary answers.
cb_test_reset_http();
t_reset_cache();
$GLOBALS['T']['aa'] = t_aa_fixture();
$GLOBALS['T']['fail_api_servers'] = ['nms-primary.example.net'];
$GLOBALS['T']['cdr_caller'] = [
    ['call-term-user' => '1234', 'call-leg-ordinal-index' => '1', 'call-talking-duration-seconds' => 9],
];
$out = t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '4155551234',
    'NmsDnis' => '5551000',
], t_config());
t_contains('<say-as interpret-as="digits">1234</say-as>', $out, '5a. call succeeds on the secondary API server');
t_ok(count(t_http_calls('nms-primary')) > 0, '5a. primary server was attempted first');
t_ok(count(t_http_calls('nms-secondary')) > 0, '5a. secondary server was used');
t_well_formed($out, '5a.');

// 5b. Every API server down -> configured fallback extension.
cb_test_reset_http();
t_reset_cache();
$GLOBALS['T']['aa'] = t_aa_fixture();
$GLOBALS['T']['fail_api_servers'] = ['nms-primary.example.net', 'nms-secondary.example.net'];
$out = t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '4155551234',
    'NmsDnis' => '5551000',
], t_config(['callback' => ['fallback_extension' => '9111']]));
t_same('<Response><Forward>sip:9111@cb.example.com</Forward></Response>', $out, '5b. total API failure -> fallback extension');
t_well_formed($out, '5b.');

// 5c. All servers down, OAuth also failing, no fallback configured -> polite hangup.
cb_test_reset_http();
t_reset_cache();
$GLOBALS['T']['aa'] = t_aa_fixture();
$GLOBALS['T']['fail_api_servers'] = ['nms-primary.example.net', 'nms-secondary.example.net'];
$GLOBALS['T']['fail_token_servers'] = ['nms-primary.example.net', 'nms-secondary.example.net'];
$out = t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '4155551234',
    'NmsDnis' => '5551000',
], t_config());
t_contains('<Say', $out, '5c. no API and no fallback -> spoken apology');
t_contains('<Hangup/>', $out, '5c. call is hung up cleanly');
t_well_formed($out, '5c.');

// 5d. Expired token (401) -> one refresh + retry on the same server.
cb_test_reset_http();
t_reset_cache();
$GLOBALS['T']['aa'] = t_aa_fixture();
$GLOBALS['T']['unauthorized_attempts'] = 1;
$out = t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '4155551234',
    'NmsDnis' => '5551000',
], t_config());
t_same(2, count($GLOBALS['T']['token_calls']), '5d. token refreshed exactly once after a 401');
t_contains('<Forward>sip:9001@cb.example.com</Forward>', $out, '5d. request succeeded after the token refresh');

// 5e. Token is cached across requests (no second token call).
cb_test_reset_http();
t_reset_cache();
$GLOBALS['T']['aa'] = t_aa_fixture();
$config = t_config();
t_run('callback', ['AccountUser' => '1234', 'AccountDomain' => 'cb.example.com', 'NmsAni' => '4155551234', 'NmsDnis' => '5551000'], $config);
t_same(1, count($GLOBALS['T']['token_calls']), '5e. first request fetches a token');
cb_test_reset_http();
$GLOBALS['T']['aa'] = t_aa_fixture();
t_run('callback', ['AccountUser' => '1234', 'AccountDomain' => 'cb.example.com', 'NmsAni' => '4155551234', 'NmsDnis' => '5551000'], $config);
t_same(0, count($GLOBALS['T']['token_calls']), '5e. second request reuses the cached token');

/* -------------------------------------------------------------------------
 * 6. Malicious / malformed input
 * ---------------------------------------------------------------------- */

t_section('6. Malicious and malformed input');

// 6a. Invalid domain -> hangup, zero API calls.
cb_test_reset_http();
$out = t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'evil.com<script>',
    'NmsAni' => '4155551234',
    'NmsDnis' => '5551000',
], t_config());
t_same('<Response><Hangup/></Response>', $out, '6a. invalid domain -> hangup');
t_same(0, count($GLOBALS['CB_TEST_HTTP_CALLS']), '6a. no API traffic for an invalid domain');

// 6b. Path traversal in AccountUser.
cb_test_reset_http();
$GLOBALS['T']['aa'] = t_aa_fixture();
$out = t_run('callback', [
    'AccountUser' => '../../etc/passwd',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '4155551234',
    'NmsDnis' => '5551000',
], t_config(['callback' => ['default_call_decider_extension' => '9001']]));
t_same(0, count(t_http_calls('/autoattendants/')), '6b. traversal user never reaches the API');
t_not_contains('etc/passwd', implode(' ', array_column($GLOBALS['CB_TEST_HTTP_CALLS'], 'url')), '6b. traversal string never leaves the app');
t_same('<Response><Forward>sip:9001@cb.example.com</Forward></Response>', $out, '6b. configured default decider used instead');

// 6c. Markup in NmsAni -> rejected, no CDR lookup.
cb_test_reset_http();
$GLOBALS['T']['aa'] = t_aa_fixture();
$out = t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => "'><script>",
    'NmsDnis' => '5551000',
], t_config());
t_same(0, count(t_http_calls('/cdrs')), '6c. invalid ANI -> no CDR lookup');
t_same('<Response><Forward>sip:9001@cb.example.com</Forward></Response>', $out, '6c. forwarded to call decider');

// 6d. Hostile CDR content cannot break the XML.
cb_test_reset_http();
$GLOBALS['T']['aa'] = t_aa_fixture();
$GLOBALS['T']['cdr_dialled'] = [[
    'call-orig-caller-id' => '5551000',
    'call-orig-user' => '2002',
    'call-orig-from-name' => '"><Response><Forward>sip:666@evil.com</Forward></Response>',
    'call-talking-duration-seconds' => '42',
]];
$out = t_run('callback', [
    'AccountUser' => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '4155551234',
    'NmsDnis' => '5551000',
], t_config());
t_not_contains('<Forward>sip:666', $out, '6d. injected forward element is neutralised');
t_contains('&lt;Forward&gt;', $out, '6d. injected markup is present but escaped');
t_well_formed($out, '6d.');
t_same(1, substr_count($out, '<Response>'), '6d. exactly one Response document');

/* -------------------------------------------------------------------------
 * 7. Gather (digit) handler
 * ---------------------------------------------------------------------- */

t_section('7. Gather handler');

$gatherBase = ['domain' => 'cb.example.com', 'cdext' => '9001'];

$out = t_run('gather', $gatherBase + ['did' => '2002', 'Digits' => '1'], t_config());
t_same('<Response><Forward>sip:2002@cb.example.com</Forward></Response>', $out, '7a. digit 1 -> reconnect to did');

$out = t_run('gather', $gatherBase + ['did' => '2002', 'Digits' => '2'], t_config());
t_same('<Response><Forward>sip:9001@cb.example.com</Forward></Response>', $out, '7b. digit 2 -> call decider');

$out = t_run('gather', $gatherBase + ['did' => '2002', 'Digits' => '456'], t_config());
t_same('<Response><Forward>sip:456@cb.example.com</Forward></Response>', $out, '7c. 3+ digits -> dialled extension');

$out = t_run('gather', $gatherBase + ['did' => '2002', 'Digits' => ''], t_config());
t_same('<Response><Forward>sip:9001@cb.example.com</Forward></Response>', $out, '7d. timeout/no digits -> call decider (no dropped call)');

$out = t_run('gather', $gatherBase + ['did' => '2002', 'Digits' => '9'], t_config());
t_same('<Response><Forward>sip:9001@cb.example.com</Forward></Response>', $out, '7e. unexpected digit -> call decider');

$out = t_run('gather', $gatherBase + ['did' => '2002', 'Digits' => '1 OR 1=1'], t_config());
t_same('<Response><Forward>sip:9001@cb.example.com</Forward></Response>', $out, '7f. malformed digits neutralised');

$out = t_run('gather', $gatherBase + ['did' => '../../../etc', 'Digits' => '1'], t_config());
t_same('<Response><Forward>sip:9001@cb.example.com</Forward></Response>', $out, '7g. malformed did falls back to the call decider');

$out = t_run('gather', ['domain' => 'bad domain', 'Digits' => '1'], t_config());
t_same('<Response><Hangup/></Response>', $out, '7h. invalid domain -> hangup');

$out = t_run('gather', ['domain' => 'cb.example.com', 'did' => 'sip:2002@cb.example.com', 'Digits' => '1'], t_config());
t_same('<Response><Forward>sip:2002@cb.example.com</Forward></Response>', $out, '7h2. sip: did accepted when it is inside our domain');

$out = t_run('gather', ['domain' => 'cb.example.com', 'did' => 'sip:2002@evil.example.net', 'cdext' => '9001', 'Digits' => '1'], t_config());
t_same('<Response><Forward>sip:9001@cb.example.com</Forward></Response>', $out, '7h3. foreign sip host refused -> call decider');

$out = t_run('gather', ['domain' => 'cb.example.com', 'did' => '100 200', 'cdext' => '9001', 'Digits' => '1'], t_config());
t_same('<Response><Forward>sip:9001@cb.example.com</Forward></Response>', $out, '7h4. space-containing did refused -> call decider');

$out = t_run('gather', ['domain' => 'cb.example.com', 'Digits' => '1'], t_config());
t_same('<Response><Hangup/></Response>', $out, '7i. no targets at all -> hangup (never broken XML)');
t_well_formed($out, '7i.');

// 7j. Legacy standalone prompt replays once, then stops.
$legacyConfig = t_config(['callback' => ['legacy_domain' => '11123.WideTelco', 'legacy_audio' => 'jewishcallbackivr2.wav']]);
$out = t_run('gather', ['domain' => '11123.WideTelco'], $legacyConfig);
t_contains('<Gather numDigits="1"', $out, '7j. legacy prompt replay gather');
t_contains('<Play>jewishcallbackivr2.wav</Play>', $out, '7j. legacy audio replayed');
t_contains('action="https://cb.example.com/index.php?action=gather&amp;domain=11123.widetelco&amp;apikey=test-key&amp;attempt=1"', $out, '7j. replay action keeps domain + key + attempt');
t_well_formed($out, '7j.');
$out = t_run('gather', ['domain' => '11123.WideTelco', 'attempt' => '1'], $legacyConfig);
t_same('<Response><Hangup/></Response>', $out, '7j. second timeout hangs up (no infinite replay)');

// 7k. Legacy prompt disabled unless configured.
$out = t_run('gather', ['domain' => '11123.WideTelco'], t_config());
t_same('<Response><Hangup/></Response>', $out, '7k. legacy domain ignored when not configured');

/* -------------------------------------------------------------------------
 * 8. Requests without AccountUser
 * ---------------------------------------------------------------------- */

t_section('8. Requests without AccountUser');

cb_test_reset_http();
$GLOBALS['T']['cdr_caller'] = [];
$GLOBALS['T']['cdr_dialled'] = [];
$out = t_run('callback', [
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '4155551234',
    'NmsDnis' => '5551000',
], t_config(['callback' => ['default_call_decider_extension' => '9001']]));
t_same(0, count(t_http_calls('/autoattendants/')), '8a. no AA lookup when AccountUser is missing');
t_ok(count(t_http_calls('/cdrs')) > 0, '8a. CDR lookups still run with the default decider');
t_same('<Response><Forward>sip:9001@cb.example.com</Forward></Response>', $out, '8a. no match -> default decider');

cb_test_reset_http();
$out = t_run('callback', [
    'AccountDomain' => 'cb.example.com',
    'NmsAni' => '4155551234',
    'NmsDnis' => '5551000',
], t_config(['callback' => ['fallback_extension' => '9111']]));
t_same('<Response><Forward>sip:9111@cb.example.com</Forward></Response>', $out, '8b. no AccountUser and no default -> fallback extension');

/* -------------------------------------------------------------------------
 * 9. Response shape for every emitted document
 * ---------------------------------------------------------------------- */

t_section('9. Output well-formedness sweep');

foreach ([
    'forward' => t_run('gather', ['domain' => 'cb.example.com', 'cdext' => '9001', 'Digits' => '2'], t_config()),
    'hangup'  => t_run('gather', ['domain' => 'cb.example.com'], t_config()),
] as $name => $document) {
    t_well_formed($document, '9. ' . $name . ' response');
    t_ok(strpos($document, '<Response>') === 0, '9. ' . $name . ' starts with <Response>');
    t_ok(substr($document, -11) === '</Response>', '9. ' . $name . ' ends with </Response>');
}

/* -------------------------------------------------------------------------
 * 10. Entry point authorisation + routing (index.php logic)
 * ---------------------------------------------------------------------- */

t_section('10. Entry point authorisation and routing');

function t_auth(array $config, array $server = [], array $get = []): array
{
    $savedServer = $_SERVER;
    $savedGet = $_GET;
    $savedPost = $_POST;

    $_GET = $get;
    $_POST = [];
    $_SERVER = array_merge(['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '10.1.2.3'], $server);
    $GLOBALS['CB_CONFIG'] = $config;

    $result = cb_authorize_request($config);

    $_SERVER = $savedServer;
    $_GET = $savedGet;
    $_POST = $savedPost;
    return $result;
}

$apiKey = '0123456789abcdef0123456789abcdef';

$result = t_auth(t_config(), ['HTTP_X_API_KEY' => $apiKey]);
t_ok($result['ok'], '10a. correct X-API-Key header accepted');

$result = t_auth(t_config(), ['HTTP_X_API_KEY' => 'wrong-key-wrong-key-wrong']);
t_same(403, $result['status'], '10b. wrong key -> 403');

$result = t_auth(t_config());
t_same(403, $result['status'], '10c. missing key -> 403');

$result = t_auth(t_config(), [], ['apikey' => $apiKey]);
t_ok($result['ok'], '10d. query parameter key accepted (switch cannot send headers)');

$result = t_auth(t_config(['security' => ['allow_query_key' => false]]), [], ['apikey' => $apiKey]);
t_same(403, $result['status'], '10e. query key refused when allow_query_key is off');

$result = t_auth(t_config(['security' => ['allow_header_key' => false]]), ['HTTP_X_API_KEY' => $apiKey]);
t_same(403, $result['status'], '10f. header key refused when allow_header_key is off');

$result = t_auth(t_config(['security' => ['api_key' => 'tooshort']]), ['HTTP_X_API_KEY' => 'tooshort']);
t_same(500, $result['status'], '10g. short configured key -> 500 (fail closed)');

$result = t_auth(t_config(), ['HTTP_X_API_KEY' => $apiKey, 'REQUEST_METHOD' => 'PUT']);
t_same(405, $result['status'], '10h. disallowed HTTP method -> 405');

$result = t_auth(t_config(['security' => ['allowed_ips' => ['10.0.0.0/8']]]), ['HTTP_X_API_KEY' => $apiKey]);
t_ok($result['ok'], '10i. request from an allowed CIDR accepted');

$result = t_auth(t_config(['security' => ['allowed_ips' => ['192.168.0.0/16']]]), ['HTTP_X_API_KEY' => $apiKey]);
t_same(403, $result['status'], '10j. request from a non-listed IP -> 403');

$result = t_auth(t_config(['security' => ['require_https' => true]]), ['HTTP_X_API_KEY' => $apiKey]);
t_same(403, $result['status'], '10k. plain HTTP refused when require_https is on');

$result = t_auth(t_config(['security' => ['require_https' => true]]), ['HTTP_X_API_KEY' => $apiKey, 'HTTPS' => 'on']);
t_ok($result['ok'], '10l. HTTPS accepted when require_https is on');

$result = t_auth(t_config(['security' => ['require_https' => true]]), ['HTTP_X_API_KEY' => $apiKey, 'HTTP_X_FORWARDED_PROTO' => 'https']);
t_ok($result['ok'], '10m. TLS-terminating proxy honoured via X-Forwarded-Proto');

t_same('callback', cb_resolve_action(''), '10n. default action is callback');
t_same('callback', cb_resolve_action('cb'), '10n. cb alias');
t_same('gather', cb_resolve_action('gather'), '10n. gather action');
t_same('gather', cb_resolve_action('callbackfeaturev2'), '10n. legacy callbackfeaturev2 alias');
t_same('gather', cb_resolve_action('Digits'), '10n. action names are case-insensitive');
t_same('', cb_resolve_action('delete-everything'), '10n. unknown action resolves to empty');
t_same('callback', cb_resolve_action(['array' => 'injection']), '10n. array action falls back to callback');

/* -------------------------------------------------------------------------
 * 11. Per-domain configuration store (admin config page backend)
 * ---------------------------------------------------------------------- */

t_section('11. Per-domain configuration store');

$GLOBALS['CB_CONFIG'] = t_config();

t_same(null, cb_domain_config_load('cb.example.com'), '11a. missing config -> null');
t_same([], cb_domain_config_list(), '11b. empty list when nothing is stored');

$v = cb_domain_config_validate('cb.example.com', [
    'enabled'                  => '1',
    'failover_extension'       => '9300',
    'callback_aa_extension'    => '9100',
    'call_decider_extension'   => '9101',
    'auto_attendant_extension' => '9102',
    'lookup_minutes'           => '45',
    'message_url'              => 'default',
    'excluded_extensions'      => '9001, 9002',
]);
t_ok($v['ok'], '11c. valid config passes validation');
t_same([], $v['errors'], '11c. no validation errors');
t_same('9300', $v['config']['failover_extension'], '11c. failover extension stored');
t_same(45, $v['config']['lookup_minutes'], '11c. lookup window stored as int');
t_same('9001,9002', $v['config']['excluded_extensions'], '11c. excluded list normalised');
t_same(true, $v['config']['enabled'], '11c. enabled flag stored');

t_ok(cb_domain_config_save('cb.example.com', $v['config'], 'tester'), '11d. save succeeds');
$loaded = cb_domain_config_load('cb.example.com');
t_same('cb.example.com', $loaded['domain'], '11e. load roundtrip: domain');
t_same('9300', $loaded['failover_extension'], '11e. load roundtrip: failover extension');
t_same(45, $loaded['lookup_minutes'], '11e. load roundtrip: lookup window');
t_same(true, $loaded['enabled'], '11e. load roundtrip: enabled');
t_ok($loaded['updated_at'] !== '', '11e. updated_at recorded');
t_same('tester', $loaded['updated_by'], '11e. updated_by recorded');
t_same(['cb.example.com'], array_keys(cb_domain_config_list()), '11f. list shows the saved domain');

// Validation failures.
$v = cb_domain_config_validate('bad domain!', ['failover_extension' => '9300']);
t_ok(!$v['ok'], '11g. invalid domain rejected');
t_ok(count($v['errors']) > 0, '11g. invalid domain produces an error');

$v = cb_domain_config_validate('cb.example.com', ['lookup_minutes' => '5000']);
t_ok(!$v['ok'], '11h. lookup window above 1440 rejected');

$v = cb_domain_config_validate('cb.example.com', ['lookup_minutes' => 'soon']);
t_ok(!$v['ok'], '11h. non-numeric lookup window rejected');

$v = cb_domain_config_validate('cb.example.com', ['message_url' => 'javascript:alert(1)']);
t_ok(!$v['ok'], '11i. script URL as prompt audio rejected');

$v = cb_domain_config_validate('cb.example.com', ['message_url' => 'https://prompts.example.com/cb.wav']);
t_ok($v['ok'], '11i. https prompt URL accepted');
t_same('https://prompts.example.com/cb.wav', $v['config']['message_url'], '11i. https URL stored verbatim');

$v = cb_domain_config_validate('cb.example.com', ['excluded_extensions' => '9001, <bad>']);
t_ok(!$v['ok'], '11j. invalid excluded extension rejected');

$v = cb_domain_config_validate('cb.example.com', ['failover_extension' => '9100', 'callback_aa_extension' => '9100']);
t_ok(!$v['ok'], '11k. failover == callback AA is a hard error (routing loop)');
t_ok(count($v['errors']) > 0, '11k. loop error reported');

$v = cb_domain_config_validate('cb.example.com', ['failover_extension' => '9101', 'call_decider_extension' => '9101']);
t_ok($v['ok'], '11l. failover == call decider saves (with a warning)');
t_ok(count($v['warnings']) > 0, '11l. loop-risk warning reported');

$v = cb_domain_config_validate('cb.example.com', ['failover_extension' => '90 0']);
t_ok(!$v['ok'], '11m. extension with a space rejected (not a usable forward target)');

// Delete + corrupt file + audit log.
t_ok(cb_domain_config_delete('cb.example.com', 'tester'), '11n. delete succeeds');
t_same(null, cb_domain_config_load('cb.example.com'), '11n. config gone after delete');
t_ok(!cb_domain_config_delete('cb.example.com', 'tester'), '11n. deleting a missing config fails');

file_put_contents($GLOBALS['CB_TEST_DOMAINS_DIR'] . '/broken.example.com.json', '{not json');
t_same(null, cb_domain_config_load('broken.example.com'), '11o. corrupt config -> null');
@unlink($GLOBALS['CB_TEST_DOMAINS_DIR'] . '/broken.example.com.json');

$auditFile = $GLOBALS['CB_TEST_DOMAINS_DIR'] . '/audit.log';
t_ok(is_file($auditFile), '11p. audit log exists after save/delete');
$auditLines = is_file($auditFile) ? file($auditFile, FILE_IGNORE_NEW_LINES) : [];
t_ok(count($auditLines) >= 2, '11p. audit log records save and delete');
t_contains('"action":"save"', (string) ($auditLines[0] ?? ''), '11p. audit records the save');
t_contains('"action":"delete"', (string) (end($auditLines) ?: ''), '11p. audit records the delete');
t_contains('"domain":"cb.example.com"', (string) ($auditLines[0] ?? ''), '11p. audit records the domain');

/* -------------------------------------------------------------------------
 * 12. Settings resolution (domain config > AA options > defaults)
 * ---------------------------------------------------------------------- */

t_section('12. Settings resolution precedence');

$tDefaults = [
    'failover_extension' => '',
    'callback_aa'        => '8000',
    'call_decider'       => '8001',
    'auto_attendant'     => '8002',
    'lookup_minutes'     => 30,
    'message_url'        => 'default',
    'excluded'           => cb_excluded_set('7000'),
];
$tAaOptions = [
    'callback_aa'    => '9100',
    'call_decider'   => '9101',
    'auto_attendant' => '9102',
    'lookup_minutes' => 60,
    'message_url'    => 'aa-prompt.wav',
    'excluded'       => cb_excluded_set('9001'),
];
$tDomainCfg = [
    'domain'                   => 'cb.example.com',
    'enabled'                  => true,
    'failover_extension'       => '9300',
    'callback_aa_extension'    => '9100',
    'call_decider_extension'   => '9101',
    'auto_attendant_extension' => '',
    'lookup_minutes'           => 45,
    'message_url'              => '',
    'excluded_extensions'      => '9050',
];

// Legacy: no domain config -> AA options win over defaults.
$settings = cb_resolve_settings(null, $tAaOptions, $tDefaults);
t_same('9100', $settings['callback_aa'], '12a. AA option callback AA used');
t_same('9101', $settings['call_decider'], '12a. AA option call decider used');
t_same(60, $settings['lookup_minutes'], '12a. AA option lookup window used');
t_same('aa-prompt.wav', $settings['message_url'], '12a. AA option message used');
t_ok(isset($settings['excluded']['9001']), '12a. AA option exclusions used');
t_same(true, $settings['enabled'], '12a. enabled by default without a domain config');
t_same(false, $settings['has_domain_config'], '12a. has_domain_config false');
t_same('aa-options', $settings['sources']['lookup_minutes'], '12a. source recorded');

// Neither -> defaults.
$settings = cb_resolve_settings(null, null, $tDefaults);
t_same('8001', $settings['call_decider'], '12b. defaults used when nothing else exists');
t_same(30, $settings['lookup_minutes'], '12b. default lookup window used');
t_ok(isset($settings['excluded']['7000']), '12b. default exclusions used');

// Domain config present (real flow: AA options are not even fetched).
$settings = cb_resolve_settings($tDomainCfg, null, $tDefaults);
t_same('9300', $settings['failover_extension'], '12c. domain failover extension wins');
t_same('9100', $settings['callback_aa'], '12c. domain callback AA wins');
t_same('9101', $settings['call_decider'], '12c. domain call decider wins');
t_same('8002', $settings['auto_attendant'], '12c. empty domain field falls back to defaults');
t_same(45, $settings['lookup_minutes'], '12c. domain lookup window wins');
t_same('default', $settings['message_url'], '12c. empty domain message falls back to defaults');
t_ok(isset($settings['excluded']['9050']), '12c. domain exclusions win');
t_ok(!isset($settings['excluded']['7000']), '12c. domain exclusions replace defaults');
t_same(true, $settings['has_domain_config'], '12c. has_domain_config true');
t_same('domain-config', $settings['sources']['failover_extension'], '12c. source recorded');

// Disabled toggle.
$tOff = $tDomainCfg;
$tOff['enabled'] = false;
$settings = cb_resolve_settings($tOff, null, $tDefaults);
t_same(false, $settings['enabled'], '12d. disabled toggle respected');

/* -------------------------------------------------------------------------
 * 13. Hand-off (failover) target resolution
 * ---------------------------------------------------------------------- */

t_section('13. Hand-off target resolution');

$settings = cb_resolve_settings($tDomainCfg, null, $tDefaults);
t_same('9300', cb_handoff_extension($settings), '13a. domain failover extension preferred');
t_same('sip:9300@cb.example.com', cb_handoff_target('cb.example.com', $settings), '13a. hand-off target builds a sip URI');

$noFailover = $settings;
$noFailover['failover_extension'] = '';
t_same('sip:9101@cb.example.com', cb_handoff_target('cb.example.com', $noFailover), '13b. falls back to the call decider');

// Feature disabled: main AA preferred over the decider (avoids re-entering the feature).
t_same('sip:8002@cb.example.com', cb_handoff_target('cb.example.com', $noFailover, true), '13c. disabled prefers the main AA over the decider');
t_same('sip:9300@cb.example.com', cb_handoff_target('cb.example.com', $settings, true), '13c. failover extension still first when disabled');

// Global fallback last; nothing configured -> empty (caller gets Say+hangup).
$bare = ['failover_extension' => '', 'call_decider' => '', 'auto_attendant' => ''];
$GLOBALS['CB_CONFIG'] = t_config(['callback' => ['fallback_extension' => '9111']]);
t_same('sip:9111@cb.example.com', cb_handoff_target('cb.example.com', $bare), '13d. global fallback used last');
$GLOBALS['CB_CONFIG'] = t_config(['callback' => ['fallback_extension' => '']]);
t_same('', cb_handoff_target('cb.example.com', $bare), '13d. nothing configured -> empty target');
t_same('', cb_handoff_target('bad domain!', $settings), '13d. invalid domain -> empty target');

/* -------------------------------------------------------------------------
 * 14. Callback flow driven by a domain config
 * ---------------------------------------------------------------------- */

t_section('14. Callback flow driven by the domain config');

$domainConfig = t_config();
$GLOBALS['CB_CONFIG'] = $domainConfig;

$v = cb_domain_config_validate('cb.example.com', [
    'enabled'                  => '1',
    'failover_extension'       => '9300',
    'callback_aa_extension'    => '9100',
    'call_decider_extension'   => '9101',
    'auto_attendant_extension' => '9102',
    'lookup_minutes'           => '45',
    'message_url'              => 'default',
    'excluded_extensions'      => '9001',
]);
cb_domain_config_save('cb.example.com', $v['config'], 'tester');

$request = [
    'AccountUser'   => '1234',
    'AccountDomain' => 'cb.example.com',
    'NmsAni'        => '4155551234',
    'NmsDnis'       => '5551000',
];

// 14a. Config present -> AA options are NOT fetched; config values drive the flow.
cb_test_reset_http();
t_reset_cache();
$GLOBALS['T']['aa'] = t_aa_fixture(); // would be used in legacy mode
$GLOBALS['T']['cdr_caller'] = [
    ['call-leg-ordinal-index' => '1', 'call-term-user' => '2002', 'call-talking-duration-seconds' => '30'],
];
$out = t_run('callback', $request, $domainConfig);
t_same(0, count(t_http_calls('/autoattendants/')), '14a. AA options not fetched when a domain config exists (one API call saved)');
t_ok(count(t_http_calls('/cdrs')) > 0, '14a. CDR lookup still runs');
t_contains('cdext=9300', $out, '14a. gather action carries the failover extension as hand-off target');
t_contains('<say-as interpret-as="digits">2002</say-as>', $out, '14a. reconnect prompt for the matched extension');
$query = t_query_of(t_last_http_url('/cdrs'));
t_same(45 * 60, strtotime($query['datetime-end']) - strtotime($query['datetime-start']), '14a. CDR window comes from the domain config (45 min)');
t_well_formed($out, '14a.');

// 14b. Disabled -> immediate hand-off to the failover extension, zero API calls.
cb_test_reset_http();
$off = $v['config'];
$off['enabled'] = false;
cb_domain_config_save('cb.example.com', $off, 'tester');
$out = t_run('callback', $request, $domainConfig);
t_same('<Response><Forward>sip:9300@cb.example.com</Forward></Response>', $out, '14b. disabled -> forward to the failover extension');
t_same(0, count($GLOBALS['CB_TEST_HTTP_CALLS']), '14b. no API calls at all when disabled');

// 14c. Disabled without failover -> main AA (not the decider, to avoid re-entry).
cb_test_reset_http();
$off2 = $v['config'];
$off2['enabled'] = false;
$off2['failover_extension'] = '';
cb_domain_config_save('cb.example.com', $off2, 'tester');
$out = t_run('callback', $request, $domainConfig);
t_same('<Response><Forward>sip:9102@cb.example.com</Forward></Response>', $out, '14c. disabled without failover -> main AA');

// 14d. Total API outage with a domain config -> failover extension.
cb_test_reset_http();
t_reset_cache();
cb_domain_config_save('cb.example.com', $v['config'], 'tester');
$GLOBALS['T']['fail_api_servers'] = ['nms-primary.example.net', 'nms-secondary.example.net'];
$GLOBALS['T']['fail_token_servers'] = ['nms-primary.example.net', 'nms-secondary.example.net'];
$out = t_run('callback', $request, $domainConfig);
t_same('<Response><Forward>sip:9300@cb.example.com</Forward></Response>', $out, '14d. total API outage -> domain failover extension');
$GLOBALS['T']['fail_api_servers'] = [];
$GLOBALS['T']['fail_token_servers'] = [];

// 14e. Excluded extension from the config -> hand-off target.
cb_test_reset_http();
t_reset_cache();
$GLOBALS['T']['cdr_caller'] = [];
$GLOBALS['T']['cdr_dialled'] = [
    [
        'call-orig-caller-id'            => '5551000',
        'call-orig-user'                 => '9001',
        'call-orig-from-name'            => 'Excluded User',
        'call-talking-duration-seconds'  => '30',
    ],
];
$out = t_run('callback', $request, $domainConfig);
t_same('<Response><Forward>sip:9300@cb.example.com</Forward></Response>', $out, '14e. excluded extension (from config) -> hand-off target');

// 14f. No match with a domain config -> failover extension (downstream hand-off).
cb_test_reset_http();
$GLOBALS['T']['cdr_dialled'] = [];
$out = t_run('callback', $request, $domainConfig);
t_same('<Response><Forward>sip:9300@cb.example.com</Forward></Response>', $out, '14f. no match -> domain failover extension (downstream hand-off)');

// 14g. Legacy behaviour is untouched without a domain config: decider is the hand-off.
cb_test_reset_http();
t_reset_cache();
cb_domain_config_delete('cb.example.com', 'tester');
$GLOBALS['T']['aa'] = t_aa_fixture();
$GLOBALS['T']['cdr_caller'] = [];
$GLOBALS['T']['cdr_dialled'] = [];
$out = t_run('callback', $request, $domainConfig);
t_same('<Response><Forward>sip:9001@cb.example.com</Forward></Response>', $out, '14g. no domain config -> legacy call decider hand-off');
t_ok(count(t_http_calls('/autoattendants/')) > 0, '14g. legacy path still fetches the AA options');

/* -------------------------------------------------------------------------
 * 15. Admin interface authorisation, throttle and CSRF
 * ---------------------------------------------------------------------- */

t_section('15. Admin authorisation, throttle and CSRF');

function t_admin_auth(array $config, array $server = [], array $get = []): array
{
    $savedServer = $_SERVER;
    $savedGet = $_GET;
    $savedPost = $_POST;

    $_GET = $get;
    $_POST = [];
    $_SERVER = array_merge(['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '10.1.2.3'], $server);
    $GLOBALS['CB_CONFIG'] = $config;

    $result = cb_admin_authorize($config);

    $_SERVER = $savedServer;
    $_GET = $savedGet;
    $_POST = $savedPost;
    return $result;
}

$adminConfig = t_config();

$result = t_admin_auth($adminConfig);
t_ok($result['ok'], '15a. admin interface available when admin_key is configured');

$result = t_admin_auth(t_config(['security' => ['admin_key' => '']]));
t_same(404, $result['status'], '15b. no admin_key -> 404 (panel hidden, fail closed)');

$result = t_admin_auth(t_config(['security' => ['admin_key' => 'short']]));
t_same(404, $result['status'], '15c. short admin_key -> 404 (fail closed)');

$result = t_admin_auth($adminConfig, ['REQUEST_METHOD' => 'PUT']);
t_same(405, $result['status'], '15d. PUT -> 405');

$result = t_admin_auth(t_config(['security' => ['admin_allowed_ips' => ['10.0.0.0/8']]]));
t_ok($result['ok'], '15e. admin request from an allowed CIDR accepted');

$result = t_admin_auth(t_config(['security' => ['admin_allowed_ips' => ['192.168.0.0/16']]]));
t_same(403, $result['status'], '15f. admin request from a non-listed IP -> 403');

$result = t_admin_auth(t_config(['security' => ['require_https' => true]]));
t_same(403, $result['status'], '15g. plain HTTP refused when require_https is on');

$result = t_admin_auth(t_config(['security' => ['require_https' => true]]), ['HTTPS' => 'on']);
t_ok($result['ok'], '15g. HTTPS accepted when require_https is on');

t_ok(cb_admin_key_valid('admin-secret-key-0123456789', $adminConfig), '15h. correct admin key accepted');
t_ok(!cb_admin_key_valid('wrong-key', $adminConfig), '15h. wrong admin key rejected');
t_ok(!cb_admin_key_valid('', $adminConfig), '15h. empty admin key rejected');
t_ok(!cb_admin_key_valid('admin-secret-key-0123456789', t_config(['security' => ['admin_key' => '']] )), '15h. unconfigured admin key rejects everything');

// Throttle.
$throttleIp = '203.0.113.77';
$throttleConfig = t_config(['security' => ['admin_max_failures' => 3, 'admin_lockout_seconds' => 900]]);
$GLOBALS['CB_CONFIG'] = $throttleConfig;
t_ok(!cb_admin_throttled($throttleIp), '15i. not throttled initially');
cb_admin_throttle_failure($throttleIp);
cb_admin_throttle_failure($throttleIp);
t_ok(!cb_admin_throttled($throttleIp), '15i. below the failure limit -> not throttled');
cb_admin_throttle_failure($throttleIp);
t_ok(cb_admin_throttled($throttleIp), '15i. limit reached -> throttled');
cb_admin_throttle_clear($throttleIp);
t_ok(!cb_admin_throttled($throttleIp), '15i. cleared -> not throttled');

// Expired lockout is lifted automatically.
cb_admin_throttle_failure($throttleIp);
cb_admin_throttle_failure($throttleIp);
cb_admin_throttle_failure($throttleIp);
$throttleFile = cb_admin_throttle_file($throttleIp);
cb_cache_write($throttleFile, ['failures' => 9, 'last_failure' => time() - 1000, 'locked_until' => time() - 5]);
t_ok(!cb_admin_throttled($throttleIp), '15i. expired lockout lifted');
t_ok(!is_file($throttleFile), '15i. expired lockout entry removed');

// CSRF.
$_SESSION = [];
$token = cb_admin_csrf_token();
t_ok($token !== '' && strlen($token) >= 32, '15j. csrf token generated');
t_same($token, cb_admin_csrf_token(), '15j. csrf token stable within the session');
t_ok(cb_admin_csrf_valid($token), '15j. correct csrf token accepted');
t_ok(!cb_admin_csrf_valid('nope'), '15j. wrong csrf token rejected');
t_ok(!cb_admin_csrf_valid(''), '15j. empty csrf token rejected');
$_SESSION = [];
t_ok(!cb_admin_csrf_valid($token), '15j. token invalid after the session is reset');
$_SESSION = [];

/* -------------------------------------------------------------------------
 * 16. Shared configuration loading (cb_load_config)
 * ---------------------------------------------------------------------- */

t_section('16. Shared configuration loading');

$cfgFile = $GLOBALS['CB_TEST_CACHE_DIR'] . '/test-config.php';
file_put_contents($cfgFile, '<?php return ["security" => ["api_key" => "0123456789abcdef0123456789abcdef"], '
    . '"callback" => ["default_lookup_minutes" => 45], "custom" => ["key" => "value"]];');

$previousEnv = getenv('CALLBACK_CONFIG');
putenv('CALLBACK_CONFIG=' . $cfgFile);
$loaded = cb_load_config('/nonexistent-private-dir');
putenv($previousEnv === false ? 'CALLBACK_CONFIG' : 'CALLBACK_CONFIG=' . $previousEnv);

t_ok(is_array($loaded), '16a. config file loads');
t_same('0123456789abcdef0123456789abcdef', $loaded['security']['api_key'], '16a. api key loaded');
t_same(45, $loaded['callback']['default_lookup_minutes'], '16a. override applied');
t_same(10, $loaded['ns_api']['request_timeout'], '16a. defaults merged in');
t_same('value', $loaded['custom']['key'], '16a. unknown top-level keys preserved');

$previousEnv = getenv('CALLBACK_CONFIG');
putenv('CALLBACK_CONFIG=/nonexistent/config.php');
$loaded = cb_load_config('/nonexistent-private-dir');
putenv($previousEnv === false ? 'CALLBACK_CONFIG' : 'CALLBACK_CONFIG=' . $previousEnv);
t_same(null, $loaded, '16b. missing config and no legacy constants -> null');

@unlink($cfgFile);

/* -------------------------------------------------------------------------
 * Summary
 * ---------------------------------------------------------------------- */

$stats = $GLOBALS['CB_TEST_STATS'];
echo "\n" . str_repeat('=', 60) . "\n";
echo 'Result: ' . $stats['pass'] . ' passed, ' . $stats['fail'] . " failed\n";

array_map('unlink', glob($GLOBALS['CB_TEST_CACHE_DIR'] . '/*') ?: []);
@rmdir($GLOBALS['CB_TEST_CACHE_DIR']);
array_map('unlink', glob($GLOBALS['CB_TEST_DOMAINS_DIR'] . '/*') ?: []);
@rmdir($GLOBALS['CB_TEST_DOMAINS_DIR']);

exit($stats['fail'] === 0 ? 0 : 1);
