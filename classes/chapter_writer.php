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
 * Writes a single book chapter and its images into mod_book.
 *
 * @package    booktool_importpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace booktool_importpptx;

/**
 * Inserts a chapter row, stores its images in mod_book's chapter file area, and
 * fires the chapter-created event. Shared by the PDF backend so image bytes that
 * do not originate from a package can be written the same way.
 */
class chapter_writer {
    /**
     * Writes one chapter and returns its id.
     *
     * @param \stdClass $book The target book record.
     * @param \context_module $context The book's module context.
     * @param string $importsrc The uploaded file name, recorded on the chapter.
     * @param string $title The chapter title (plain text; truncated to 255 chars).
     * @param string $html The chapter body HTML (with @@PLUGINFILE@@ references).
     * @param array $images Map of filename to raw image bytes to store.
     * @param int $pagenum The chapter's page number within the book.
     * @param int $subchapter 1 if this is a subchapter, otherwise 0.
     * @return int The new chapter id.
     */
    public static function write(
        \stdClass $book,
        \context_module $context,
        string $importsrc,
        string $title,
        string $html,
        array $images,
        int $pagenum,
        int $subchapter
    ): int {
        global $DB;

        $now = time();
        $chapter = (object) [
            'bookid' => $book->id,
            'pagenum' => $pagenum,
            'subchapter' => $subchapter,
            'title' => \core_text::substr($title, 0, 255),
            'content' => $html,
            'contentformat' => FORMAT_HTML,
            'hidden' => 0,
            'importsrc' => $importsrc,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $chapter->id = $DB->insert_record('book_chapters', $chapter);

        $fs = get_file_storage();
        foreach ($images as $filename => $bytes) {
            if ($bytes === null || $bytes === '') {
                continue;
            }
            if ($fs->file_exists($context->id, 'mod_book', 'chapter', $chapter->id, '/', $filename)) {
                continue;
            }
            $fs->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'mod_book',
                'filearea' => 'chapter',
                'itemid' => $chapter->id,
                'filepath' => '/',
                'filename' => $filename,
            ], $bytes);
        }

        $event = \mod_book\event\chapter_created::create([
            'context' => $context,
            'objectid' => $chapter->id,
        ]);
        $event->add_record_snapshot('book_chapters', $chapter);
        $event->add_record_snapshot('book', $book);
        $event->trigger();

        return $chapter->id;
    }
}
