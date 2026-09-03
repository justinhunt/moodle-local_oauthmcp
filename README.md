# local_oauthmcp

A shared **OAuth 2.1 authorization server** for MCP-enabled Moodle plugins.

AI agents can authenticate with an MCP server using a bearer token directly or obtaining one via OAuth. Moodle has a system for generating web service tokens and these work fine with MCP as bearer tokens if the agent supports it. But recently the UI for many agents only accept OAuth information (ie not a token) e.g Claude.ai (web), ChatGPT connectors, and Google Gemini Spark.  This plugin uses the existing Moodle web-service token system but wraps it in an OAuth 2.1 server.

It is designed to be used by other MCP-enabled plugins (e.g. `mod_minilesson`, `local_hellomcp`). The MCP-enabled plugin will already support token based authentication, and this plugin (local_oauthmcp) gives it a way to wrap that in an OAuth server. The MCP-enabled plugin will return OAuth connection information to the AI agent (ie the MCP client) that points to the URLs exposed by this plugin.

- **Type:** `local` plugin (`public/local/oauthmcp`)
- **Requires:** Moodle 4.3+ (`2023100900`).
- **Maturity:** alpha (`0.2.0`).
- **Licence:** GNU GPL v3 or later.

## Installation

Install this plugin so that AI agent that uses OAuth — Claude.ai (web), ChatGPT, 
or Google Gemini Spark — can authorize and use an MCP plugin such as
 **mod_minilesson** or **local_hellomcp**. `local_oauthmcp` does nothing on
its own; it needs at least one such *consumer* plugin (eg Poodll Minilesson) installed alongside it.

### 1. Install the plugin

Place the code at `local/oauthmcp` under the Moodle web root (the `public/` subdirectory on
the Moodle 5.x layout) — through **Site administration ▸ Plugins ▸ Install plugins**, or by
unpacking the ZIP / cloning the repository there by hand — then finish the upgrade at **Site
administration ▸ Notifications** (or run `php admin/cli/upgrade.php`).

There are no settings to configure. Though in some cases you may need to use the plugin's "Manage OAuth Clients" page to get a client id and secret (these are not the same as the Moodle username and password). Read on ..

### 2. Configure agent to use it

Each agent has its own UI for adding MCP tools (they may call them "plugins" or "connectors"). Once you find it the steps 
are basically the same as the example agents listed below.

You will need to provide the MCP URL of the Moodle plugin (consumer). It is sometimes called a resource. It will probably look like:
`https://[path to moodle]/[path to plugin]/mcp.php`

- **ChatGPT** — Add the consumer plugin's MCP URL in the connector's settings. 
  Login into Moodle if asked, and approve the "Allow" consent screen when it appears.
- **Claude.ai (web)** — Add the consumer plugin's MCP URL in the connector's own settings. 
  Login to Moodle if asked, and approve the "Allow" consent screen when it appears.
  NB Web server config. probably needed 
- **Google Gemini Spark** — Needs a client created by hand by the Moodle site admin.
  It and similar agents will give you  a redirect URL. 
  The admin should enter that at **Site administration ▸ Plugins ▸ Local plugins ▸ Manage OAuth clients**. 
  The plugin will then give you a client ID and secret to paste into Spark. Each Moodle user can use the same client ID and secret.
  After that proceed as for ChatGPT and approve access on the consent screen. NB Web server config. probably needed 

