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

namespace local_oauthmcp;

use local_oauthmcp\oauth\helper;

/**
 * Public API surface for plugins consuming this shared MCP OAuth authorization server.
 *
 * A consuming plugin needs two things: implement `<frankenstyle>_mcp_oauth_resources()` in
 * its own lib.php (see \local_oauthmcp\oauth\registry for the contract), and call
 * {@see self::resource_metadata()} from its own protected-resource endpoint (e.g. a
 * PATH_INFO discovery branch on its mcp.php, mirroring mod_minilesson's existing one) to
 * answer RFC 9728 discovery requests - those must be served from the resource's own URL, not
 * this plugin, so this stays a helper rather than an endpoint here.
 *
 * @package    local_oauthmcp
 * @copyright  2026 Justin Hunt (poodllsupport@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /**
     * This authorization server's issuer/metadata URL, for a consuming plugin's own
     * `WWW-Authenticate: Bearer resource_metadata="..."` challenge and RFC 9728
     * `authorization_servers` entry.
     *
     * @return string
     */
    public static function authorization_server_url(): string {
        return helper::issuer();
    }

    /**
     * RFC 9728 protected resource metadata for a resource this plugin has registered.
     *
     * @param string $resourceurl the exact URL passed as 'resource' in the plugin's own
     *        mcp_oauth_resources() declaration
     * @return array|null null if that URL was not found registered (e.g. called before the
     *         registering plugin's own lib.php callback exists, or a typo'd URL)
     */
    public static function resource_metadata(string $resourceurl): ?array {
        return helper::resource_metadata($resourceurl);
    }

    /**
     * RFC 8414 authorization server metadata (this server, not any one resource).
     *
     * Some real MCP clients (observed: ChatGPT) request this by appending the well-known
     * suffix onto the *resource's own* URL rather than this server's issuer URL - a
     * consuming plugin's own PATH_INFO discovery branch on its resource endpoint should
     * serve this JSON directly to answer that form, mirroring mod_minilesson's mcp.php.
     *
     * @return array
     */
    public static function authorization_server_metadata(): array {
        return helper::authorization_server_metadata();
    }
}
