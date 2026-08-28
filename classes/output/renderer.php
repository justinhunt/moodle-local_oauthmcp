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

namespace local_oauthmcp\output;

/**
 * Renderer for local_oauthmcp.
 *
 * @package    local_oauthmcp
 * @copyright  2026 Justin Hunt (poodllsupport@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /**
     * Render the OAuth consent screen body (oauth_authorize.php).
     *
     * @param array $data
     * @return string
     */
    public function render_oauthconsent(array $data) {
        return $this->render_from_template('local_oauthmcp/oauthconsent', $data);
    }

    /**
     * Render the "you don't have permission" screen body (oauth_authorize.php).
     *
     * @param array $data
     * @return string
     */
    public function render_oauthnotpermitted(array $data) {
        return $this->render_from_template('local_oauthmcp/oauthnotpermitted', $data);
    }

    /**
     * Render the Manage OAuth clients admin page body.
     *
     * @param array $data
     * @return string
     */
    public function render_manageoauthclients(array $data) {
        return $this->render_from_template('local_oauthmcp/manageoauthclients', $data);
    }
}
