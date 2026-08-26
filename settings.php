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
 * Admin settings for the PowerPoint import tool for Book.
 *
 * @package    booktool_importpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'booktool_importpptx/cloudheading',
        get_string('cloudheading', 'booktool_importpptx'),
        get_string('cloudheading_desc', 'booktool_importpptx')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'booktool_importpptx/cloudenabled',
        get_string('cloudenabled', 'booktool_importpptx'),
        get_string('cloudenabled_desc', 'booktool_importpptx'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'booktool_importpptx/cloudurl',
        get_string('cloudurl', 'booktool_importpptx'),
        get_string('cloudurl_desc', 'booktool_importpptx'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'booktool_importpptx/cloudkey',
        get_string('cloudkey', 'booktool_importpptx'),
        get_string('cloudkey_desc', 'booktool_importpptx'),
        ''
    ));
}
