<?php
/**
 * Callback2 — main callback feature.
 *
 * Never request this file directly: it is loaded by index.php (which enforces
 * the API key) and is meant to live outside the public web root.
 *
 * How to use:
 *   - Preferred: create a per-domain config on the admin config page
 *     (admin.php). A domain with a config is fully driven by it — failover
 *     extension, prompt audio, excluded extensions, lookup window, the
 *     callback-AA / call-decider / main-AA extensions and an on/off toggle.
 *     The legacy AA options are not consulted for configured domains (this
 *     also saves one API call per inbound call).
 *   - Legacy fallback (domains without a config): the DID points to an
 *     Auto-attendant ("Callback AA").
 *   - Option 0 -> an extension that forwards calls to this app; that
 *     extension's caller ID number must be the Callback AA.
 *   - Option 1 -> the "CallDecider" extension.
 *   - Option 2 -> the Callback AA.
 *   - Option 3 -> lookup window (optional). e.g. "30" (minutes), "45m",
 *     "2h"; bare values <= 12 keep the legacy meaning of hours ("-4" = 4h).
 *   - Option 4 -> audio file for the prompts ("default" = text-to-speech).
 *   - Option 5 -> comma separated extensions to ignore (script text field).
 *
 * @package Callback2
 */

if (!defined('CALLBACK_APP')) {
    http_response_code(404);
    exit;
}

/**
 * Normalise an ANI/DNIS value: strip "+", strip a leading country code 1 on
 * 11 digit numbers (legacy behaviour), and turn short extension numbers into
 * the sip:user@domain form the CDR API uses for internal calls.
 */
function cb_normalize_number(string $number, string $domain): string
{
    if ($number === '') {
        return '';
    }
    if ($number[0] === '+') {
        $number = substr($number, 1);
    }
    if (strlen($number) === 11 && $number[0] === '1') {
        $number = substr($number, 1);
    }
    if (strlen($number) <= 5 && $domain !== '') {
        return 'sip:' . $number . '@' . $domain;
    }
    return $number;
}

/**
 * Accept the caller identity as the switch sends it (bare number, +E.164 or
 * sip:user@domain) and return the value to search the CDR API with.
 */
function cb_caller_identity($raw, string $domain): string
{
    $value = cb_scalar($raw, 120);
    if ($value === '') {
        return '';
    }
    if (stripos($value, 'sip:') === 0) {
        $value = substr($value, 4);
    }
    if (strpos($value, '@') !== false) {
        $parts = explode('@', $value, 2);
        $host = cb_host($parts[1]);
        if ($host !== '' && $host !== $domain) {
            return ''; // caller from a foreign domain: not our business
        }
        $value = $parts[0];
    }
    if (!preg_match('/^\+?\d{1,15}$/', $value)) {
        return '';
    }
    return $value;
}

/**
 * Compare two phone numbers, tolerating a leading country code.
 */
function cb_numbers_match(string $a, string $b): bool
{
    $digits = function ($value) {
        return preg_replace('/\D+/', '', $value);
    };
    $a = (string) $digits($a);
    $b = (string) $digits($b);
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    if (strlen($a) === 11 && $a[0] === '1') {
        $a = substr($a, 1);
    }
    if (strlen($b) === 11 && $b[0] === '1') {
        $b = substr($b, 1);
    }
    return $a === $b;
}

/**
 * Answer used whenever the flow cannot be completed: forward to the
 * call-decider when known, otherwise to callback.fallback_extension, and when
 * neither exists apologise and hang up instead of returning empty XML.
 */
function cb_output_failover(string $domain, string $preferred): void
{
    $target = cb_failover_target($domain, $preferred);
    if ($target !== '') {
        cb_output_forward($target);
        return;
    }
    cb_log('no call-decider or fallback extension available; hanging up', 'warning');
    cb_output_say_hangup('We are unable to complete your call at this time. Please try again later.');
}

/**
 * Forward target used whenever we cannot complete the flow: the call-decider
 * when known, otherwise callback.fallback_extension from the config.
 */
function cb_failover_target(string $domain, string $preferred): string
{
    $extension = cb_extension($preferred);
    if ($extension === '') {
        $extension = cb_extension(cb_cfg('callback.fallback_extension', ''));
    }
    if ($extension === '') {
        return '';
    }
    return cb_sip_uri($extension, $domain);
}

