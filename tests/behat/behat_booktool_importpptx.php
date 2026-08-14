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
 * Behat step definitions and page resolvers for the PowerPoint import tool.
 *
 * @package    booktool_importpptx
 * @category   test
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL check should be here.

require_once(__DIR__ . '/../../../../../../lib/behat/behat_base.php');

/**
 * Resolves navigable pages for the PowerPoint import tool so scenarios can jump
 * straight to the import page without depending on menu placement.
 */
class behat_booktool_importpptx extends behat_base {
    /**
     * Recognised page instance URLs.
     *
     * Supported page type:
     * - "Import": the import form, identified by the book's course-module idnumber.
     *
     * @param string $type The page type (e.g. "Import").
     * @param string $identifier The book's course-module idnumber.
     * @return \moodle_url The URL of the requested page.
     * @throws Exception If the page type is not recognised.
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        global $DB;

        switch (strtolower($type)) {
            case 'import':
                $cm = $DB->get_record_sql(
                    "SELECT cm.id
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE m.name = :modname AND cm.idnumber = :idnumber",
                    ['modname' => 'book', 'idnumber' => $identifier],
                    MUST_EXIST
                );
                return new moodle_url('/mod/book/tool/importpptx/index.php', ['id' => $cm->id]);
            default:
                throw new Exception("Unrecognised booktool_importpptx page type '{$type}'.");
        }
    }
}
