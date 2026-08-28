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
 * English language strings for local_oauthmcp.
 *
 * @package    local_oauthmcp
 * @copyright  2026 Justin Hunt (poodllsupport@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['manageoauthclients'] = 'Manage OAuth clients';
$string['manageoauthclients_confidentiallabel'] = 'Confidential client (issue a client secret - needed for most manual-entry setups, e.g. Gemini)';
$string['manageoauthclients_confidentialtypelabel'] = 'Confidential';
$string['manageoauthclients_createbtn'] = 'Create client';
$string['manageoauthclients_created'] = 'Client created.';
$string['manageoauthclients_createdcollabel'] = 'Created';
$string['manageoauthclients_createheading'] = 'Create a manual OAuth client';
$string['manageoauthclients_dcrlabel'] = 'DCR (auto-registered)';
$string['manageoauthclients_deleted'] = 'Client deleted, and any of its outstanding codes/refresh tokens revoked.';
$string['manageoauthclients_intro'] = 'Most AI clients (including Claude) register themselves automatically. Some, such as Google Gemini\'s custom connectors, ask you to paste in a client ID and secret manually when a server doesn\'t support automatic registration - create a client here for that case.';
$string['manageoauthclients_invaliduris'] = 'Enter at least one redirect URI. Each must be https, or a loopback http URI (127.0.0.1/::1/localhost).';
$string['manageoauthclients_listheading'] = 'Existing clients';
$string['manageoauthclients_namelabel'] = 'Name';
$string['manageoauthclients_newsecretwarning'] = 'This client secret is shown only once - copy it now. If you lose it, use "Regenerate secret" below to issue a new one.';
$string['manageoauthclients_noclients'] = 'No OAuth clients yet.';
$string['manageoauthclients_publiclabel'] = 'Public';
$string['manageoauthclients_redirecturislabel'] = 'Redirect URIs (one per line)';
$string['manageoauthclients_regenerated'] = 'Client secret regenerated. The old secret no longer works.';
$string['manageoauthclients_regeneratelabel'] = 'Regenerate secret';
$string['manageoauthclients_typecollabel'] = 'Type';
$string['oauthauthorizetitle'] = 'Connect an AI tool';
$string['oauthcleanuptask'] = 'Clean up expired MCP OAuth codes and refresh tokens';
$string['oauthconsentapprove'] = 'Allow';
$string['oauthconsentbody'] = '{$a->clientname} wants to access {$a->resourcedescription} on your behalf.';
$string['oauthconsentbody_defaultresource'] = "this site's tools";
$string['oauthconsentclientlink'] = 'About this application';
$string['oauthconsentdeny'] = 'Deny';
$string['oauthconsentheading'] = 'Connect an AI tool to this site';
$string['oauthnotpermittedbody'] = 'Your account does not have permission to use this AI tool integration. Contact your site administrator if you believe this is a mistake.';
$string['oauthnotpermittedheading'] = "You don't have permission to connect AI tools";
$string['oauthunnamedclient'] = 'An unnamed application';
$string['pluginname'] = 'Shared MCP OAuth authorization server';
$string['privacy:metadata:local_oauthmcp_codes'] = 'A short-lived, single-use OAuth authorization code issued while a user was connecting a third-party AI tool.';
$string['privacy:metadata:local_oauthmcp_codes:clientid'] = 'The identifier of the OAuth client (AI tool) being authorized.';
$string['privacy:metadata:local_oauthmcp_codes:clientnamesnapshot'] = "The AI tool's display name at the time consent was given.";
$string['privacy:metadata:local_oauthmcp_codes:timecreated'] = 'The time the authorization code was issued.';
$string['privacy:metadata:local_oauthmcp_codes:userid'] = 'The user who was authorizing the connection.';
$string['privacy:metadata:local_oauthmcp_refresh'] = 'A rotating OAuth refresh token that keeps a third-party AI tool connected to a user\'s account.';
$string['privacy:metadata:local_oauthmcp_refresh:clientid'] = 'The identifier of the OAuth client (AI tool) holding this refresh token.';
$string['privacy:metadata:local_oauthmcp_refresh:lastused'] = 'The time this refresh token was last used to obtain a new access token.';
$string['privacy:metadata:local_oauthmcp_refresh:timecreated'] = 'The time this refresh token was issued.';
$string['privacy:metadata:local_oauthmcp_refresh:userid'] = 'The user who authorized the connection.';