/**
 * Answer used whenever the flow hands the call over to the customer's normal
 * routing: the domain's failover extension (the downstream target — main AA /
 * after-hours — from the config page), then the call decider, then the global
 * fallback. When nothing is configured, apologise and hang up instead of
 * returning empty XML.
 *
 * @param bool $preferMainAa Prefer the main auto attendant over the call
 *                           decider (used when the feature is disabled for
 *                           the domain, so the call never re-enters the
 *                           callback feature through the decider).
 */
function cb_output_handoff(string $domain, array $settings, bool $preferMainAa = false): void
{
    $target = cb_handoff_target($domain, $settings, $preferMainAa);
    if ($target !== '') {
        cb_output_forward($target);
        return;
    }
    cb_log('no hand-off target available; hanging up', 'warning');
    cb_output_say_hangup('We are unable to complete your call at this time. Please try again later.');
}

/**
 * Fetch (and optionally cache) the AA prompt configuration that drives the
 * callback flow: Prompt_<identifier> for the account user.
 *
 * @return array|null null when the configuration cannot be retrieved.
 */
function cb_fetch_aa_options(CbNsApiClient $api, string $domain, string $accountUser): ?array
{
    $cacheSeconds = max(0, (int) cb_cfg('callback.aa_cache_seconds', 60));
    $cacheFile = cb_cache_file('aa', $domain . '|' . $accountUser);

    if ($cacheSeconds > 0) {
        $cached = cb_cache_read($cacheFile);
        if ($cached !== null
            && isset($cached['expiry'], $cached['options'])
            && is_array($cached['options'])
            && (int) $cached['expiry'] > time()
        ) {
            return $cached['options'];
        }
    }

    $promptIdentifier = cb_generate_prompt_identifier($accountUser);
    $path = 'v2/domains/' . $domain . '/users/' . $accountUser
        . '/autoattendants/Prompt_' . $promptIdentifier;

    $result = $api->get($path);
    if (!$result['ok']) {
        return null;
    }

    $attendant = $result['data']['auto-attendant'] ?? null;
    if (!is_array($attendant)) {
        cb_log('AA payload for ' . $accountUser . '@' . $domain . ' has no auto-attendant node');
        return null;
    }

    $options = [
        'callback_aa'    => cb_extension($attendant['option-0']['destination-user'] ?? ''),
        'call_decider'   => cb_extension($attendant['option-1']['destination-user'] ?? ''),
        'auto_attendant' => cb_extension($attendant['option-2']['destination-user'] ?? ''),
        'lookup_minutes' => cb_parse_lookup_minutes(
            $attendant['option-3']['destination-user'] ?? '',
            (int) cb_cfg('callback.default_lookup_minutes', 30),
            (int) cb_cfg('callback.max_lookup_minutes', 1440)
        ),
        'message_url'    => cb_audio_source($attendant['option-4']['audio']['file-access-url'] ?? 'default'),
        'excluded'       => cb_excluded_set($attendant['option-5']['audio']['file-script-text'] ?? ''),
    ];

    if ($cacheSeconds > 0) {
        cb_cache_write($cacheFile, ['expiry' => time() + $cacheSeconds, 'options' => $options]);
    }

    return $options;
}

/**
 * "Has this caller talked to one of our extensions recently?" lookup
 * (caller= query). Returns the extension, 0 when there is no match, or null
 * when the API could not be reached.
 */
function cb_find_recent_conversation(
    CbNsApiClient $api,
    string $domain,
    string $callerNumber,
    array $aaExtensions,
    int $lookupMinutes,
    array $excluded,
    int $minTalkSeconds = 3
): ?int {
    if ($callerNumber === '') {
        return 0;
    }

    $end = gmdate('Y-m-d H:i:s');
    $start = gmdate('Y-m-d H:i:s', time() - ($lookupMinutes * 60));

    $result = $api->get('v2/domains/' . $domain . '/cdrs', [
        'datetime-start' => $start,
        'datetime-end'   => $end,
        'caller'         => $callerNumber,
    ]);
    if (!$result['ok']) {
        return null;
    }

    $aaSet = cb_lookup_set($aaExtensions);

    foreach (array_reverse(cb_cdr_records($result['data'])) as $record) {
        // Ignore AA legs (they carry a call-leg-ordinal-index).
        $ordinal = $record['call-leg-ordinal-index'] ?? null;
        if ($ordinal === null || $ordinal === '' || $ordinal === 0 || $ordinal === '0') {
            continue;
        }

        $terminatingUser = cb_clean_text($record['call-term-user'] ?? '', 64);
        if ($terminatingUser === '' || isset($aaSet[$terminatingUser])) {
            continue;
        }
        if ((int) ($record['call-talking-duration-seconds'] ?? 0) < $minTalkSeconds) {
            continue;
        }
        if (isset($excluded[$terminatingUser])) {
            return 0;
        }
        if (!preg_match('/^\d{1,20}$/', $terminatingUser)) {
            continue;
        }
        return (int) $terminatingUser;
    }

    return 0;
}

