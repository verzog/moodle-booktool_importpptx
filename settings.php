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
 * Administration settings for the PowerPoint import tool.
 *
 * @package    booktool_importpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Kill-switch: disable the importer without removing the plugin.
    $settings->add(new admin_setting_configcheckbox(
        'booktool_importpptx/enabled',
        get_string('setting_enabled', 'booktool_importpptx'),
        get_string('setting_enabled_desc', 'booktool_importpptx'),
        1
    ));

    // Default colour used for the section-divider plate when the slide's own
    // fill cannot be detected. The importer prefers the detected fill.
    $settings->add(new admin_setting_configcolourpicker(
        'booktool_importpptx/sectionpanelcolour',
        get_string('setting_sectionpanelcolour', 'booktool_importpptx'),
        get_string('setting_sectionpanelcolour_desc', 'booktool_importpptx'),
        '#442980'
    ));

    // Optional down-scaling of imported images. 0 keeps originals.
    $settings->add(new admin_setting_configtext(
        'booktool_importpptx/imagemaxdim',
        get_string('setting_imagemaxdim', 'booktool_importpptx'),
        get_string('setting_imagemaxdim_desc', 'booktool_importpptx'),
        1600,
        PARAM_INT
    ));

    // Above this many slides the import runs as a background (adhoc) task.
    $settings->add(new admin_setting_configtext(
        'booktool_importpptx/asyncthreshold',
        get_string('setting_asyncthreshold', 'booktool_importpptx'),
        get_string('setting_asyncthreshold_desc', 'booktool_importpptx'),
        30,
        PARAM_INT
    ));
}
