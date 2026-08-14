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
 * Import a PowerPoint presentation into a book as chapters.
 *
 * @package    booktool_importpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/book/locallib.php');
require_once($CFG->dirroot . '/mod/book/tool/importpptx/locallib.php');
require_once($CFG->libdir . '/formslib.php');

$id = required_param('id', PARAM_INT); // Course module id.

$cm = get_coursemodule_from_id('book', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('booktool/importpptx:import', $context);

$returnurl = new moodle_url('/mod/book/view.php', ['id' => $cm->id]);
$PAGE->set_url('/mod/book/tool/importpptx/index.php', ['id' => $cm->id]);
$PAGE->set_title($book->name);
$PAGE->set_heading($course->fullname);
$PAGE->set_activity_record($book);
$PAGE->navbar->add(get_string('importpptx', 'booktool_importpptx'));

// Kill-switch: refuse only when an administrator has explicitly disabled the
// importer; an unset value means the default (enabled) applies.
$enabled = get_config('booktool_importpptx', 'enabled');
if ($enabled !== false && empty($enabled)) {
    redirect(
        $returnurl,
        get_string('disabled', 'booktool_importpptx'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Do not allow a second import while one is already queued for this book.
if (\booktool_importpptx\task\import_task::is_queued($book->id)) {
    redirect(
        $returnurl,
        get_string('taskinprogress', 'booktool_importpptx'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$mform = new \booktool_importpptx\form\import_form(null, ['id' => $cm->id]);
if ($mform->is_cancelled()) {
    \booktool_importpptx\pending_file::delete($context, $book->id);
    redirect($returnurl);
}

// Confirmation step: the upload has already been staged; run it now.
if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
    $file = \booktool_importpptx\pending_file::get($context, $book->id);
    if ($file === null) {
        redirect(
            $returnurl,
            get_string('errornoslides', 'booktool_importpptx'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    $result = booktool_importpptx_process($file, $book, $context, $cm);
    if ($result->queued) {
        redirect(
            $returnurl,
            get_string('asyncqueued', 'booktool_importpptx', $result->count),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }
    redirect(
        $returnurl,
        get_string('importresult', 'booktool_importpptx', $result->count),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// First submission: stage the upload and show a confirmation with the slide count.
if ($data = $mform->get_data()) {
    $file = \booktool_importpptx\pending_file::store($data->pptxfile, $context, $book->id);
    if ($file === null) {
        redirect(
            $PAGE->url,
            get_string('errornopptx', 'booktool_importpptx'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    try {
        $count = \booktool_importpptx\importer::count_slides($file);
    } catch (\moodle_exception $e) {
        \booktool_importpptx\pending_file::delete($context, $book->id);
        redirect($PAGE->url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }

    $continueurl = new moodle_url($PAGE->url, ['id' => $cm->id, 'confirm' => 1]);
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('confirmimport', 'booktool_importpptx'));
    echo $OUTPUT->confirm(
        get_string('confirmimportdetail', 'booktool_importpptx', $count),
        $continueurl,
        $returnurl
    );
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('importpptx', 'booktool_importpptx'));
$mform->display();
echo $OUTPUT->footer();