/**
 * "Did one of our users call this number recently?" lookup (dialled= query).
 *
 * @return array{ok:bool,match:?array,reason:string}
 */
function cb_find_callback_target(
    CbNsApiClient $api,
    string $domain,
    string $callerNumber,
    string $calledNumber,
    int $lookupMinutes,
    array $excluded
): array {
    if ($callerNumber === '' || $calledNumber === '') {
        return ['ok' => true, 'match' => null, 'reason' => 'missing caller/did'];
    }

    $end = gmdate('Y-m-d H:i:s');
    $start = gmdate('Y-m-d H:i:s', time() - ($lookupMinutes * 60));

    $result = $api->get('v2/domains/' . $domain . '/cdrs', [
        'datetime-start' => $start,
        'datetime-end'   => $end,
        'dialled'        => $callerNumber,
    ]);
    if (!$result['ok']) {
        return ['ok' => false, 'match' => null, 'reason' => $result['error']];
    }

    foreach (cb_cdr_records($result['data']) as $record) {
        $origCallerId = (string) ($record['call-orig-caller-id'] ?? '');
        if (!cb_numbers_match($origCallerId, $calledNumber)) {
            continue;
        }

        $originationUser = cb_clean_text($record['call-orig-user'] ?? '', 64);
        $name = cb_clean_text($record['call-orig-from-name'] ?? '', 64);
        $talked = (int) ($record['call-talking-duration-seconds'] ?? 0) >= 3;

        if ($originationUser === '') {
            return ['ok' => true, 'match' => null, 'reason' => 'call had no origination user'];
        }
        if (isset($excluded[$originationUser])) {
            return ['ok' => true, 'match' => [
                'extension' => $originationUser,
                'name'      => $name,
                'talked'    => $talked,
                'excluded'  => true,
            ], 'reason' => 'extension excluded'];
        }

        return ['ok' => true, 'match' => [
            'extension' => $originationUser,
            'name'      => $name,
            'talked'    => $talked,
            'excluded'  => false,
        ], 'reason' => 'match'];
    }

    return ['ok' => true, 'match' => null, 'reason' => 'no match'];
}

/**
 * Entry point — called by index.php for action=callback.
 */
