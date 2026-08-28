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

/**
 * RFC 8414 OAuth 2.0 Authorization Server Metadata for this shared MCP authorization server.
 *
 * A plugin living in a URL subdirectory (not a domain root) cannot serve the site-root
 * .well-known forms RFC 8414 normally expects. Real-world testing (see
 * mod_minilesson's forclaude/mcp-oauth-implementation-guide.md, sections 4b/4d) found that
 * no major MCP client reliably follows the resource_metadata pointer on a 401 the way the
 * spec text describes; the one reachable form actually observed is a client appending
 * /.well-known/openid-configuration onto the *resource's own* URL, which is answered by the
 * consuming plugin itself (its mcp.php), not here. This script's own URL is what that answer
 * points at as the issuer/authorization_servers entry.
 *
 * This script serves exactly one public, non-sensitive document, so it does not gate on how
 * it was reached (e.g. via PATH_INFO).
 *
 * @package    local_oauthmcp
 * @copyright  2026 Justin Hunt (poodllsupport@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_DEBUG_DISPLAY', true);
define('NO_MOODLE_COOKIES', true);

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Public, unauthenticated discovery metadata.
require(__DIR__ . '/../../config.php');

header('Content-Type: application/json; charset=utf-8');
echo json_encode(
    \local_oauthmcp\oauth\helper::authorization_server_metadata(),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
