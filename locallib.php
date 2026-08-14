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
 * Orchestration helpers for the PowerPoint import tool.
 *
 * @package    booktool_importpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Imports a staged presentation, either inline or as a background task.
 *
 * Presentations larger than the configured threshold are queued so the web
 * request stays responsive; smaller ones run immediately. In both cases the
 * staged upload is cleaned up (inline here, in the task for async runs).
 *
 * @param stored_file $file The staged .pptx (see \booktool_importpptx\pending_file).
 * @param stdClass $book The target book record.
 * @param context_module $context The book's module context.
 * @param stdClass|cm_info $cm The book's course module.
 * @param int $pendingid The staged upload's item id (for cleanup and the async task).
 * @param array $options Import options ('imagemaxdim' int, 'sectioncolour' string).
 * @return stdClass Object with properties queued (bool) and count (int slides/chapters).
 */
function booktool_importpptx_process(
    stored_file $file,
    stdClass $book,
    context_module $context,
    $cm,
    int $pendingid,
    array $options = []
): stdClass {
    global $USER;

    // Presentations above this many slides import in the background. There is no
    // admin settings page for booktools, so this uses a sensible fixed default
    // (overridable via forced_plugin_settings / the CLI if a site needs to).
    $threshold = get_config('booktool_importpptx', 'asyncthreshold');
    $threshold = ($threshold === false || $threshold === '') ? 30 : (int) $threshold;
    $count = \booktool_importpptx\importer::count_slides($file);

    if ($count > $threshold) {
        $task = new \booktool_importpptx\task\import_task();
        $task->set_custom_data([
            'bookid' => $book->id,
            'cmid' => $cm->id,
            'fileitemid' => $pendingid,
            'imagemaxdim' => (int) ($options['imagemaxdim'] ?? 1600),
            'sectioncolour' => (string) ($options['sectioncolour'] ?? '#442980'),
        ]);
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task);
        return (object) ['queued' => true, 'count' => $count];
    }

    $importer = new \booktool_importpptx\importer($book, $context, $options);
    $created = $importer->import($file);
    \booktool_importpptx\pending_file::delete($context, $pendingid);
    return (object) ['queued' => false, 'count' => $created];
}
