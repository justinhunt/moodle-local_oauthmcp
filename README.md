# local_oauthmcp

A shared **OAuth 2.1 authorization server** for MCP-enabled Moodle plugins.

AI agents can authenticate with an MCP server using a bearer token directly or obtaining one via OAuth. Moodle has a system for generating web service tokens and these work fine with MCP as bearer tokens if the agent supports it. But recently AI clients connector UI settings only accept OAuth information (ie not a token) e.g Claude.ai (web), ChatGPT connectors, and Google Gemini Spark.  This plugin uses the existing Moodle web-service token system but wraps it in an OAuth 2.1 server.

It is designed to be used by other MCP-enabled plugins (e.g. `mod_minilesson`). The MCP-enabled plugin will already support token based authentication, and this plugin (local_oauthmcp) gives it a way to wrap that in an OAuth server. The MCP-enabled plugin will return OAuth connection information to the AI agent (ie the MCP client) that points to the URLs exposed by this plugin.

- **Type:** `local` plugin (`public/local/oauthmcp`)
- **Requires:** Moodle 4.3+ (`2023100900`).
- **Maturity:** alpha (`0.2.0`).
- **Licence:** GNU GPL v3 or later.

## What it is not

- **Not an OAuth client.** It does not log Moodle users in to external identity providers.
  That is Moodle core's `auth_oauth2`, which points the opposite direction.
- **Not a token issuer of its own.** The "access token" returned from `/token` is a real
  Moodle web-service token — a genuine `external_tokens` row (permanent type) minted by the
  consumer plugin's `mintcallback` via core's `external_generate_token()`, the same routine
  the "Create token" admin button uses. 
  
  NB Because `/token` is sessionless the row is stamped
  with no creator (`creatorid = 0`), so it does **not** appear in *Site admin ▸ Server ▸ Web
  services ▸ Manage tokens* for anyone below full site admin — that page hides tokens you
  didn't create yourself, and `moodle/webservice:managealltokens` is in no role by default.
  Audit and revoke these through the plugin's own flow (see "Revocation"), not that page.

## What it does

Once installed, it exposes a complete authorization-server surface at
`/local/oauthmcp/…`, handled centrally so no consuming plugin has to:

| Endpoint | Purpose |
| --- | --- |
| `oauth_metadata.php` | RFC 8414 authorization-server metadata (issuer, endpoints, supported scopes). Site-wide singleton. |
| `oauth_register.php` | RFC 7591 Dynamic Client Registration. Public clients only (`token_endpoint_auth_method: "none"`), open and unauthenticated. |
| `oauth_authorize.php` | `/authorize` — the consent screen. PKCE **S256 mandatory**; `plain` or missing `code_challenge` is rejected. Resolves CIMD (`client_id`-as-URL) clients. |
| `oauth_token.php` | `/token` — `authorization_code` and `refresh_token` grants. Calls the consuming plugin's `mintcallback` to produce the real token. |
| `manageoauthclients.php` | Admin UI (Site admin ▸ Plugins ▸ Local plugins) for manually creating client ID and Secret— the fallback path for Google/Gemini, whose DCR always requests a confidential client and is refused by design. |

