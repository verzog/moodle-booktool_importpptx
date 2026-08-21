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
 * Adhoc task that imports a large presentation in the background.
 *
 * @package    booktool_importpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace booktool_importpptx\task;

/**
 * Runs the chosen importer for presentations above the async threshold.
 */
class import_task extends \core\task\adhoc_task {
    /**
     * Returns the human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskimport', 'booktool_importpptx');
    }

    /**
     * Executes the queued import and removes the staged upload.
     *
     * @return void
     */
    public function execute(): void {
        global $DB, $CFG;

        $data = $this->get_custom_data();
        if (empty($data->cmid) || empty($data->fileitemid)) {
            return;
        }
        $itemid = (int) $data->fileitemid;

        $cm = get_coursemodule_from_id('book', $data->cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $book = $DB->get_record('book', ['id' => $cm->instance]);
        if (!$book) {
            return;
        }
        $context = \context_module::instance($cm->id);

        $file = \booktool_importpptx\pending_file::get($context, $itemid);
        if ($file === null) {
            return;
        }

        $options = [
            'imagemaxdim' => (int) ($data->imagemaxdim ?? 1600),
            'sectioncolour' => (string) ($data->sectioncolour ?? '#442980'),
            'importmode' => (string) ($data->importmode ?? 'editable'),
            'cardgroup' => !empty($data->cardgroup),
            'bodysize' => (int) ($data->bodysize ?? 0),
            'adjacentsize' => (int) ($data->adjacentsize ?? 0),
            'smartartimages' => !empty($data->smartartimages),
            'renderfont' => (string) ($data->renderfont ?? ''),
        ];

        // Do not delete the staged upload in a finally: if the import throws on a
        // transient error, Moodle retries the adhoc task and needs the input intact.
        require_once($CFG->dirroot . '/mod/book/tool/importpptx/locallib.php');
        $importer = booktool_importpptx_importer($file, $book, $context, $options);
        $count = $importer->import($file);
        mtrace("booktool_importpptx: imported {$count} chapters into book {$book->id}.");
        \booktool_importpptx\pending_file::delete($context, $itemid);
    }

    /**
     * Whether an import task is already queued for the given book.
     *
     * @param int $bookid The book id.
     * @return bool True if a matching adhoc task is pending.
     */
    public static function is_queued(int $bookid): bool {
        $tasks = \core\task\manager::get_adhoc_tasks('\\booktool_importpptx\\task\\import_task');
        foreach ($tasks as $task) {
            $data = $task->get_custom_data();
            if (!empty($data->bookid) && (int) $data->bookid === $bookid) {
                return true;
            }
        }
        return false;
    }
}
