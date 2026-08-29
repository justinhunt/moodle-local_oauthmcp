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
 * Discovers and resolves the protected resources any installed plugin has declared to this
 * shared MCP OAuth authorization server.
 *
 * A consuming plugin opts in by implementing a `<frankenstyle>_mcp_oauth_resources()`
 * function in its own lib.php, discovered the same way Moodle discovers
 * `<component>_extend_navigation()` and similar callbacks - no install-order dependency, no
 * explicit registration call, and the consuming plugin works identically whether or not this
 * plugin happens to be installed.
 *
 * The callback must return an array of resource declarations, each either an array or object
 * with the keys:
 *  - resource (string, required): the full URL of the protected resource (e.g. the
 *    consuming plugin's own mcp.php), matched byte-for-byte against the OAuth `resource`
 *    parameter (RFC 8707).
 *  - scope (string, required): the single scope name this resource is granted under.
 *  - capability (string, required): a Moodle capability checked at CONTEXT_SYSTEM, both at
 *    the /authorize consent step and again on every refresh_token grant.
 *  - mintcallback (callable, required): `function(int $userid): string`, returning a real
 *    Moodle web service token for that user. May throw if the user is no longer permitted.
 *  - revokecallback (callable, optional): `function(int $userid): void`, invoked by
 *    \local_oauthmcp\oauth\revoker when a grant is revoked (reuse/theft detection, client
 *    deletion, capability withdrawal, refresh-token expiry, privacy delete) so the plugin can
 *    drop the web service token it minted. A declared-but-uncallable value is ignored with a
 *    DEBUG_DEVELOPER notice rather than skipping the whole resource.
 *  - description (string, optional): shown on the consent screen in place of the generic
 *    "this site's AI tools" wording.
 *
 * @package    local_oauthmcp
 * @copyright  2026 Justin Hunt (poodllsupport@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class registry {
    /** @var array|null resource objects keyed by resource URL, or null until first built. */
    private static ?array $resources = null;

    /**
     * All registered resources, keyed by resource URL.
     *
     * @return \stdClass[]
     */
    public static function get_resources(): array {
        if (self::$resources !== null) {
            return self::$resources;
        }

        $resources = [];
        $pluginswithfunction = get_plugins_with_function('mcp_oauth_resources', 'lib.php');
        foreach ($pluginswithfunction as $plugintype => $plugins) {
            foreach ($plugins as $pluginname => $functionname) {
                $component = $plugintype . '_' . $pluginname;
                $declared = $functionname();
                if (!is_array($declared)) {
                    debugging("{$component}: {$functionname}() must return an array", DEBUG_DEVELOPER);
                    continue;
                }
                foreach ($declared as $entry) {
                    $resource = self::normalise($entry, $component);
                    if ($resource !== null) {
                        $resources[$resource->resource] = $resource;
                    }
                }
            }
        }

        return self::$resources = $resources;
    }

    /**
     * Find a single registered resource by its exact URL.
     *
     * @param string $resourceurl
     * @return \stdClass|null
     */
    public static function find(string $resourceurl): ?\stdClass {
        return self::get_resources()[$resourceurl] ?? null;
    }

    /**
     * Resolve a resource from an OAuth request: the resource URL sent by the client, or (when
     * the client omitted `resource` entirely, which real-world clients do) fall back to the
     * single registered resource if there is exactly one - preserving today's single-resource
     * behaviour unchanged. With more than one resource registered, an omitted `resource` is
     * genuinely ambiguous and resolves to null.
     *
     * @param string|null $resourceurl
     * @return \stdClass|null
     */
    public static function resolve(?string $resourceurl): ?\stdClass {
        $resources = self::get_resources();
        if ($resourceurl !== null) {
            return $resources[$resourceurl] ?? null;
        }
        return count($resources) === 1 ? reset($resources) : null;
    }

    /**
     * Validate and normalise one declared resource entry.
     *
     * @param mixed $entry
     * @param string $component the declaring plugin, for debugging() messages only
     * @return \stdClass|null
     */
    private static function normalise($entry, string $component): ?\stdClass {
        $entry = (array) $entry;
        foreach (['resource', 'scope', 'capability', 'mintcallback'] as $required) {
            if (empty($entry[$required])) {
                debugging("{$component}: mcp_oauth_resources() entry missing '{$required}'", DEBUG_DEVELOPER);
                return null;
            }
        }
        if (!is_callable($entry['mintcallback'])) {
            debugging("{$component}: mcp_oauth_resources() entry's mintcallback is not callable", DEBUG_DEVELOPER);
            return null;
        }
        if (isset($entry['revokecallback']) && !is_callable($entry['revokecallback'])) {
            debugging(
                "{$component}: mcp_oauth_resources() entry's revokecallback is not callable; ignoring it",
                DEBUG_DEVELOPER
            );
            $entry['revokecallback'] = null;
        }
        return (object) [
            'component' => $component,
            'resource' => $entry['resource'],
            'scope' => $entry['scope'],
            'capability' => $entry['capability'],
            'mintcallback' => $entry['mintcallback'],
            'revokecallback' => $entry['revokecallback'] ?? null,
            'description' => $entry['description'] ?? null,
        ];
    }
}
