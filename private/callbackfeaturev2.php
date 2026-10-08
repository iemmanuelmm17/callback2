<?php
/**
 * Callback2 — <Gather> digit handler.
 *
 * This is the action target of the prompts built by cb.php. It receives the
 * caller's digits plus the routing context (did / cdext / domain) and answers
 * with the <Forward> the switch should execute.
 *
 * Never request this file directly: it is loaded by index.php (which enforces
 * the API key) and is meant to live outside the public web root.
 *
 * @package Callback2
 */

if (!defined('CALLBACK_APP')) {
    http_response_code(404);
    exit;
}

/**
 * Entry point — called by index.php for action=gather.
 *
 * @param array  $config  Loaded configuration.
 * @param string $apiKey  Validated API key (echoed back into the replay action).
 */
function run_gather_handler(array $config, string $apiKey): void
{
    $digits = cb_digits(cb_request_value('Digits', 20), 12);
    $did    = cb_scalar(cb_request_value('did', 80), 80);    // extension to reconnect to
    $cdext  = cb_scalar(cb_request_value('cdext', 80), 80);  // call-decider extension
    $domain = cb_domain(cb_request_value('domain', 253));
    $attempt = (int) cb_digits(cb_request_value('attempt', 4), 2);

    if ($domain === '') {
        cb_log('gather request rejected: missing or invalid domain', 'warning');
        cb_output_hangup();
        return;
    }

    // Targets must resolve to a SIP user inside our own domain.
    $didUri = cb_sip_uri_local($did, $domain);
    $cdextUri = cb_sip_uri_local($cdext, $domain);

    // 1 = yes -> reconnect to the extension we offered.
    if ($digits === '1') {
        $target = $didUri !== '' ? $didUri : $cdextUri;
        if ($target !== '') {
            cb_output_forward($target);
            return;
        }
    }

    // 2 = no -> normal routing (call decider).
    if ($digits === '2') {
        $target = $cdextUri !== '' ? $cdextUri : $didUri;
        if ($target !== '') {
            cb_output_forward($target);
            return;
        }
    }

    // The caller dialled an extension directly (3+ digits).
    if ($digits !== '' && strlen($digits) >= 3) {
        $target = cb_sip_uri_local($digits, $domain);
        if ($target !== '') {
            cb_output_forward($target);
            return;
        }
    }

    // Nothing usable pressed (timeout) or an unexpected digit. Never drop the
    // call: hand it to the call decider when we know it, otherwise leave it at
    // a safe forwarding target.
    if ($digits !== '') {
        cb_log('gather received unexpected digit "' . $digits . '"', 'warning');
    }
    if ($cdextUri !== '') {
        cb_output_forward($cdextUri);
        return;
    }
    if ($didUri !== '') {
        cb_output_forward($didUri);
        return;
    }

    // Legacy standalone "callback IVR" prompt (invoked without routing context).
    // Replay the prompt once, then stop so the caller cannot be looped forever.
    $legacyDomain = cb_domain(cb_cfg('callback.legacy_domain', ''));
    if ($legacyDomain !== '' && strcasecmp($domain, $legacyDomain) === 0) {
        if ($attempt < 1) {
            $audio = cb_audio_source(cb_cfg('callback.legacy_audio', 'jewishcallbackivr2.wav'));
            $timeout = max(1, min(20, (int) cb_cfg('callback.legacy_gather_timeout', 5)));
            cb_output_gather(
                cb_action_url('gather', [
                    'domain'  => $domain,
                    'apikey'  => $apiKey,
                    'attempt' => '1',
                ]),
                $audio,
                '',
                1,
                $timeout
            );
            return;
        }
        cb_log('legacy IVR prompt replayed once and received no digits; hanging up', 'warning');
    }

    cb_output_hangup();
}