Also handled : 
* refresh-token rotation with reuse/theft detection (reuse revokes the whole rotation family)
* cooperative teardown of the underlying web-service token on revocation (via your optional `revokecallback` — see "Revocation" below), 
* CIMD document fetch/validation (through Moodle's `\curl`, so the `curl_security_helper` SSRF blocklist applies)
* an hourly `oauth_cleanup` scheduled task
* three backup-excluded DB tables (`local_oauthmcp_clients`, `_codes`, `_refresh`).

### Security model

Registering a client via DCR (Dynamic Client Registration) or via this plugin's manageoauthclients.php admin does not grant the client any access. The real security check is a logged-in Moodle user clicking "Allow" on the /authorize consent screen. That authorizes the AI agent to act on their behalf. Access to that screen is restricted by a capability the consumer plugin declares (e.g. mod/minilesson:usemcp). That is checked at CONTEXT_SYSTEM — so a user must be granted it site-wide (site admins bypass it). It's re-checked on every token refresh.

## The public API

`\local_oauthmcp\api` is the only class a consuming plugin should call:

```php
\local_oauthmcp\api::authorization_server_url();       // issuer / metadata URL, for WWW-Authenticate
\local_oauthmcp\api::resource_metadata($resourceurl);  // RFC 9728 doc for one registered resource (or null)
\local_oauthmcp\api::authorization_server_metadata();  // RFC 8414 doc for this server
```

Everything else in `classes/` (`oauth\registry`, `oauth\helper`, `oauth\revoker`) is internal.

## How a plugin becomes a consumer

A consuming plugin can be any Moodle component referred to by its frankenstyle name (`mod_`, `local_`, …). All the code
lives in that plugin; nothing here needs editing. Four pieces:

### 1. Install this plugin

Install `local_oauthmcp` once per site the usual way. Your plugin's core functionality
must not depend on it being present (see step 4).

### 2. Declare your resource in your `lib.php`

Add a `<frankenstyle name>_mcp_oauth_resources()` function. Moodle discovers it via
`get_plugins_with_function('mcp_oauth_resources', 'lib.php')` — the same mechanism as
`<component>_extend_navigation()`. 

```php
function mod_yourplugin_mcp_oauth_resources(): array {
    global $CFG;
    return [
        [
            // Exact URL the client sends as the OAuth `resource` parameter (RFC 8707).
            // Must match byte-for-byte what your mcp.php's metadata/challenge advertise.
            'resource'     => $CFG->wwwroot . '/mod/yourplugin/mcp.php',

            // Single scope name for this resource.
            // A single short string naming the bundle of access this resource represents. mod_minilesson uses 'aigen'.
            // Descriptive only, it does not affect access
            'scope'        => 'yourscope',

            // Moodle capability, checked at CONTEXT_SYSTEM — at the consent screen
            // AND again on every refresh_token grant.
            'capability'   => 'mod/yourplugin:usemcp',

            // function(int $userid): string — returns a REAL Moodle web-service token.
            // May throw if the user is no longer permitted.
            'mintcallback' => [\mod_yourplugin\facade::class, 'mint_or_reuse_token'],

            // Optional: function(int $userid): void — called when a grant is revoked, so you
            // can drop the web-service token you minted instead of leaving it live until it
            // expires. See "Revocation" below.
            'revokecallback' => [\mod_yourplugin\facade::class, 'revoke_tokens'],

            // Optional: shown on the consent screen instead of generic wording.
            'description'  => get_string('oauthresourcedescription', 'mod_yourplugin'),
        ],
    ];
}
```

Malformed entries (missing key, uncallable `mintcallback`) are skipped with a
`DEBUG_DEVELOPER` `debugging()` notice — so that one bad plugin does not take down the server. A
declared-but-uncallable `revokecallback` is the one exception: it is ignored (with the same
notice) rather than taking the whole resource down, since it is optional.

### 3. Implement `revoke_tokens()` and `mint_or_reuse_token()`

Full example is provided below (a `facade` class in your plugin — modelled on `mod_minilesson`'s):

`mintcallback` must return a **token string** for one of *your own* Moodle external
services — the service whose functions your `mcp.php` brokers, declared in your plugin's
`db/services.php`. This is the token the AI client will send back as a bearer token on every
MCP request, so it has to be a row your normal request-time auth path already accepts.

`revokecallback()` is optional and returns nothing — its job is to invalidate what mintcallback handed out, normally a one-line `$DB->delete_records('external_tokens', …)` for the same (userid, service) rows. It exists because that access token is a real web-service token this plugin didn't create and can't delete on its own, so without the hook it stays usable until its own validuntil even after the user's OAuth grant is gone. \local_oauthmcp\oauth\revoker calls it when a grant is torn down — refresh-token reuse/theft detection, the capability being withdrawn, a refresh token passing its outer lifetime, an admin deleting the client, or a privacy delete request — but only once that user has no other live grant for the same resource, since a consumer typically reuses one shared token across every client. It's best-effort: a throw is downgraded to a DEBUG_DEVELOPER notice, there is no retry, and it can be called when there's already nothing to delete — so keep it a plain, idempotent delete.

```php
namespace mod_yourplugin\local;

defined('MOODLE_INTERNAL') || die();

class facade {

    /** The external service (db/services.php) whose functions mcp.php brokers. */
    const SERVICE_SHORTNAME = 'yourpluginservice';

    /** The enabled external_services row for that service, or null. */
    public static function get_service(): ?\stdClass {
        global $DB;
        $service = $DB->get_record('external_services', [
            'shortname' => self::SERVICE_SHORTNAME,
            'component' => 'mod_yourplugin',
            'enabled'   => 1,
        ]);
        return $service ?: null;
    }

    /**
     * Get, or mint, a real web service token for $userid on that service, for
     * local_oauthmcp to hand back from /token as the OAuth access token.
     *
     * @throws \moodle_exception if the service is missing, or (from
     *         external_generate_token) if $userid lacks the capability — local_oauthmcp
     *         catches that and returns an OAuth `invalid_grant`.
     */
    public static function mint_or_reuse_token(int $userid): string {
        global $DB, $CFG;

        $service = self::get_service();
        if (!$service) {
            throw new \moodle_exception('cannotfindwebservice', 'webservice', '', self::SERVICE_SHORTNAME);
        }

        // Reuse the newest non-expired permanent, non-session-bound token if there is one,
        // so re-authorizing the same user doesn't pile up external_tokens rows.
        $existing = $DB->get_records('external_tokens', [
            'userid'            => $userid,
            'externalserviceid' => $service->id,
            'tokentype'         => EXTERNAL_TOKEN_PERMANENT,
            'sid'              => null,
        ], 'timecreated DESC', '*', 0, 1);
        $token = reset($existing);
        if ($token && (empty($token->validuntil) || $token->validuntil > time())) {
            return $token->token;
        }

        // Otherwise mint one. external_generate_token() re-checks the service's required
        // capability for $userid and throws if it's missing.
        require_once($CFG->libdir . '/externallib.php');
        $validuntil = empty($CFG->tokenduration) ? 0 : (time() + $CFG->tokenduration);
        return external_generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            $userid,
            \context_system::instance(),
            $validuntil
        );
    }

    /**
     * revokecallback (optional, see step 2): drop the tokens minted above so a revoked
     * OAuth grant doesn't leave a usable web service token behind until it expires.
     */
    public static function revoke_tokens(int $userid): void {
        global $DB;

        $service = self::get_service();
        if (!$service) {
            return;
        }
        $DB->delete_records('external_tokens', [
            'userid'            => $userid,
            'externalserviceid' => $service->id,
            'tokentype'         => EXTERNAL_TOKEN_PERMANENT,
            'sid'              => null,
        ]);
    }
}
```

Use `external_generate_token()`, **not** `external_generate_token_for_current_user()` — the
latter adds two gates that are wrong here: it excludes site admins, and it requires
`moodle/webservice:createtoken`. The only gate this flow should enforce is the `capability`
you declared in step 2, which `external_generate_token()` already re-checks internally
(throwing `nocapabilitytousethisservice`, which `oauth_token.php` turns into an OAuth
`invalid_grant`). Set an explicit `$validuntil` rather than `0`/forever — the refresh-token
layer already handles renewal.

### 4. Point your resource endpoint's discovery at this plugin

Your *protected resource* is your MCP endpoint URL — e.g.
`https://your.moodle/mod/yourplugin/mcp.php`. A client that hits it without a valid token has
to *discover* where to authorize. There are three requests it may make, and all three are
answered **from your own plugin** (guarded by `class_exists('\local_oauthmcp\api')`, so when
this plugin is absent you simply answer "no OAuth here" and the static-token path is
unaffected).

**(a) A `401` that points at your discovery document.** When an MCP request arrives with a
missing or invalid token, send the challenge header before the `401` body:

```php
function yourplugin_mcp_send_auth_challenge(): void {
    global $CFG;
    if (class_exists('\local_oauthmcp\api')) {
        header(
            'WWW-Authenticate: Bearer resource_metadata="'
                . $CFG->wwwroot . '/mod/yourplugin/oauth_resource_metadata.php"',
            true,
            401
        );
        return;
    }
    header('WWW-Authenticate: Bearer', true, 401); // no OAuth flow to advertise
}
```

**(b) `.well-known` suffixes appended to your resource URL.** Some clients (observed:
ChatGPT) request discovery as `GET /mod/yourplugin/mcp.php/.well-known/openid-configuration`
— the well-known path tacked onto *your* URL, not the domain root. Answer it on `GET`, from
`PATH_INFO`, before your normal "POST only" / 405 response:

```php
function yourplugin_mcp_maybe_send_wellknown_discovery(): void {
    global $CFG;
    if (!class_exists('\local_oauthmcp\api')) {
        return; // fall through to your usual GET handling
    }
    $pathinfo = $_SERVER['PATH_INFO'] ?? '';
    $resourceurl = $CFG->wwwroot . '/mod/yourplugin/mcp.php';

    if (strpos($pathinfo, 'oauth-protected-resource') !== false) {
        $data = \local_oauthmcp\api::resource_metadata($resourceurl);
        if ($data !== null) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($data, JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
    if (strpos($pathinfo, 'oauth-authorization-server') !== false
            || strpos($pathinfo, 'openid-configuration') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            \local_oauthmcp\api::authorization_server_metadata(),
            JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}
```

**(c) `oauth_resource_metadata.php`** — a standalone file next to your `mcp.php`, the RFC
9728 document that (a)'s header points at:

```php
<?php
define('NO_DEBUG_DISPLAY', true);
define('NO_MOODLE_COOKIES', true);
require(__DIR__ . '/../../config.php');

if (!class_exists('\local_oauthmcp\api')) {
    http_response_code(404); // no authorization server on this site
    die;
}
$data = \local_oauthmcp\api::resource_metadata($CFG->wwwroot . '/mod/yourplugin/mcp.php');
if ($data === null) {
    http_response_code(503); // local_oauthmcp installed but your resource not visible yet — purge caches
    die;
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
```

Wire (a) and (b) into `mcp.php`: call `yourplugin_mcp_maybe_send_wellknown_discovery()` at
the top of the `GET` branch, and `yourplugin_mcp_send_auth_challenge()` immediately before
each `401` you return for a missing/invalid token. Do the same in any REST front-end.

What the two API calls return: `resource_metadata($url)` is the RFC 9728 document (your
resource URL plus the one authorization server that serves it); `authorization_server_metadata()`
is the RFC 8414 document (this plugin's `/authorize`, `/token`, `/register` URLs and the
union of registered scopes). Both are public, non-sensitive metadata.

Keep `oauth_resource_metadata.php` in *your* plugin, not in `local_oauthmcp` — the client
reaches it at a URL your own `401` chose (the `resource_metadata="…"` value), so it can't
live at one fixed central path.

After adding or changing any of this, **purge caches** — `local_oauthmcp` rebuilds its
resource registry from `get_plugins_with_function()` scans, so a newly declared resource
returns `503` from (c) until you do.

### Division of responsibility

| Handled centrally here — you never touch it | Stays in your plugin |
| --- | --- |
| `/authorize`, `/token`, `/register` (DCR) | `mcp.php` / REST front-end (wraps *your* external functions) |
| Site-wide authorization-server metadata, PKCE enforcement | `mint_or_reuse_token()` and your service's function set |
| CIMD resolution + validation | The `lib.php` resource-declaration callback |
| Refresh-token rotation + reuse detection | Thin `oauth_resource_metadata.php` |
| Deciding *when* a grant is revoked, and calling your `revokecallback` | `revoke_tokens()` — actually invalidating the minted token |
| Manual client admin UI, the three DB tables, caches, hourly cleanup | Your own capability + admin role page |

## Server configuration (site admin)

In an ideal world you should not have to add any web server settings so that Oauth MCP works. But a couple of things currently can break, and they need to be papered over with some webserver config. You should wait until it breaks before doing any of this config, it might work for you without doing anything special.


### a) Let PHP see the `Authorization` header

OAuth clients send the access token as `Authorization: Bearer <token>`. Apache in front of
PHP-FPM (`mod_proxy_fcgi`) commonly drops that header before PHP sees it — and the same
symptom has also turned up on a plain `mod_php` dev container, for an unrelated reason.
Either way, if you have this problem: every authenticated MCP call is rejected with a `401` despite carrying a valid
token, while the same token sent as `X-API-Key` gets through.

Fix, in the `<Directory>` for the Moodle docroot (or `.htaccess`):

```apache
CGIPassAuth On
```

`CGIPassAuth`'s context is in a `directory` block in a virtual hosts file, or in an `.htaccess` file.
Apache errors if you put it bare in `<VirtualHost>`.
It needs Apache ≥ 2.4.13 (any currently supported release). This covers the
FastCGI/PHP-FPM case; per the mod_minilesson notes it also cleared a `mod_php` dev-container
instance of the same symptom.

nginx + PHP-FPM equivalent, in the `location ~ \.php$` block:

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

Check it: `curl` your MCP endpoint with a bogus token once as `-H "X-API-Key: x"` and once
as `-H "Authorization: Bearer x"`. If only the `Authorization` form behaves as though no
token was sent, the header is being stripped. (A consuming plugin's `request_token()` should
also fall back to `REDIRECT_HTTP_AUTHORIZATION` and accept `X-API-Key` — that covers some
`mod_rewrite`/CGI setups, but it does **not** substitute for the fix above against FPM
stripping.)

### b) Domain-root `.well-known` insert-form rewrites

The plugin serves discovery entirely from its own path (step 4) with zero server config, and
that is enough for **ChatGPT**. **Claude** and **Gemini Spark**, as of 2026-08, need one more
thing: they request the RFC 8414 **insert** form at the domain root — the well-known segment
with the resource's (or the authorization server's own) path spliced in right after it, e.g.
`/.well-known/oauth-protected-resource/mod/yourplugin/mcp.php`. That specific form isn't
served by anything in this plugin or in Moodle's own routing (a plugin cannot claim a literal
site-root path — see the design notes if you're curious why), so it needs one `RewriteRule`
per document:

```apache
# Authorization-server metadata — one per site, needed by both Claude and Spark
RewriteRule ^/\.well-known/oauth-authorization-server/local/oauthmcp/oauth_metadata\.php$ /local/oauthmcp/oauth_metadata.php [L]

# Optional belt-and-braces alias — not observed as needed by either client, but RFC 8414 §3.3
# allows either well-known suffix for the same document, and it costs nothing to add.
RewriteRule ^/\.well-known/openid-configuration/local/oauthmcp/oauth_metadata\.php$ /local/oauthmcp/oauth_metadata.php [L]

# Protected-resource metadata — one per registered resource, needed by both Claude and Spark
RewriteRule ^/\.well-known/oauth-protected-resource/mod/yourplugin/mcp\.php$ /mod/yourplugin/oauth_resource_metadata.php [L]
```

That's it — There's no reason to add the *bare* forms e.g. `^/\.well-known/oauth-protected-resource$`

`oauth_metadata.php` deliberately never gates on `PATH_INFO`, so a rewrite reaching it by an
unexpected path is harmless. **Never** ship a bare `RewriteEngine`/`RewriteRule` in a
plugin's own `.htaccess`: if `mod_rewrite` isn't loaded, Apache returns `500` for the entire
directory, not just the well-known paths — wrap it in `<IfModule mod_rewrite.c>` if you ever
must.

Client discovery behaviour changes without notice — it is worth re-testing all three connectors periodically.

### Full vhost example

Both fixes together, for a single-resource site (`mod_minilesson` the only registered
resource). Merge the `CGIPassAuth` line and the rewrite block into your real vhost rather
than using this verbatim.

```apache
<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName moodle.example.com
    # Moodle web root: the directory containing local/, mod/, config.php.
    # On the Moodle 5.x public/ layout that is the public/ subdirectory.
    DocumentRoot /var/www/moodle/public

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/moodle.example.com.pem
    SSLCertificateKeyFile /etc/ssl/private/moodle.example.com.key

    <Directory /var/www/moodle/public>
        Require all granted
        AllowOverride None
        # Let PHP see the OAuth bearer token (Authorization header). Apache >= 2.4.13.
        CGIPassAuth On
    </Directory>

    # Domain-root OAuth discovery shims for Claude and Gemini Spark (confirmed 2026-08-29 —
    # both use the RFC 8414 insert form below, neither needs or falls back to the bare form,
    # so there is nothing here that can collide across multiple registered resources).
    <IfModule mod_rewrite.c>
        RewriteEngine On

        # Authorization-server metadata -> local_oauthmcp (one authorization server per site)
        RewriteRule ^/\.well-known/oauth-authorization-server/local/oauthmcp/oauth_metadata\.php$ /local/oauthmcp/oauth_metadata.php [L]
        # Optional belt-and-braces alias — not observed as needed, costs nothing to keep
        RewriteRule ^/\.well-known/openid-configuration/local/oauthmcp/oauth_metadata\.php$       /local/oauthmcp/oauth_metadata.php [L]

        # Protected-resource metadata -> the one registered resource (mod_minilesson).
        # Add one more line like this per additional registered resource — they never collide.
        RewriteRule ^/\.well-known/oauth-protected-resource/mod/minilesson/mcp\.php$ /mod/minilesson/oauth_resource_metadata.php [L]
    </IfModule>

    # ... your normal Moodle vhost directives (PHP handler / proxy_fcgi, logging, etc.)
</VirtualHost>
</IfModule>
```

The `[L]`-only flags are correct: the substitutions are URL-paths under `DocumentRoot`, so
Apache does an internal subrequest and PHP still handles the `.php`.

## Revocation

Because the "access token" is a real Moodle web-service token this plugin did not create, it
cannot delete it either — once handed out it stays valid until its own `validuntil`, even
after the OAuth grant behind it is gone. Declaring a `revokecallback` closes that gap:
`\local_oauthmcp\oauth\revoker` calls it, as `revokecallback($userid)`, whenever a grant is
revoked:

- **Refresh-token reuse / theft detection** — a replayed, already-rotated token revokes its
  whole rotation family, then fires the callback.
- **Capability withdrawn** — the `capability` is re-checked on every `refresh_token` grant;
  if the user has lost it, the family is revoked and the callback fires. The client must
  re-authorize (and pass the consent-screen capability check) if access is restored.
- **Refresh token past its outer lifetime** (90 days) — the row is retired and the callback
  fires.
- **Admin deletes the client** in `manageoauthclients.php`.
- **Privacy delete request** for the user.

The callback fires **only once the user has no other live grant for that same resource** —
consuming plugins typically mint one shared token per `(user, service)` and reuse it across
every client, so revoking one client's grant must not cut off another that is still valid.
A callback that throws is caught and downgraded to a `DEBUG_DEVELOPER` `debugging()` notice,
so a consumer bug cannot break the `/token` response. It is a plain best-effort hook: there
is no retry queue, so keep it a straightforward DB delete.

## Multi-resource behaviour

Because the server is shared, more than one plugin can register a resource on one site.
One consequence: if a client omits the OAuth `resource` parameter, `registry::resolve()`
falls back to "the one registered resource" **only when exactly one is registered**. With
two or more, an omitted `resource` fails closed (`invalid_target`) rather than guessing.

## Reference consumer

`mod_minilesson` is the reference and first consumer (its own git repo). Its
`mod_minilesson_mcp_oauth_resources()` in `lib.php` and its `oauth_resource_metadata.php` are
the concrete example of the integration pattern above.

## Development

Flat Moodle plugin — no Composer, no build step.

```bash
# PHP syntax check
php -l <file>

# Moodle coding style (run from your Moodle root, with local_codechecker installed)
local/codechecker/vendor/bin/phpcs local/oauthmcp
```

Install or upgrade the normal way (`php admin/cli/upgrade.php`, or the web UI). **Purge
caches after any change that affects resource discovery** — the registry is rebuilt from
`get_plugins_with_function()` scans. The hourly `oauth_cleanup` scheduled task purges expired
authorization codes and long-revoked refresh rows.

There is no automated test suite yet; verification is by installing and exercising the
endpoints, or calling `\local_oauthmcp\oauth\registry` / `\local_oauthmcp\oauth\helper`
directly from a short CLI script.
