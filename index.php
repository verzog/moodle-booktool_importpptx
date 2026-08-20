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

// Do not allow a second import while one is already queued for this book.
if (\booktool_importpptx\task\import_task::is_queued($book->id)) {
    redirect(
        $returnurl,
        get_string('taskinprogress', 'booktool_importpptx'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Cancelling the confirmation step removes the staged upload it was about to import.
$cancelpending = optional_param('cancelpending', 0, PARAM_INT);
if ($cancelpending) {
    \booktool_importpptx\pending_file::delete($context, $cancelpending);
    redirect($returnurl);
}

$pdfenabled = \booktool_importpptx\pdf\renderer::is_available();
$officeenabled = \booktool_importpptx\office\renderer::is_available();
$mform = new \booktool_importpptx\form\import_form(
    null,
    ['id' => $cm->id, 'pdfenabled' => $pdfenabled, 'officeenabled' => $officeenabled]
);
if ($mform->is_cancelled()) {
    redirect($returnurl);
}

// Confirmation step: the upload has already been staged; run it now.
if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
    $pendingid = required_param('pendingid', PARAM_INT);
    $file = \booktool_importpptx\pending_file::get($context, $pendingid);
    if ($file === null) {
        redirect(
            $returnurl,
            get_string('errornoslides', 'booktool_importpptx'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    $options = [
        'imagemaxdim' => optional_param('imagemaxdim', 1600, PARAM_INT),
        'sectioncolour' => optional_param('sectioncolour', '#442980', PARAM_TEXT),
        'importmode' => optional_param('importmode', 'editable', PARAM_ALPHA),
        'cardgroup' => optional_param('cardgroup', 0, PARAM_BOOL),
        'bodysize' => optional_param('bodysize', 0, PARAM_INT),
        'adjacentsize' => optional_param('adjacentsize', 0, PARAM_INT),
        'smartartimages' => optional_param('smartartimages', 0, PARAM_BOOL),
    ];
    $result = booktool_importpptx_process($file, $book, $context, $cm, $pendingid, $options);
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
    $pendingid = (int) $data->pptxfile;
    $file = \booktool_importpptx\pending_file::store($pendingid, $context);
    if ($file === null) {
        redirect(
            $PAGE->url,
            get_string('errornopptx', 'booktool_importpptx'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    if (booktool_importpptx_type($file) === 'pdf' && !$pdfenabled) {
        \booktool_importpptx\pending_file::delete($context, $pendingid);
        redirect(
            $PAGE->url,
            get_string('errorpdfunavailable', 'booktool_importpptx'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    try {
        $count = booktool_importpptx_count($file, [
            'importmode' => (string) ($data->importmode ?? 'editable'),
        ]);
    } catch (\moodle_exception $e) {
        \booktool_importpptx\pending_file::delete($context, $pendingid);
        redirect($PAGE->url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }

    $continueurl = new moodle_url($PAGE->url, [
        'id' => $cm->id,
        'confirm' => 1,
        'pendingid' => $pendingid,
        'imagemaxdim' => (int) $data->imagemaxdim,
        'sectioncolour' => (string) $data->sectioncolour,
        'importmode' => (string) ($data->importmode ?? 'editable'),
        'cardgroup' => (int) !empty($data->cardgroup),
        'bodysize' => (int) ($data->bodysize ?? 0),
        'adjacentsize' => (int) ($data->adjacentsize ?? 0),
        'smartartimages' => (int) !empty($data->smartartimages),
    ]);
    $cancelurl = new moodle_url($PAGE->url, ['id' => $cm->id, 'cancelpending' => $pendingid]);
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('confirmimport', 'booktool_importpptx'));
    echo $OUTPUT->confirm(
        get_string('confirmimportdetail', 'booktool_importpptx', $count),
        $continueurl,
        $cancelurl
    );
    echo $OUTPUT->footer();
    exit;
}

// When both backends are available the form offers the editable/images choice
// and also accepts PDFs. A PDF always imports as page images, so the choice
// does not apply to it: hide that row while a PDF is selected and show it again
// when a PowerPoint is chosen, so the visible option always matches what runs.
if ($pdfenabled && $officeenabled) {
    $js = <<<'JS'
require([], function() {
    var group = document.getElementById('fitem_id_importmode');
    var picker = document.getElementById('fitem_id_pptxfile');
    if (!group || !picker) {
        return;
    }
    var sync = function() {
        var el = picker.querySelector('.filepicker-filename');
        var name = (el ? el.textContent : '').trim().toLowerCase();
        var ispdf = name.length >= 4 && name.slice(-4) === '.pdf';
        group.style.display = ispdf ? 'none' : '';
    };
    sync();
    if (window.MutationObserver) {
        new MutationObserver(sync).observe(picker, {
            subtree: true,
            childList: true,
            characterData: true
        });
    }
});
JS;
    $PAGE->requires->js_amd_inline($js);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('importpptx', 'booktool_importpptx'));
$mform->display();
echo $OUTPUT->footer();
