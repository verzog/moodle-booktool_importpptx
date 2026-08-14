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
function booktool_importpptx_extend_settings_navigation(settings_navigation $settingsnav,
        navigation_node $booknode) {
    global $PAGE;

    // Only show the action while we are inside a book activity with a course module.
    if (empty($PAGE->cm) || $PAGE->cm->modname !== 'book') {
        return;
    }

    // Respect the site-wide kill-switch (see settings.php).
    if (!get_config('booktool_importpptx', 'enabled')) {
        return;
    }

    if (!has_capability('booktool/importpptx:import', $PAGE->cm->context)) {
        return;
    }

    $url = new moodle_url('/mod/book/tool/importpptx/index.php', ['id' => $PAGE->cm->id]);
    $booknode->add(get_string('importpptx', 'booktool_importpptx'), $url,
        navigation_node::TYPE_SETTING, null, 'importpptx', new pix_icon('i/import', ''));
}