function run_callback(array $config, string $apiKey): void
{
    $accountUser  = cb_user_token(cb_request_value('AccountUser'));
    $domain       = cb_domain(cb_request_value('AccountDomain'));
    $callerRaw    = cb_request_value('NmsAni', 120);
    $calledRaw    = cb_request_value('NmsDnis', 120);

    if ($domain === '') {
        cb_log('callback request rejected: missing or invalid AccountDomain', 'warning');
        cb_output_hangup();
        return;
    }

    $callerNumber = cb_normalize_number(cb_caller_identity($callerRaw, $domain), $domain);
    $calledNumber = cb_normalize_number(cb_phone($calledRaw), $domain);

    if ($callerNumber === '') {
        cb_log('callback request has no usable caller number (NmsAni=' . cb_clean_text($callerRaw, 40) . ')', 'warning');
    }

    $defaults = [
        'failover_extension' => '',
        'callback_aa'    => cb_extension(cb_cfg('callback.default_callback_aa_extension', '')),
        'call_decider'   => cb_extension(cb_cfg('callback.default_call_decider_extension', '')),
        'auto_attendant' => cb_extension(cb_cfg('callback.default_auto_attendant_extension', '')),
        'lookup_minutes' => cb_parse_lookup_minutes(
            null,
            (int) cb_cfg('callback.default_lookup_minutes', 30),
            (int) cb_cfg('callback.max_lookup_minutes', 1440)
        ),
        'message_url'    => cb_audio_source(cb_cfg('callback.default_message_url', 'default')),
        'excluded'       => cb_excluded_set(cb_cfg('callback.default_excluded_extensions', '')),
    ];

    // Per-domain configuration from the admin config page. When present it
    // is the source of truth and the legacy AA options are not consulted (and
    // not even fetched — one API call saved per inbound call).
    $domainCfg = cb_domain_config_load($domain);

    $api = new CbNsApiClient($config);

    $aa = null;
    if ($domainCfg === null) {
        if ($accountUser !== '') {
            $options = cb_fetch_aa_options($api, $domain, $accountUser);
            if ($options === null) {
                cb_log('AA configuration unavailable for ' . $accountUser . '@' . $domain . ' -> failover', 'warning');
                cb_output_failover($domain, '');
                return;
            }
            $aa = $options;
        } else {
            // No AccountUser: the flow can only continue with a configured default.
            if ($defaults['call_decider'] === '') {
                cb_log('request without AccountUser and no callback.default_call_decider_extension configured', 'warning');
                cb_output_failover($domain, '');
                return;
            }
            $aa = $defaults;
        }

        if ($aa['call_decider'] === '' && $defaults['call_decider'] !== '') {
            $aa['call_decider'] = $defaults['call_decider'];
        }
    }

    // Effective settings: domain config > AA options (legacy) > global defaults.
    $settings = cb_resolve_settings($domainCfg, $aa, $defaults);

    // Feature switched off for this domain: hand straight to the downstream
    // target (main AA / after-hours) without spending a single API call.
    if (!$settings['enabled']) {
        cb_log('callback feature disabled for domain ' . $domain . ' -> handing off', 'info');
        cb_output_handoff($domain, $settings, true);
        return;
    }

    $callDecider = $settings['call_decider'];
    // Where the <Gather> step sends the call when the caller declines or
    // times out: the downstream hand-off target, not the call decider.
    $handoffExtension = cb_handoff_extension($settings, false);
    $lookupMinutes = $settings['lookup_minutes'];

    // --- 1) Recent conversation with one of our extensions? -----------------
    if ($callerNumber !== '') {
        $aaExtensions = [$settings['callback_aa'], $callDecider, $settings['auto_attendant']];
        $recent = cb_find_recent_conversation(
            $api,
            $domain,
            $callerNumber,
            $aaExtensions,
            $lookupMinutes,
            $settings['excluded']
        );

        if ($recent !== null && $recent > 0) {
            $message = 'Would you like to reconnect to extension '
                . cb_say_digits_hint((string) $recent)
                . '? Press 1 for yes, or 2 for no.';
            cb_output_gather(
                cb_action_url('gather', [
                    'did'     => (string) $recent,
                    'cdext'   => $handoffExtension,
                    'domain'  => $domain,
                    'apikey'  => $apiKey,
                ]),
                $settings['message_url'],
                $message
            );
            return;
        }
        if ($recent === null) {
            cb_log('recent-conversation lookup failed; continuing to callback lookup', 'warning');
        }

        // --- 2) Did one of our users call this number recently? -------------
        $result = cb_find_callback_target(
            $api,
            $domain,
            $callerNumber,
            $calledNumber,
            $lookupMinutes,
            $settings['excluded']
        );

        if (!$result['ok']) {
            cb_log('callback lookup failed: ' . $result['reason'], 'warning');
            cb_output_handoff($domain, $settings, false);
            return;
        }

        if ($result['match'] !== null) {
            $match = $result['match'];

            if (!$match['excluded'] && $match['extension'] !== '') {
                $name = $match['name'] !== '' ? $match['name'] : 'the caller';
                $message = $match['talked']
                    ? 'Would you like to reconnect to ' . cb_xml($name) . '? Press 1 for yes, or 2 for no.'
                    : 'Are you returning ' . cb_xml($name) . ' call? Press 1 for yes, or 2 for no.';
                cb_output_gather(
                    cb_action_url('gather', [
                        'did'     => $match['extension'],
                        'cdext'   => $handoffExtension,
                        'domain'  => $domain,
                        'apikey'  => $apiKey,
                    ]),
                    $settings['message_url'],
                    $message
                );
                return;
            }

            cb_log('callback target skipped (' . $result['reason'] . ') -> handing off', 'warning');
        }
    }

    // --- 3) Nothing matched (or nothing to look up): hand the call to the
    //        customer's normal routing (failover ext / call decider) ---------
    cb_output_handoff($domain, $settings, false);
}
