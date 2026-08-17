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
 * Library of interface functions for the PowerPoint import tool.
 *
 * @package    booktool_importpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds the "Import PowerPoint" action to a book's settings navigation.
 *
 * Mirrors booktool_importhtml so the action appears in the same place teachers
 * already look for the HTML importer.
 *
 * @param settings_navigation $settingsnav The settings navigation object.
 * @param navigation_node $booknode The book branch of the settings navigation.
 * @return void
 */
function booktool_importpptx_extend_settings_navigation(
    settings_navigation $settingsnav,
    navigation_node $booknode
) {
    global $PAGE;

    // Read the course module directly, exactly as core booktool_importhtml does.
    // The page object exposes cm through a magic getter, so probing it with
    // empty()/isset() can report "not set" even when a direct read returns the
    // module (observed live: the guard saw null while importhtml, in the same
    // callback loop, used $PAGE->cm successfully). Assign first, then test the
    // local variable, so no magic-property semantics are involved. mod_book only
    // invokes booktool callbacks for its own pages, so no modname check is needed.
    $cm = $PAGE->cm;
    if (!$cm || !has_capability('booktool/importpptx:import', $cm->context)) {
        return;
    }

    $url = new moodle_url('/mod/book/tool/importpptx/index.php', ['id' => $cm->id]);
    $booknode->add(
        get_string('importpptx', 'booktool_importpptx'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'importpptx'
    );
}
