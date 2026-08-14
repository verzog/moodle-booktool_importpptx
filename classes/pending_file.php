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
 * Durable staging area for an uploaded presentation awaiting import.
 *
 * @package    booktool_importpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace booktool_importpptx;

/**
 * Stores the uploaded .pptx in the module context so that both the confirmation
 * step and a later background task can read it, independent of the user's
 * transient draft file area. One pending upload per book (keyed by book id).
 */
class pending_file {

    /** @var string The file component. */
    const COMPONENT = 'booktool_importpptx';

    /** @var string The file area holding a pending upload. */
    const FILEAREA = 'import';

    /**
     * Copies a draft-area upload into durable storage, replacing any existing one.
     *
     * @param int $draftid The submitted draft item id.
     * @param \context_module $context The book's module context.
     * @param int $bookid The book id (used as the item id).
     * @return \stored_file|null The stored file, or null if the draft was empty.
     */
    public static function store(int $draftid, \context_module $context, int $bookid): ?\stored_file {
        global $USER;

        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $drafts = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftid, 'id DESC', false);
        $draft = reset($drafts);
        if (!$draft) {
            return null;
        }

        self::delete($context, $bookid);
        $filerecord = [
            'contextid' => $context->id,
            'component' => self::COMPONENT,
            'filearea' => self::FILEAREA,
            'itemid' => $bookid,
            'filepath' => '/',
            'filename' => $draft->get_filename(),
        ];
        return $fs->create_file_from_storedfile($filerecord, $draft);
    }

    /**
     * Returns the pending upload for a book, if any.
     *
     * @param \context_module $context The book's module context.
     * @param int $bookid The book id.
     * @return \stored_file|null The stored file, or null when none is staged.
     */
    public static function get(\context_module $context, int $bookid): ?\stored_file {
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, self::COMPONENT, self::FILEAREA, $bookid, 'id DESC', false);
        $file = reset($files);
        return $file ?: null;
    }

    /**
     * Deletes any pending upload for a book.
     *
     * @param \context_module $context The book's module context.
     * @param int $bookid The book id.
     * @return void
     */
    public static function delete(\context_module $context, int $bookid): void {
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, self::COMPONENT, self::FILEAREA, $bookid);
    }
}
