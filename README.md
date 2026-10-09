# Callback2

Callback feature for **NetSapiens** phone systems: when a caller rings a DID, the
app checks the CDRs for a recent conversation with one of your extensions and,
if there is one, offers the caller a one-touch reconnect ("Press 1 for yes, or
2 for no"). If there is nothing to reconnect, the call is forwarded to the
customer's **CallDecider** extension as before.

Version 2 is a security + reliability rewrite of the original `cb.php` /
`callbackfeaturev2.php` pair.

---

## What changed in v2

| Area | Before | Now |
| --- | --- | --- |
| **File layout** | `cb.php` and `callbackfeaturev2.php` sat in the web root | Everything lives in `private/` — outside the web root. Only `index.php` is public |
| **Authentication** | None: anyone who found the URL could drive the routing | API key required (`X-API-Key` header, or `?apikey=` for the switch), constant-time compare, fail-closed |
| **Failover** | One hard-coded API server; any error = broken/empty XML | Ordered server list, per-request failover, 401 token-refresh retry, total time budget, graceful `<Forward>` fallback |
| **Lookup window** | Main lookup 4 h, recent-conversation check hard-coded 1 h | **30 minutes by default** for both, overridable per AA via option 3 |
| **Input handling** | Raw `$_REQUEST` interpolated into XML and URLs | Every parameter validated against a strict pattern; all output XML-escaped |
| **HTTP layer** | Guzzle (Composer) + cURL | Direct cURL with stream fallback — no Composer, no `vendor/` |
| **Token cache** | Plain `file_put_contents`, world-readable | Atomic writes, `0600`, per server, `flock` reads |
| **Errors** | PHP notices inside the XML, truncated documents | Errors logged; switch always gets exactly one well-formed `<Response>` (exception + fatal-error handlers) |
| **Timeouts** | None — a dead API node could hang the call | Connect/request timeouts plus `total_budget_seconds` cap |
| **Bugs fixed** | Garbage bytes at end of `cb.php`, invalid `//<Play>` comment inside XML, undefined `$callDeciderExtension` on token failure, `sip:@domain` forwards, `&`-unsafe query strings, unescaped prompt text | all fixed |

**v2.1** adds the per-domain **config page** (`admin.php`): failover extension,
prompt audio, excluded extensions, lookup window, routing extensions and an
on/off toggle per domain, stored as one JSON file per domain. A configured
domain ignores the legacy AA options 0-5 entirely (and skips that API call);
unconfigured domains behave exactly as v2.0. See
[Per-domain configuration](#per-domain-configuration-admin-config-page).

---

## Requirements

* PHP **7.1+** (8.x recommended — tested on 8.2).
* Extensions: `json` (always bundled). `curl` is recommended; without it the app
  falls back to the stream wrapper. `mbstring` optional.
* No Composer, no Guzzle, no `vendor/` directory.
* A writable cache directory outside the web root (OAuth tokens, AA cache).

---

## File layout

```
<public web root>/            # the only internet-reachable part
    index.php                 # switch entry point: config load, API-key auth, routing
    admin.php                 # per-domain configuration UI (own admin key)
    .htaccess                 # optional hardening (no indexes, no dotfiles)

<private dir>/**              # NOT reachable from the web
    bootstrap.php             # helpers: sanitising, XML, HTTP, token, NS-API client,
                              #   config loading (cb_load_config)
    domains.php               # per-domain config store + settings resolution
    cb.php                    # main callback feature  (run_callback)
    callbackfeaturev2.php     # <Gather> digit handler   (run_gather_handler)
    admin.php                 # admin UI: auth, session, CSRF, pages
    config.example.php        # copy to config.php and fill in
    config.php                # your secrets (git-ignored, chmod 640)
    .htaccess                 # deny-everything safety net
    cache/                    # tokens + AA cache (created automatically)
    domains/                  # per-domain JSON configs + audit.log (git-ignored,
                              # created automatically by the admin UI)

tests/
    run-tests.php             # offline test-suite (stubbed NS-API) — CLI only,
                              # never deploy it to the web root (.htaccess denies /tests/)
```

The private directory is selected at runtime:

1. `CALLBACK_PRIVATE_DIR` environment variable, if set, otherwise
2. `private/` next to `index.php`.

In production point it at a directory outside the docroot, e.g.
`/var/www/callback` (this is exactly what the original `config.php` path
already used). If you *must* keep it inside the docroot, the shipped
`.htaccess` files deny access to it — but outside the docroot is the safe
option.

---

## Deployment

### 1. Place the files

```bash
# private application, outside the web root
sudo mkdir -p /var/www/callback/cache
sudo chown -R www-data:www-data /var/www/callback
sudo chmod 750 /var/www/callback /var/www/callback/cache
sudo cp private/bootstrap.php private/cb.php private/callbackfeaturev2.php \
        private/config.example.php private/.htaccess /var/www/callback/

# public entry point
sudo cp index.php .htaccess /var/www/html/callback/
```

Point the entry point at the private directory — either set an environment
variable in the vhost (Apache `SetEnv`, PHP-FPM `env[...]`, or a `fastcgi_param`):

```
CALLBACK_PRIVATE_DIR=/var/www/callback
CALLBACK_CONFIG=/var/www/callback/config.php   # optional, defaults to <private>/config.php
```

…or edit the two lines near the top of `index.php`.

### 2. Create the configuration

```bash
cp private/config.example.php private/config.php
chmod 640 private/config.php
openssl rand -hex 32          # paste the result into security.api_key
```

Minimum to fill in:

* `security.api_key` — **at least 16 characters** (shorter values make the app
  reject every request with HTTP 500 on purpose: fail closed).
* `security.admin_key` — **at least 16 characters**, for the config page
  (`admin.php`). Generate a *different* key (`openssl rand -hex 32`); the panel
  answers 404 until this is set.
* `ns_api.servers` — the API node(s), in failover order.
* `ns_api.token_cache_dir` — writable, outside the web root.
* `ns_api.credentials` — or leave blank and use the environment variables
  (`CDR_CLIENT_ID`, `CDR_CLIENT_SECRET`, `CDR_USERNAME`, `CDR_PASSWORD`);
  the legacy constants (`CLIENT_ID`, `CLIENT_SECRET`, `SUPER_USER`,
  `SUPER_PASS`) are still honoured, so an existing `config.php` that only
  defines constants keeps working during migration.

The per-domain configs themselves are created through `admin.php` — no manual
file editing needed (the app creates `private/domains/` on first save).

### 3. Apache / nginx

Apache works out of the box with `index.php` in the docroot (the shipped
`.htaccess` only disables indexes and dotfiles).

nginx — the private directory is simply not under `root`, so nothing else to do:

```nginx
server {
    listen 443 ssl;
    server_name cb.example.com;
    root /var/www/html/callback;
    index index.php;

    location = /index.php {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param CALLBACK_PRIVATE_DIR /var/www/callback;   # if not set in the pool
    }

    location / { deny all; }   # nothing else is served
}
```

### 4. Smoke test

```bash
curl -i 'https://cb.example.com/index.php?AccountUser=1234&AccountDomain=example.com&NmsAni=4155551234&NmsDnis=4155551000' \
     -H 'X-API-Key: <your key>'
# -> <Response><Gather .../></Response>  or  <Response><Forward>sip:…</Forward></Response>

curl -i 'https://cb.example.com/index.php' -H 'X-API-Key: wrong'   # -> 403
```

---

## Phone system configuration

Two ways to configure a customer, in order of preference:

1. **Per-domain config page (recommended)** — see the next section. One page per
   domain holds everything: failover extension, prompt audio, excluded
   extensions, lookup window, the routing extensions and an on/off toggle.
2. **Legacy AA options** — for domains without a config page, the app reads
   `Prompt_<AccountUser>` options exactly as before:

| AA option | Meaning |
| --- | --- |
| `option-0` | Callback AA extension (excluded from reconnect lookups, used as the caller-ID of the forwarding extension) |
| `option-1` | **CallDecider** extension — default routing target |
| `option-2` | Callback AA extension |
| `option-3` | Lookup window, optional (see below) |
| `option-4` | Audio file / `file-access-url` used for the prompts (`default` = text-to-speech) |
| `option-5` | Comma separated extensions to ignore (`file-script-text` field) |

A domain **with** a config page ignores the AA options entirely (the app does
not even fetch them — one API call saved per inbound call). A domain **without**
one behaves exactly as v2.0 did. Move customers over one domain at a time.

Point the DID's application URL at **`index.php`** instead of `cb.php`
(add `?action=callback` if you prefer to be explicit — that is the default), and
give the switch the API key (see the next section).

> **Migrating an existing install:** the URL changes in two places —
> (1) the inbound application/forward URL for the callback extension and
> (2) any hard-coded `<Gather action="...">` URL stored in provisioning. New
> responses generated by the app already point at `index.php` and carry the key.
> If you cannot re-provision (2), keep this six-line file in the web root:
>
> ```php
> <?php
> $_GET['action'] = $_POST['action'] = 'gather';
> require __DIR__ . '/index.php';
> ```
>
> It routes old URLs through the authenticated entry point, so the request still
> needs the API key to be accepted (or the switch must send it).

### API key: header vs query parameter

* **Inbound request (DID → app):** send `X-API-Key: <key>`. Preferred — headers
  never end up in access logs.
* **`<Gather action>` URL:** the switch fetches this URL itself and cannot send
  custom headers, so the app appends `?apikey=<key>` to the action URL it emits.
  This is the one place the key travels in a URL. Tighten it with
  `security.allowed_ips` (switch IPs only) and `security.require_https`.

If your provisioning cannot set a header *and* you cannot use query keys, set
`security.allow_header_key=false`/`allow_query_key=true` combinations to taste —
both are validated with `hash_equals()` either way.

---

## Per-domain configuration (admin config page)

Instead of encoding a customer's failover target, prompt audio and excluded
extensions into AA options 3/4/5, each domain gets a **config page** at
`admin.php`. Everything is stored as one JSON file per domain
(`domains/<domain>.json`, git-ignored, `0600`, written atomically) — no
database needed.

**Setup**

1. Set `security.admin_key` in `config.php` (its own key — `openssl rand -hex 32`;
   deliberately *not* the switch API key, which appears in `<Gather action>` URLs).
2. Open `https://<your-host>/admin.php` and sign in.
3. Add a domain and fill in the form.

**Fields**

| Field | Replaces | Meaning |
| --- | --- | --- |
| Enabled | — | Master switch for the callback feature for this domain. When off, inbound calls are handed straight to the routing below **without a single API call** |
| Failover extension | — | Where calls go when the callback feature cannot handle them: API outage, or nothing to offer, or the caller presses 2 / times out. Use a **downstream** target (main AA / after-hours) — see the loop warning below |
| Callback AA extension | option 0 | Excluded from reconnect lookups |
| Call decider extension | option 1 | The customer's CallDecider |
| Main auto attendant extension | option 2 | The regular main AA; also the hand-off target when the feature is disabled and no failover extension is set |
| Lookup window (minutes) | option 3 | CDR search window, 1–1440; empty = global default (30) |
| Prompt audio | option 4 | `default` (TTS), an http(s) URL or a prompt file name |
| Excluded extensions | option 5 | Comma separated extensions never offered a callback |

**Precedence:** domain config page → legacy AA options → global defaults in
`config.php`. The enabled toggle only exists on the config page.

**Recommended call flow** (the one this design assumes):

```
DID → intro AA (plays greeting) → CallDecider
        ├─ callback feature (index.php)   ← during the hours you want callbacks
        ├─ after-hours AA                 ← time-based routing in the decider
        └─ main AA                        ← customers without the feature
```

The CallDecider owns time-of-day. The callback feature never sends a call back
to the CallDecider — it always hands off **downstream** (failover extension →
main AA), so a caller can never loop between the decider and the feature.

> **⚠ Routing-loop warning** (also enforced in the form): the failover
> extension must **not** be the callback AA (hard error — the call would loop
> straight back into the feature). Setting it to the CallDecider saves with a
> warning, because a decider that routes into the callback feature can loop
> while the API is down. Prefer a downstream target: the main AA or the
> after-hours AA.

**Admin panel security:** separate admin key (constant-time compare, ≥ 16
chars), optional `security.admin_allowed_ips` allow-list, HTTPS enforcement via
`security.require_https`, hardened session cookie (httponly, SameSite, idle
timeout `security.admin_session_seconds`), CSRF token on every POST, per-IP
lockout after `security.admin_max_failures` failed sign-ins
(`security.admin_lockout_seconds`), and an audit log (`domains/audit.log`)
recording every sign-in and config change with IP and timestamp. When
`security.admin_key` is not configured the panel answers **404** (fail closed —
its existence is not advertised). The panel is never reachable with the
switch's API key.

---

## Lookup window (option 3)

Default is **30 minutes** for both the "recent conversation" check and the
"did we call this number" check. The per-domain config page's *Lookup window*
field overrides option 3 for configured domains; option 3 overrides it for
everyone else:

| Value | Meaning |
| --- | --- |
| *(unset)* | `callback.default_lookup_minutes` → **30 minutes** |
| `45` | 45 minutes |
| `30m` / `2h` | explicit minutes / hours |
| `6` / `-4` / `User -4` | **legacy** — bare values ≤ 12 are read as hours (6 h / 4 h) |

The result is clamped to 1…`callback.max_lookup_minutes` (default 1440).
Note that legacy bare-hour values still work, so no re-provisioning is needed
for customers who never touched option 3.

---

## Failover behaviour

1. `ns_api.servers` is tried **in order**. A node that fails is skipped for the
   rest of the request (no repeated waits).
2. A `401`/`403` triggers **one** OAuth token refresh for that node, then a retry.
3. Every attempt is bounded by `request_timeout` / `connect_timeout`, and the
   sum of all attempts by `total_budget_seconds` (default 12 s) so a call is
   never left listening to dead air.
4. If the AA configuration cannot be fetched, or the CDR lookup fails on every
   node, the call is **forwarded to `callback.fallback_extension`** (the
   CallDecider is unknown at that point). With no fallback configured the caller
   hears an apology and the call ends cleanly — never a broken/empty XML answer.

Token caching means a healthy install does **zero** extra API calls per call
(one token request per hour per node), and `callback.aa_cache_seconds`
(default 60) removes one more.

---

## Entry point reference

Both actions require a valid API key. `Content-Type: text/xml`.

| URL | Handler | Purpose |
| --- | --- | --- |
| `index.php` or `?action=callback` | `run_callback()` | Inbound DID handler; emits `<Gather>` or `<Forward>` |
| `?action=gather` | `run_gather_handler()` | Digit handler for the emitted `<Gather action>` |

Aliases kept for convenience: `cb`, `main` → callback; `callbackfeaturev2`,
`featurev2`, `digits` → gather.

`callback` parameters: `AccountUser`, `AccountDomain`, `NmsAni`, `NmsDnis`
(GET or POST; cookies are deliberately ignored).

`gather` parameters: `Digits`, `did` (reconnect target), `cdext` (CallDecider),
`domain`, `apikey`.

Responses are always a single document:

```xml
<Response><Forward>sip:9001@example.com</Forward></Response>
<Response><Gather numDigits="6" timeout="5" action="https://…/index.php?action=gather&amp;did=1234&amp;cdext=9001&amp;domain=example.com&amp;apikey=…"><Say language="en-US" voice="amy">Would you like to reconnect to extension <say-as interpret-as="digits">1234</say-as>? Press 1 for yes, or 2 for no.</Say></Gather><Hangup/></Response>
<Response><Hangup/></Response>
```

Digit handling: `1` → reconnect to `did`; `2` → `cdext`; 3+ digits → dial that
extension; timeout or an unexpected digit → `cdext` (the call is never dropped
because a caller hesitated).

---

## Configuration reference

See `private/config.example.php` for the annotated list. Summary:

| Key | Default | Notes |
| --- | --- | --- |
| `app.timezone` | `UTC` | log timestamps only; CDR windows are UTC |
| `app.log_file` | *(off)* | optional file; errors always reach the PHP error log |
| `app.access_log` | `false` | one line per call (action, IP, did, digits) |
| `app.max_execution` | `25` | hard cap for the request, seconds |
| `security.api_key` | — | **required**, ≥ 16 chars |
| `security.allowed_ips` | `[]` | switch IPs / CIDRs; empty = any source |
| `security.allowed_methods` | `GET, POST` | anything else → 405 |
| `security.allow_header_key` / `allow_query_key` | `true` / `true` | where the key may come from |
| `security.require_https` | `false` | reject plain HTTP (applies to admin.php too) |
| `security.admin_key` | — | **required for admin.php**, ≥ 16 chars; 404 when unset |
| `security.admin_allowed_ips` | `[]` | optional allow-list for the admin UI |
| `security.admin_session_seconds` | `1800` | admin session idle timeout |
| `security.admin_max_failures` / `admin_lockout_seconds` | `5` / `900` | sign-in throttle |
| `domains.dir` | `<private>/domains` | per-domain JSON configs; never web-accessible |
| `domains.audit_log` | `true` | append sign-ins/config changes to `audit.log` |
| `ns_api.servers` | — | failover order |
| `ns_api.request_timeout` / `connect_timeout` / `token_timeout` | `10` / `5` / `8` | per attempt |
| `ns_api.total_budget_seconds` | `12` | across all servers/attempts |
| `ns_api.token_cache_dir` | temp dir | **set this** to a private path |
| `ns_api.credentials` | — | or env vars / legacy constants |
| `callback.default_lookup_minutes` | `30` | used when option 3 is unset |
| `callback.max_lookup_minutes` | `1440` | clamp |
| `callback.aa_cache_seconds` | `60` | `0` disables the AA cache |
| `callback.fallback_extension` | *(off)* | last-resort target when the API is unreachable |
| `callback.default_call_decider_extension` | *(off)* | used when `AccountUser` is missing |
| `callback.default_message_url` | `default` | TTS; set a prompt file to use `<Play>` |
| `callback.public_url` | *(derived)* | absolute URL of `index.php` for `<Gather action>`; set it if the switch reaches the app through another hostname/proxy |
| `callback.legacy_domain` / `legacy_audio` | *(off)* / `jewishcallbackivr2.wav` | the standalone prompt replay; set the domain to enable |

---

## Tests

```bash
php tests/run-tests.php
```

155 assertions, no dependencies, no network: the suite stubs the NS-API and
drives the real handlers. It covers input sanitising (path traversal, CRLF,
markup, over-long values), the 30-minute window and every option-3 form,
prompt identifiers, the API key/IP/TLS rules of the entry point, action
routing, the gather digit matrix, the legacy prompt replay, all failover paths
(second server, 401 refresh, total outage, cached token), the strict
forward-target rules and asserts that every emitted document is well-formed XML.

---

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| HTTP 403, `<Response><Hangup/></Response>` | Missing/wrong API key, or source IP not in `security.allowed_ips` |
| HTTP 500 on every request | `security.api_key` missing or < 16 characters, `config.php` not found/not readable, or bootstrap not found |
| HTTP 404 | `action` unknown, or `index.php` routing untouched while the switch still calls the old `cb.php` URL |
| Call goes straight to the CallDecider | No CDR match inside the window (expected), option-5 excludes the extension, or the caller-ID/DID did not match |
| "We are unable to complete your call" | API unreachable on all nodes *and* no `callback.fallback_extension` — set it to the CallDecider |
| One API node fails every call | It is marked bad only for that request; check the log line `NS-API request failed (…)` for the node's error |
| Prompts never play | `option-4` audio not reachable, or the DID's application URL still points at the old file |
| Token file permission errors | `ns_api.token_cache_dir` not writable by the web-server user |
| `admin.php` answers 404 | `security.admin_key` missing or shorter than 16 characters (fail closed, by design) |
| `admin.php` answers 403/429 | Source IP outside `security.admin_allowed_ips`, or too many failed sign-ins — wait `security.admin_lockout_seconds` |
| Config not saved ("Could not write…") | `domains.dir` (or `<private>/domains`) not writable by the web-server user |
| Domain still uses AA options after saving | The domain field on the config page must match `AccountDomain` exactly (lowercase host) |

Logs: PHP error log plus optional `app.log_file`. The API key and the OAuth
client secret are never logged.

---

## Security notes

* Only `index.php` is public. The application, config, cache and logs live
  outside the docroot; the shipped `.htaccess` files deny them if that ever
  changes, and every private file refuses to run without `CALLBACK_APP`.
* Every request is authenticated (constant-time compare) and fail-closed:
  a misconfigured key stops all traffic rather than allowing any.
* All parameters are pattern-validated before use; all output is XML-escaped,
  including CDR-sourced names, so the phone switch can never receive injected
  XML or headers.
* API URLs are built from validated values with `rawurlencode()`, so no
  parameter can smuggle extra query arguments.
* TLS is verified (`CURLOPT_SSL_VERIFYPEER`, `verify_peer`) — no
  `verify => false` anywhere. Responses are `no-store` and `nosniff`.
* `security.allowed_ips` uses `REMOTE_ADDR` only; `X-Forwarded-For` is not
  trusted unless you explicitly enable `require_https`'s proxy check for TLS.
* The single deliberate trade-off: the API key appears in the `<Gather action>`
  URL (switch limitations). Rotate the key if that URL is ever leaked, and
  restrict the endpoint by IP where possible.
