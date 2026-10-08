<?php
/**
 * Callback2 — example configuration.
 *
 * Copy this file to config.php in the private directory (never inside the web
 * root) and fill in the values:
 *
 *     cp config.example.php config.php
 *     chmod 640 config.php
 *
 * The file must return a plain array. Every key is optional except
 * security.api_key, ns_api.servers and the NS-API credentials.
 *
 * @package Callback2
 */

return [

    /* ---------------------------------------------------------------------
     * Application
     * ------------------------------------------------------------------ */
    'app' => [
        // Timezone used for log timestamps (the API/CDR times are always UTC).
        'timezone'      => 'UTC',

        // Optional log file, e.g. '/var/log/callback2/app.log'. Errors always
        // go to the PHP error log as well. Never point this inside the web root.
        'log_file'      => '',

        // Log one line per call (action, IP, did, digits). Handy while tuning.
        'access_log'    => false,

        // Hard cap for the whole request in seconds (the switch is waiting).
        'max_execution' => 25,
    ],

    /* ---------------------------------------------------------------------
     * Security
     * ------------------------------------------------------------------ */
    'security' => [
        // REQUIRED. Minimum 16 characters, treat it like a password.
        // Generate one with:  openssl rand -hex 32
        // Change this placeholder before going live.
        'api_key'          => 'CHANGE_ME_openssl_rand_hex_32',

        // Optional allow-list: only these IPs / IPv4 CIDR ranges may call the
        // endpoint. Empty array = allow any source (the API key still applies).
        // Use the switch IPs here for defence in depth, e.g. ['10.0.0.0/8'].
        'allowed_ips'      => [],

        // HTTP methods accepted from the switch.
        'allowed_methods'  => ['GET', 'POST'],

        // Accept the API key in headers (X-API-Key: ...).
        'allow_header_key' => true,

        // Accept the key in the query string (?apikey=...) — required because
        // the switch cannot send custom headers on a <Gather action>.
        'allow_query_key'  => true,

        // Require HTTPS for every request. Enable once the switch fetches the
        // endpoint over TLS (recommended).
        'require_https'    => false,
    ],

    /* ---------------------------------------------------------------------
     * NetSapiens API (with failover)
     * ------------------------------------------------------------------ */
    'ns_api' => [
        // Servers are tried in order; when one fails the next one is used and
        // the failing node is skipped for the rest of the request.
        'servers'              => [
            'nms2.nyc.birns.net',
            // 'nms3.nyc.birns.net',
        ],

        'request_timeout'      => 10,  // seconds per API call
        'connect_timeout'      => 5,   // seconds to establish the connection
        'token_timeout'        => 8,   // seconds for the OAuth token call
        'total_budget_seconds' => 12,  // hard cap across ALL servers/attempts

        // Directory for the OAuth token and (optional) AA config cache.
        // Must be writable by the web server and must NOT be web accessible.
        'token_cache_dir'      => '/var/www/callback/cache',

        // Credentials. Values here win over the environment variables
        // (CDR_CLIENT_ID, CDR_CLIENT_SECRET, CDR_USERNAME, CDR_PASSWORD) and
        // over legacy constants (CLIENT_ID, CLIENT_SECRET, SUPER_USER, SUPER_PASS).
        'credentials'          => [
            'client_id'     => '',
            'client_secret' => '',
            'username'      => '',
            'password'      => '',
        ],
    ],

    /* ---------------------------------------------------------------------
     * Callback behaviour
     * ------------------------------------------------------------------ */
    'callback' => [
        // Lookup window when AA option 3 is not set (minutes).
        'default_lookup_minutes' => 30,
        'max_lookup_minutes'     => 1440,

        // Cache the AA prompt configuration for N seconds (0 = no cache).
        // Saves one API call per inbound call.
        'aa_cache_seconds'       => 60,

        // Where to send the call when the API cannot be reached at all and the
        // call-decider is still unknown (e.g. the CallDecider extension).
        'fallback_extension'     => '',

        // Used only when the request contains no AccountUser parameter.
        'default_call_decider_extension'   => '',
        'default_callback_aa_extension'    => '',
        'default_auto_attendant_extension' => '',
        'default_message_url'              => 'default',
        'default_excluded_extensions'      => '',

        // Absolute URL of index.php, used for the <Gather action="...">.
        // Set this when the switch cannot guess it from the request.
        'public_url'             => '',

        // Legacy standalone prompt (jewishcallbackivr2.wav flow). Leave the
        // domain empty to disable it.
        'legacy_domain'          => '',
        'legacy_audio'           => 'jewishcallbackivr2.wav',
        'legacy_gather_timeout'  => 5,
    ],
];