If it still cannot complete authorization after doing the steps above, the site probably 
needs some web-server configuration. See [Web server configuration](#web-server-configuration).

## Web server configuration

Try connecting first — you may need none of this. 

### a) Let PHP see the `Authorization` header

**Symptom:** Nothing connects.

**Cause:** Some web server PHP extensions by default strip the headers we need. PHP-FPM is one. 

**Apache** — inside the `<Directory>` block for the Moodle web root in a virtual hosts file, or in `.htaccess` :

```apache
CGIPassAuth On
```

**nginx** — in the `location ~ \.php$` block:

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```


### b) Domain-root `.well-known` rewrites

**Symptom:** ChatGPT connects, but Claude or Gemini Spark never find the authorization
server.

**Cause:** Claude and Spark currently (Sept 2026) only look for discovery documents at 
fixed domain-root paths such as `/.well-known/oauth-authorization-server/local/oauthmcp/oauth_metadata.php`.
The plugin serves those same documents from its own path, so such agents need a rewrite to find them.

**Apache** —Two `RewriteRules` (1 per discovery document). And one `RewriteRule` per consumer plugin:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Authorization server metadata — one per site
    RewriteRule ^/\.well-known/oauth-authorization-server/local/oauthmcp/oauth_metadata\.php$ /local/oauthmcp/oauth_metadata.php [L]
    RewriteRule ^/\.well-known/openid-configuration/local/oauthmcp/oauth_metadata\.php$       /local/oauthmcp/oauth_metadata.php [L]

    # Protected-resource metadata — one line per consumer plugin (CHANGE THIS for mod/minilesson or other plugin)
    RewriteRule ^/\.well-known/oauth-protected-resource/local/hellomcp/mcp\.php$ /local/hellomcp/oauth_resource_metadata.php [L]
</IfModule>
```

Always keep the `<IfModule mod_rewrite.c>` wrapper: a bare `RewriteEngine` with the module
missing returns `500` for the whole site. Connector discovery behaviour changes without
notice, so re-test all three periodically.

### Full vhost example

`CGIPassAuth` and the rewrites together, for a site with one consumer plugin
(e.g. `local_hellomcp` or `mod_minilesson`). Merge into your real vhost rather than copying verbatim.

```apache
<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName moodle.example.com
    # Moodle web root: the directory with local/, mod/, config.php
    # (the public/ subdirectory on the Moodle 5.x layout).
    DocumentRoot /var/www/moodle/public

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/moodle.example.com.pem
    SSLCertificateKeyFile /etc/ssl/private/moodle.example.com.key

    <Directory /var/www/moodle/public>
        Require all granted
        AllowOverride None
        CGIPassAuth On
    </Directory>

    <IfModule mod_rewrite.c>
        RewriteEngine On
        RewriteRule ^/\.well-known/oauth-authorization-server/local/oauthmcp/oauth_metadata\.php$ /local/oauthmcp/oauth_metadata.php [L]
        RewriteRule ^/\.well-known/openid-configuration/local/oauthmcp/oauth_metadata\.php$       /local/oauthmcp/oauth_metadata.php [L]
        # One line per consumer plugin: (CHANGE THIS for /mod/minilesson or other plugin)
        RewriteRule ^/\.well-known/oauth-protected-resource/local/hellomcp/mcp\.php$ /local/hellomcp/oauth_resource_metadata.php [L]
    </IfModule>

    # ... your normal Moodle vhost directives (PHP handler, logging, etc.)
</VirtualHost>
</IfModule>
```

### Full `.htaccess` example

Use this only when you can't edit the vhost. It needs `AllowOverride FileInfo AuthConfig`
(or `All`) already granted for the Moodle web root — the `AllowOverride None` above would
disable it. Put the file at the Moodle web root (`public/.htaccess` on the 5.x layout):
create it if there is none, otherwise append to the existing .htaccess content.

```apache
# Let PHP see the Authorization: Bearer header (Apache >= 2.4.13)
CGIPassAuth On

<IfModule mod_rewrite.c>
    RewriteEngine On
    # Patterns have NO leading slash here — Apache strips the directory prefix in .htaccess.
    RewriteRule ^\.well-known/oauth-authorization-server/local/oauthmcp/oauth_metadata\.php$ /local/oauthmcp/oauth_metadata.php [END]
    RewriteRule ^\.well-known/openid-configuration/local/oauthmcp/oauth_metadata\.php$       /local/oauthmcp/oauth_metadata.php [END]
    # One line per consumer plugin. CHANGE THIS for /mod/minilesson or other plugin
    RewriteRule ^\.well-known/oauth-protected-resource/local/hellomcp/mcp\.php$ /local/hellomcp/oauth_resource_metadata.php [END]
</IfModule>
```

Two differences from the vhost form, both required: the `RewriteRule` patterns lose their
leading `/`, and the flag is `[END]` not `[L]` (so `.htaccess` isn't re-processed after the
internal rewrite).

## Glossary

### Terms unique to this README

- **Insert form** — a `.well-known` discovery URL with the resource's (or the authorization
  server's own) path spliced in right after the well-known segment, e.g.
  `/.well-known/oauth-protected-resource/mod/yourplugin/mcp.php`. Confirmed (2026-08-29) to be
  what Claude and Gemini Spark request, and the form the [Web server
  configuration](#web-server-configuration) rewrites target.
- **Bare form** — a `.well-known` discovery URL with no resource path at all, e.g.
  `/.well-known/oauth-protected-resource`. No tested client needs it, and with more than one
  registered resource it can only point at one of them — so this README uses the insert form
  instead.
- **Append form** — the well-known segment appended onto the *resource's own URL*, e.g.
  `/mod/yourplugin/mcp.php/.well-known/openid-configuration`. What ChatGPT requests; handled
  entirely in code (step 4 below), no web server config needed.
- **`mintcallback`** — the function a consuming plugin declares in step 2 that returns a
  Moodle web-service token for a user; this is what `/token` hands back as the OAuth access
  token.
- **`revokecallback`** — the optional function a consuming plugin declares to invalidate the
  token `mintcallback` produced, called whenever an OAuth grant is torn down (see
  "Revocation").
- **CIMD (Client ID Metadata Document)** — a draft OAuth extension where `client_id` is
  itself an `https://` URL pointing to a small JSON document describing the client, letting a
  client (e.g. Claude) skip Dynamic Client Registration entirely.

### OAuth / RFC terms

- **Authorization server (AS)** — the party that authenticates the user and issues tokens;
  that's this plugin.
- **Protected resource** — the API being accessed on the user's behalf; a consuming plugin's
  `mcp.php`.
- **Resource** — in this document, always the OAuth sense above (a protected API endpoint),
  not a Moodle "resource" activity.
- **Bearer token** — a token that grants access to whoever holds it, sent as
  `Authorization: Bearer <token>`.
- **Access token** — the credential a client sends on every API call; here, a real Moodle
  web-service token.
- **Refresh token** — a longer-lived credential exchanged at `/token` for a fresh access
  token, without the user re-authorizing.
- **Refresh token family** — the set of refresh tokens produced by rotating a single original
  grant; reusing an already-rotated token revokes the whole family (theft/replay detection).
- **PKCE (Proof Key for Code Exchange)** — a challenge/verifier pair that binds an
  authorization code to the client that requested it; `S256` is the hashed variant, mandatory
  here.
- **DCR (Dynamic Client Registration)** — a client registering itself with the authorization
  server at runtime (`/register`), rather than an admin creating it by hand.
- **Confidential client** — a client that can hold a secret (e.g. a server-side app); can
  authenticate itself with `client_secret_post`.
- **Public client** — a client that can't safely hold a secret (e.g. a browser-based or
  native app); relies on PKCE instead. This plugin's DCR only ever creates public clients.
- **Scope** — a named bundle of access a token grants; descriptive here, one per registered
  resource.
- **Consent screen** — the page a logged-in user sees at `/authorize`, where approving the
  request is the plugin's actual security boundary.

## What this plugin is not

- **Not an OAuth client.** It does not log Moodle users in to external identity providers.
  That is Moodle core's `auth_oauth2`, which points the opposite direction.
- **Not a token issuer of its own.** The "access token" returned from `/token` is a real
  Moodle web-service token — a genuine `external_tokens` row (permanent type) minted by the
  consumer plugin's `mintcallback` via core's `external_generate_token()`, the same routine
  the "Create token" admin button uses. 
  

## What this plugin does

Once installed, it exposes a complete authorization-server surface at
`/local/oauthmcp/…`, handled centrally so no consuming plugin has to:

| Endpoint | Purpose |
| --- | --- |
| `oauth_metadata.php` | RFC 8414 authorization-server metadata (issuer, endpoints, supported scopes). Site-wide singleton. |
| `oauth_register.php` | RFC 7591 Dynamic Client Registration. Public clients only (`token_endpoint_auth_method: "none"`), open and unauthenticated. |
| `oauth_authorize.php` | `/authorize` — the consent screen. PKCE **S256 mandatory**; `plain` or missing `code_challenge` is rejected. Resolves CIMD (`client_id`-as-URL) clients. |
| `oauth_token.php` | `/token` — `authorization_code` and `refresh_token` grants. Calls the consuming plugin's `mintcallback` to produce the real token. |
| `manageoauthclients.php` | Admin page (Site admin ▸ Plugins ▸ Local plugins) for manually creating client ID and Secret— the fallback path for Google/Gemini and similar clients, whose DCR always requests a confidential client and is refused by design. |

Also handled : 
* refresh-token rotation with reuse/theft detection (reuse revokes the whole rotation family)
* cooperative teardown of the underlying web-service token on revocation (via your optional `revokecallback` — see "Revocation" below), 
* CIMD document fetch/validation (through Moodle's `\curl`, so the `curl_security_helper` SSRF blocklist applies)
* an hourly `oauth_cleanup` scheduled task
* three backup-excluded DB tables (`local_oauthmcp_clients`, `_codes`, `_refresh`).

### Security model

Registering a client via DCR (Dynamic Client Registration) or via this plugin's manageoauthclients.php admin page does not grant the client any access. The real security check is a logged-in Moodle user clicking "Allow" on the /authorize consent screen. That authorizes the AI agent to act on their behalf. Access to that screen is restricted by a capability the consumer plugin declares (e.g. local/hellomcp:usemcp). That is checked at CONTEXT_SYSTEM — so a user must be granted it site-wide (site admins bypass it). It's re-checked on every token refresh.

## For plugin developers

Everything below is for developers adding OAuth support to their own MCP-enabled plugin. If
you are connecting an agent to an  MCP plugin, [Installation](#installation) and 
[Web server configuration](#web-server-configuration) above are all you need.

### The public API

`\local_oauthmcp\api` is the only class a consuming plugin should call:

```php
\local_oauthmcp\api::authorization_server_url();       // issuer / metadata URL, for WWW-Authenticate
\local_oauthmcp\api::resource_metadata($resourceurl);  // RFC 9728 doc for one registered resource (or null)
\local_oauthmcp\api::authorization_server_metadata();  // RFC 8414 doc for this server
```

Everything else in `classes/` (`oauth\registry`, `oauth\helper`, `oauth\revoker`) is internal.

### Integrating your own plugin

A consuming plugin can be any Moodle component. There are four steps:

#### 1. Install this plugin

Install `local_oauthmcp` as described under [Installation](#installation). Your plugin's core
functionality must not depend on it being present (see step 4).

#### 2. Declare your resource in your `lib.php`

Add a `<frankenstyle name>_mcp_oauth_resources()` function, that lets Moodle discover it.

```php
function mod_yourplugin_mcp_oauth_resources(): array {
    global $CFG;
    return [
        [
            // Exact URL the client sends as the OAuth `resource` parameter (RFC 8707).
            // Must match byte-for-byte what your mcp.php's metadata/challenge advertise.
            'resource'     => $CFG->wwwroot . '/mod/yourplugin/mcp.php',

            // Single scope name for this resource.
            // A single short string naming the bundle of access this resource represents.
            // mod_minilesson uses 'aigen'. local_hellomcp uses 'hellomcp'.
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

#### 3. Implement `revoke_tokens()` and `mint_or_reuse_token()`

These are used to get the actual web-service tokens that OAuth client needs to access the MCP service, and to revoke them when necessary. A Full example is provided below.

`mintcallback` must return a **token string** for one of *your own* Moodle external
services — the service whose functions your `mcp.php` brokers, declared in your plugin's
`db/services.php`. This is the token the AI client will send back as a bearer token on every
MCP request.

`revokecallback()` is optional and returns nothing — its job is to invalidate what mintcallback handed out, normally a one-line `$DB->delete_records('external_tokens', …)` for the same (userid, service) rows. It exists because that access token is a real web-service token this plugin (local_oauthmcp) didn't create and can't delete on its own. Without the revokecallback() it stays usable until its own validuntil, even after the user's OAuth grant is gone. \local_oauthmcp\oauth\revoker calls it when:

* refresh-token reuse/theft is detected;
* the capability is withdrawn;
* a refresh token passes its outer lifetime;
* an admin deletes a client;
* a user requests deletion of their data;

But this is only once that user has no other live grant for the same resource, since a consumer typically reuses one shared token across every client. It's best-effort: a throw is downgraded to a DEBUG_DEVELOPER notice, there is no retry, and it can be called when there's already nothing to delete — so keep it a plain, idempotent delete.

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

#### 4. Point your resource endpoint's discovery at this plugin

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

#### Division of responsibility

| Handled centrally here — you never touch it | Stays in your plugin |
| --- | --- |
| `/authorize`, `/token`, `/register` (DCR) | `mcp.php` / REST front-end (wraps *your* external functions) |
| Site-wide authorization-server metadata, PKCE enforcement | `mint_or_reuse_token()` and your service's function set |
| CIMD resolution + validation | The `lib.php` resource-declaration callback |
| Refresh-token rotation + reuse detection | Thin `oauth_resource_metadata.php` |
| Deciding *when* a grant is revoked, and calling your `revokecallback` | `revoke_tokens()` — actually invalidating the minted token |
| Manual client admin UI, the three DB tables, caches, hourly cleanup | Your own capability + admin role page |

### Revocation

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

### Multi-resource behaviour

Because the server is shared, more than one plugin can register a resource on one site.
One consequence: if a client omits the OAuth `resource` parameter, `registry::resolve()`
falls back to "the one registered resource" **only when exactly one is registered**. With
two or more, an omitted `resource` fails closed (`invalid_target`) rather than guessing.

### Reference consumers

- **`local_hellomcp`** — a minimal, working example of an MCP enabled Moodle plugin with Oauth support. Start here if you're wiring up a new plugin.
  See it at: [https://github.com/justinhunt/moodle-local_hellomcp](https://github.com/justinhunt/moodle-local_hellomcp)

- **`mod_minilesson`** — the original real-world (production) consumer.
  See it at: [https://github.com/justinhunt/moodle-mod_minilesson](https://github.com/justinhunt/moodle-mod_minilesson)
