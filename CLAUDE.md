# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this plugin is

`local_oauthmcp` is a shared **OAuth 2.1 authorization server** for MCP-enabled Poodll
Moodle plugins. It lets AI clients whose UI is OAuth-only (Claude.ai web, ChatGPT, Google
Gemini Spark connectors) authorize against a plugin's existing web-service token system,
without every Poodll plugin having to build and independently maintain its own AS. It is
**not** an OAuth client, and it is not Moodle core's `auth_oauth2` (which is client-only,
used for logging Moodle users into external IdPs — the opposite direction).

Extracted 2026-08-28 from a full working implementation originally built inside
`mod_minilesson` (~85-90% of that code had zero dependency on minilesson's content model).
`mod_minilesson` is the reference/first consumer — its own separate git repo, typically
installed alongside this plugin on the same Moodle site. This plugin's own dev happens
here, not there. See `README.md` in this repo for the full consumer-integration guide (the
"How a plugin becomes a consumer" section) and the server-configuration/client-behavior
findings — this file focuses on developing *this* plugin, not duplicating that.

**This plugin issues no token type of its own.** An "access token" it hands out via
`/token` is *whatever real Moodle web-service token* the consuming plugin's own
`mintcallback` returns (see Architecture below) — almost always a real `external_tokens`
row minted via core's `external_generate_token()` (the same routine "Manage tokens" uses).
One difference from a UI-created token: `/token` is sessionless, so the row gets
`creatorid = 0` and therefore does *not* show in Web services ▸ Manage tokens for anyone
below full site admin (`moodle/webservice:managealltokens` is in no role by default). That's
the whole design: a consuming plugin's existing request-time auth path needs zero knowledge
of OAuth, whether or not this plugin is even installed.

## Environment & dev workflow

- This plugin lives at `<moodle_root>/local/oauthmcp` — read Moodle core APIs directly from
  `<moodle_root>` instead of guessing at signatures.
- **Install/upgrade:** the normal Moodle way — `php admin/cli/upgrade.php` from the Moodle
  root, or the web upgrade flow. Follow with `php admin/cli/purge_caches.php` after any
  change that affects discovery, since the resource registry (see below) is rebuilt from
  `get_plugins_with_function()` scans.
- `php admin/cli/scheduled_task.php --execute='\local_oauthmcp\task\oauth_cleanup'` runs this
  plugin's hourly cleanup task on demand.
- **phpcs** (Moodle coding style, `moodle` ruleset), with `local_codechecker` installed:
  ```bash
  <moodle_root>/local/codechecker/vendor/bin/phpcs <moodle_root>/local/oauthmcp
  ```
  `phpcbf` sits next to it and auto-fixes anything marked `[x]`.
- **PHP syntax check:** `php -l <file>` — no Composer/build step, this is a flat Moodle
  plugin with no vendored dependencies.
- There is no automated test suite yet. Verification so far has been direct: install/upgrade,
  then curl the endpoints and/or a small throwaway CLI script
  (`define('CLI_SCRIPT', true); require('config.php');`) exercising
  `\local_oauthmcp\oauth\registry`/`helper` directly. See the commit history for examples of
  the exact checks run (metadata JSON shape, a real DCR round-trip, `registry::resolve()`'s
  single-vs-multi-resource fallback, a real `mintcallback` invocation producing a genuine
  `external_tokens` row).

## Architecture

### The registry: how a consuming plugin declares itself

The one thing every other design decision here hangs off: a consuming plugin (any
frankenstyle component — `mod_`, `local_`, etc.) implements a plugin callback in its own
`lib.php`:

```php
function <frankenstyle>_mcp_oauth_resources(): array {
    return [
        [
            'resource' => '<full URL of the protected resource, e.g. .../mcp.php>',
            'scope' => '<single scope name>',
            'capability' => '<Moodle capability, checked at CONTEXT_SYSTEM>',
            'mintcallback' => [SomeClass::class, 'mint_or_reuse_token'], // function(int $userid): string
            'revokecallback' => [SomeClass::class, 'revoke_tokens'], // optional, function(int $userid): void
            'description' => 'optional, shown on the consent screen',
        ],
    ];
}
```

`\local_oauthmcp\oauth\registry::get_resources()` discovers every installed plugin's
declaration via `get_plugins_with_function('mcp_oauth_resources', 'lib.php')` — the same
mechanism Moodle uses for `<component>_extend_navigation()` and similar callbacks. No
explicit registration call, no install-order dependency: a consuming plugin works
identically whether or not this plugin happens to be installed at any given moment.
`registry::normalise()` validates each declared entry and skips (with a `DEBUG_DEVELOPER`
`debugging()` notice) anything missing a required key or with an uncallable `mintcallback`,
rather than fataling the whole server over one malformed plugin. `revokecallback` is
optional; a declared-but-uncallable one is nulled out (same notice) instead of skipping the
whole resource.

`\local_oauthmcp\oauth\revoker` invokes a resource's `revokecallback($userid)` when a grant
is revoked — refresh-token reuse/theft (`oauth_token.php`), capability withdrawn on a
`refresh_token` grant, a refresh token past its outer TTL, `manageoauthclients.php` client
deletion, or a `privacy\provider` delete request. It fires only once the user has no
remaining live refresh row for that resource (consumers mint one shared token per
`(user, service)`), and swallows a throwing callback as a `debugging()` notice so `/token`
still responds. This is the AS's *only* concession that it can't invalidate the token it
handed out; everything else about "issues no token of its own" still holds.

`registry::resolve(?string $resourceurl)` is the one piece of genuinely new logic versus a
single-tenant AS: if a client omits the OAuth `resource` parameter (some do), it falls back
to "the one registered resource" **only when exactly one is registered** — with two or more,
an omitted `resource` now fails closed (`invalid_target`) rather than guessing. Keep this in
mind if you ever see a multi-plugin test fail in a way a single-plugin test didn't.

### File map

```
oauth_metadata.php            RFC 8414 AS metadata (site-wide singleton — one per site)
oauth_register.php            RFC 7591 DCR — public clients only, open/unauthenticated
oauth_authorize.php           /authorize — consent screen, PKCE (S256-only), CIMD resolution
oauth_token.php               /token — authorization_code + refresh_token grants
manageoauthclients.php        Admin UI: manual client creation (the Gemini Spark fallback
                               path), lists DCR + manual clients
settings.php                  Registers manageoauthclients.php under Site admin > Plugins >
                               Local plugins (admin_externalpage, moodle/site:config gated)

classes/oauth/registry.php    Resource discovery/resolution — see above
classes/oauth/revoker.php     Fires a resource's optional revokecallback when a grant is
                               revoked (theft, cap loss, TTL, client delete, privacy delete)
classes/oauth/helper.php      Pure-logic: redirect_uri validation, RFC 8414/9728 metadata
                               document builders (issuer, scopes = union of registered scopes)
classes/api.php               The ONLY classes a consuming plugin should call directly:
                               authorization_server_url(), resource_metadata($url),
                               authorization_server_metadata()
classes/task/oauth_cleanup.php  Hourly: purge expired codes, long-revoked refresh rows
classes/privacy/provider.php  local_oauthmcp_codes/_refresh hold personal data (which user
                               authorized which client) — no export, delete-on-request only
```

`\local_oauthmcp\api` is the intended public surface. Nothing else in `classes/` should be
called from outside this plugin — `oauth\helper`/`oauth\registry` are internal.

### DB tables (`db/install.xml`)

Three tables, all excluded from backup/restore (same treatment core gives its own
`external_tokens`/`external_services_users` — this is site/account auth state, not course
content):

- **`local_oauthmcp_clients`** — DCR-registered + manually-created clients only. CIMD
  clients (see below) are *never* persisted here — the URL itself is the identity, re-verified
  on every use via a short-TTL cache (`db/caches.php`'s `cimdclient`), not a DB row.
- **`local_oauthmcp_codes`** — single-use authorization codes, deleted on redemption (not a
  `used` flag). 60s TTL.
- **`local_oauthmcp_refresh`** — rotating refresh tokens, `familyid` constant across a
  rotation chain. Reuse of an already-rotated-away token is treated as theft and revokes the
  **entire** family, not just that token — and then fires the resource's `revokecallback`
  (see `oauth\revoker`) so the underlying web-service token is torn down too.

### Protocol decisions worth knowing before you change anything here

- **PKCE (S256) is mandatory, no exceptions** — `oauth_authorize.php` rejects `plain` or
  missing `code_challenge` outright. Don't add a non-PKCE path even for a "trusted" client.
- **DCR (`oauth_register.php`) only ever creates public clients** (`token_endpoint_auth_method:
  "none"`). This is deliberate, not a gap: a registered-but-never-consented client can do
  nothing, so open unauthenticated DCR is safe — the actual security boundary is the human
  clicking Allow at `/authorize`. Confidential clients can *only* be created by an admin via
  `manageoauthclients.php`. Google/Gemini's DCR always requests a confidential client and
  gets refused by design — its **only** real working path is the manual-client admin flow,
  not a rare fallback.
- **CIMD acceptance rule**: a client whose `client_id` is itself an `https://` URL is
  resolved by fetching that URL (via Moodle's `\curl`, which applies
  `curl_security_helper`'s SSRF blocklist automatically) and validating the document. Accept
  it if `"none"` is *either* the stated `token_endpoint_auth_method` **or** present in
  `token_endpoint_auth_methods_supported` — a client's stated preference isn't binding if it
  also lists a weaker mutually-supported option (found the hard way against a real ChatGPT
  CIMD document that preferred `private_key_jwt` but also listed `"none"`; our token
  endpoint never authenticates clients regardless, so `"none"` support is all that matters).
- **`oauth_metadata.php` must never gate on `PATH_INFO`.** It's reachable both bare and via
  a consuming plugin's own append-onto-resource-URL discovery form
  (`some-resource.php/.well-known/openid-configuration`) — a `RewriteRule` target reaching
  it via an unexpected path can leave `PATH_INFO` populated with something surprising, and
  there's nothing worth protecting by rejecting an unexpected route to a single public,
  non-sensitive document.
- **Domain-root Apache rewrites for `/.well-known/…`** (README "Server configuration §b"):
  - AS-metadata forms (`oauth-authorization-server`, `openid-configuration` →
    `oauth_metadata.php`) are safe on any site — one AS per site, no cross-plugin collision.
  - Protected-resource forms (`oauth-protected-resource` → a plugin's
    `oauth_resource_metadata.php`): per mod_minilesson guide §4b (Correction 2026-08-28), a
    real prerequisite for Gemini Spark connecting cold (Spark/Claude only probe domain-root
    well-known URLs, never the `resource_metadata=` pointer or the append-onto-`mcp.php`
    form). The **bare** form names no resource so it can point at only one plugin — safe
    only while exactly one resource is registered. The **insert** form
    (`…/mod/<plugin>/mcp.php`) names the resource, so add one per registered resource; those
    don't collide. On a multi-resource site the bare form still serves only one; the rest
    need a pointer-following client (ChatGPT).
  - All of it is opt-in admin vhost config, never something this plugin does automatically.
- **Apache/PHP-FPM strips `Authorization: Bearer`** on many setups (also plain `mod_php`) —
  needs `CGIPassAuth On` in a `<Directory>`/`.htaccess` (errors bare in `<VirtualHost>`) or
  `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`. Site-wide admin fix, not code.
  README "Server configuration §a".
- **`\curl` needs `require_once($CFG->libdir . '/filelib.php')`** — not autoloaded like most
  Moodle core classes.

## Reference consumer

`mod_minilesson` — own git repo, typically installed alongside this plugin. Its `lib.php`'s
`mod_minilesson_mcp_oauth_resources()` and its `oauth_resource_metadata.php` are the
concrete example of the integration pattern described above and in `README.md`.
