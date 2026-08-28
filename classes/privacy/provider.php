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

namespace local_oauthmcp\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_oauthmcp.
 *
 * Authorization codes and refresh tokens are short-lived/rotating OAuth grant state tied to a
 * user (which third-party AI tool that user authorized), all at CONTEXT_SYSTEM - the same
 * treatment core gives its own external_tokens/external_services_users. No export support is
 * offered (there is nothing meaningful for a user to read back beyond "you connected client
 * X on date Y", already visible via manageoauthclients.php to admins); a data request results
 * in the grant being revoked.
 *
 * @package    local_oauthmcp
 * @copyright  2026 Justin Hunt (poodllsupport@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_oauthmcp_codes', [
            'userid' => 'privacy:metadata:local_oauthmcp_codes:userid',
            'clientid' => 'privacy:metadata:local_oauthmcp_codes:clientid',
            'clientnamesnapshot' => 'privacy:metadata:local_oauthmcp_codes:clientnamesnapshot',
            'timecreated' => 'privacy:metadata:local_oauthmcp_codes:timecreated',
        ], 'privacy:metadata:local_oauthmcp_codes');

        $collection->add_database_table('local_oauthmcp_refresh', [
            'userid' => 'privacy:metadata:local_oauthmcp_refresh:userid',
            'clientid' => 'privacy:metadata:local_oauthmcp_refresh:clientid',
            'timecreated' => 'privacy:metadata:local_oauthmcp_refresh:timecreated',
            'lastused' => 'privacy:metadata:local_oauthmcp_refresh:lastused',
        ], 'privacy:metadata:local_oauthmcp_refresh');

        return $collection;
    }

    /**
     * Get the list of contexts holding user data for the given user - always just the system
     * context, since this data has no course/module scope.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        if (
            $DB->record_exists('local_oauthmcp_codes', ['userid' => $userid])
            || $DB->record_exists('local_oauthmcp_refresh', ['userid' => $userid])
        ) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Get the list of users within a context.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        global $DB;

        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        $userlist->add_users($DB->get_fieldset_select('local_oauthmcp_codes', 'DISTINCT userid', ''));
        $userlist->add_users($DB->get_fieldset_select('local_oauthmcp_refresh', 'DISTINCT userid', ''));
    }

    /**
     * No export - see class docblock for why.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
    }

    /**
     * Delete all data for all users in a context.
     *
     * @param \context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_system) {
            return;
        }
        $DB->delete_records('local_oauthmcp_codes');
        $DB->delete_records('local_oauthmcp_refresh');
    }

    /**
     * Delete all data for one user.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_system) {
                continue;
            }
            $DB->delete_records('local_oauthmcp_codes', ['userid' => $contextlist->get_user()->id]);
            $DB->delete_records('local_oauthmcp_refresh', ['userid' => $contextlist->get_user()->id]);
        }
    }

    /**
     * Delete data for multiple users within a single context.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userlist->get_userids());
        $DB->delete_records_select('local_oauthmcp_codes', "userid {$insql}", $inparams);
        $DB->delete_records_select('local_oauthmcp_refresh', "userid {$insql}", $inparams);
    }
}
