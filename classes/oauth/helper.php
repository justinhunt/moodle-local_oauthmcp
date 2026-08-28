<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_oauthmcp\oauth;

/**
 * Small pure-logic helpers shared by the OAuth authorization server front-ends
 * (oauth_register.php, oauth_authorize.php, manageoauthclients.php), so the redirect_uri
 * acceptance rule and the metadata documents can't silently drift between where they are
 * produced and where they are relied on.
 *
 * @package    local_oauthmcp
 * @copyright  2026 Justin Hunt (poodllsupport@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /**
     * Whether a redirect_uri is acceptable: https, or the RFC 8252 section 7.3 loopback
     * exception (http, host 127.0.0.1/::1/localhost, any port) for native/CLI clients.
     *
     * @param string $uri
     * @return bool
     */
    public static function valid_redirect_uri(string $uri): bool {
        $parts = parse_url($uri);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if ($parts['scheme'] === 'https') {
            return true;
        }
        $loopbackhosts = ['127.0.0.1', '::1', 'localhost'];
        return $parts['scheme'] === 'http' && in_array($parts['host'], $loopbackhosts, true);
    }

    /**
     * This authorization server's own issuer URL (oauth_metadata.php, no PATH_INFO suffix).
     *
     * @return string
     */
    public static function issuer(): string {
        global $CFG;
        return $CFG->wwwroot . '/local/oauthmcp/oauth_metadata.php';
    }

    /**
     * RFC 8414 authorization server metadata. Site-wide (this server, not any one protected
     * resource) - scopes_supported is the union of every currently-registered resource's
     * scope, so it stays accurate as plugins are installed/uninstalled without a code change
     * here.
     *
     * @return array
     */
    public static function authorization_server_metadata(): array {
        global $CFG;
        $scopes = array_values(array_unique(array_map(
            fn ($resource) => $resource->scope,
            registry::get_resources()
        )));
        return [
            'issuer' => self::issuer(),
            'authorization_endpoint' => $CFG->wwwroot . '/local/oauthmcp/oauth_authorize.php',
            'token_endpoint' => $CFG->wwwroot . '/local/oauthmcp/oauth_token.php',
            'registration_endpoint' => $CFG->wwwroot . '/local/oauthmcp/oauth_register.php',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
            'client_id_metadata_document_supported' => true,
            'scopes_supported' => $scopes,
        ];
    }

    /**
     * RFC 9728 protected resource metadata for one registered resource. Intended to be called
     * by the *consuming* plugin's own endpoint (e.g. its mcp.php PATH_INFO discovery branch) -
     * real MCP clients were found to request this at the resource's own URL, not a central
     * one, so this plugin does not serve it directly. See
     * forclaude/mcp-oauth-implementation-guide.md section 4 in mod_minilesson for why.
     *
     * @param string $resourceurl
     * @return array|null null if no plugin has registered this exact resource URL
     */
    public static function resource_metadata(string $resourceurl): ?array {
        $resource = registry::find($resourceurl);
        if ($resource === null) {
            return null;
        }
        return [
            'resource' => $resource->resource,
            'authorization_servers' => [self::issuer()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => [$resource->scope],
        ];
    }
}
