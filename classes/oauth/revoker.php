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
 * Tears down the real Moodle web service token behind an OAuth grant when that grant is
 * revoked - refresh-token reuse/theft detection, an admin deleting a client, a capability
 * being withdrawn, a refresh token reaching its outer lifetime, or a privacy delete request.
 *
 * This authorization server issues no credential of its own (see
 * \local_oauthmcp\oauth\registry): the "access token" returned from /token is a real
 * external_tokens row the consuming plugin minted through its own mintcallback, and only that
 * plugin knows how to invalidate it. A resource declaration may therefore supply an optional
 * `revokecallback` - `function(int $userid): void` - which this class invokes at the moments
 * above so a revoked grant does not leave a usable web service token behind until it expires
 * on its own.
 *
 * A consuming plugin typically mints one shared token per (user, external service) and reuses
 * it across every client and every re-authorization, so the callback is fired only once the
 * user has no remaining live grant for that resource - otherwise revoking one client's grant
 * would cut off another that is still valid.
 *
 * @package    local_oauthmcp
 * @copyright  2026 Justin Hunt (poodllsupport@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class revoker {
    /**
     * Revoke an entire refresh-token rotation family, then fire the resource's revokecallback
     * if that leaves the user with no live grant for the resource.
     *
     * @param string $familyid the familyid constant across a rotation chain
     * @return void
     */
    public static function revoke_family(string $familyid): void {
        global $DB;

        $sample = $DB->get_record('local_oauthmcp_refresh', ['familyid' => $familyid], '*', IGNORE_MULTIPLE);
        if (!$sample) {
            return;
        }
        $DB->set_field('local_oauthmcp_refresh', 'revoked', 1, ['familyid' => $familyid]);
        self::maybe_revoke_access_token($sample->resource, (int) $sample->userid);
    }

    /**
     * Ask a resource to drop the web service token behind a user's grant, unless that user
     * still holds a live (non-revoked, unexpired) refresh token for the same resource - in
     * which case the shared access token is still in use and is left alone.
     *
     * A no-op when the resource is not registered or declared no revokecallback. Call this
     * *after* the relevant refresh rows have been revoked or deleted, so the "still active"
     * check reflects the post-revocation state.
     *
     * @param string|null $resourceurl the canonical registered resource URL
     * @param int $userid
     * @return void
     */
    public static function maybe_revoke_access_token(?string $resourceurl, int $userid): void {
        global $DB;

        if ($resourceurl === null || $userid <= 0) {
            return;
        }
        $resource = registry::find($resourceurl);
        if ($resource === null || empty($resource->revokecallback)) {
            return;
        }
        $stillactive = $DB->record_exists_select(
            'local_oauthmcp_refresh',
            'resource = ? AND userid = ? AND revoked = 0 AND (expires IS NULL OR expires > ?)',
            [$resourceurl, $userid, time()]
        );
        if ($stillactive) {
            return;
        }
        try {
            call_user_func($resource->revokecallback, $userid);
        } catch (\Throwable $e) {
            debugging(
                "local_oauthmcp: revokecallback for {$resource->component} threw: " . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }
}
