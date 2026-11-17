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
 * Entry point for other plugins that import presentations into books.
 *
 * @package    booktool_importpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace booktool_importpptx;

/**
 * A small, stable API for importing a presentation from a file on the server.
 *
 * The upload form (index.php) depends on the logged-in user's draft files and
 * session. Background callers, such as a scheduled task in another plugin, have
 * neither, so they use this class instead. Check {@see self::API_VERSION} before
 * calling: it only goes up when a method here changes in a way callers must
 * know about.
 *
 * Nothing here checks a capability, because a background caller has no user.
 * The caller is responsible for deciding who may start an import, for example
 * by requiring {@see self::CAPABILITY} or a site administrator setting.
 */
class api {
    /** @var int Version of this API. */
    public const API_VERSION = 1;

    /** @var string The capability the upload form requires in the book's context. */
    public const CAPABILITY = 'booktool/importpptx:import';

    /** @var string The activity module this plugin imports into. */
    public const MODULE = 'book';

    /**
     * Lists the file extensions that can be imported on this server.
     *
     * @return string[] Lower-case extensions without the dot.
     */
    public static function extensions(): array {
        $extensions = ['pptx'];
        if (pdf\renderer::is_available()) {
            $extensions[] = 'pdf';
        }
        return $extensions;
    }

    /**
     * Lists the import modes available on this server.
     *
     * @return string[] 'editable', plus 'images' when slides can be rendered to images.
     */
    public static function import_modes(): array {
        $modes = ['editable'];
        if (office\renderer::is_available()) {
            $modes[] = 'images';
        }
        return $modes;
    }

    /**
     * Finds a book in a course by its exact name.
     *
     * @param int $courseid The course id.
     * @param string $name The book's name.
     * @return int The course module id of the oldest matching book, or 0 when there is none.
     */
    public static function find_activity(int $courseid, string $name): int {
        global $DB;

        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {book} b ON b.id = cm.instance
                 WHERE cm.course = :courseid
                       AND m.name = :modname
                       AND b.name = :name
                       AND cm.deletioninprogress = 0
              ORDER BY cm.id";
        $params = ['courseid' => $courseid, 'modname' => self::MODULE, 'name' => self::clean_name($name)];
        $records = $DB->get_records_sql($sql, $params, 0, 1);
        $record = reset($records);
        return $record ? (int) $record->id : 0;
    }

    /**
     * Creates an empty book in a course.
     *
     * Core saves the book's (empty) description files against the current user, so
     * a user must be set. Cron and adhoc tasks run as the admin user, which is enough.
     *
     * @param int $courseid The course id.
     * @param string $name The book's name.
     * @param int $section The course section number to add it to.
     * @return int The new book's course module id.
     */
    public static function create_activity(int $courseid, string $name, int $section = 0): int {
        global $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->libdir . '/filelib.php');

        $course = get_course($courseid);
        $info = (object) [
            'modulename' => self::MODULE,
            'course' => $course->id,
            'section' => max(0, $section),
            'visible' => 1,
            'name' => self::clean_name($name),
            'introeditor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => file_get_unused_draft_itemid()],
            'numbering' => (int) get_config('book', 'numbering'),
            'navstyle' => (int) get_config('book', 'navstyle'),
            'customtitles' => 0,
        ];
        return (int) create_module($info)->coursemodule;
    }

    /**
     * Imports a presentation from a file on the server into an existing book.
     *
     * The import runs straight away, however many slides there are, so call this
     * from a background task. New chapters are added after any existing ones.
     * The file itself is left where it is.
     *
     * @param string $path Absolute path to a .pptx (or, when available, .pdf) file.
     * @param int $cmid The book's course module id.
     * @param array $options The upload form's options: 'importmode' ('editable' or
     *                       'images'), 'imagemaxdim', 'sectioncolour', 'cardgroup',
     *                       'bodysize', 'adjacentsize', 'smartartimages', 'renderfont'.
     * @return int The number of chapters created.
     */
    public static function import_path(string $path, int $cmid, array $options = []): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/book/tool/importpptx/locallib.php');

        if (!is_file($path) || !is_readable($path)) {
            throw new \moodle_exception('errorfilemissing', 'booktool_importpptx', '', basename($path));
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::extensions(), true)) {
            throw new \moodle_exception('errorfiletype', 'booktool_importpptx', '', basename($path));
        }

        $cm = get_coursemodule_from_id(self::MODULE, $cmid, 0, false, MUST_EXIST);
        $book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);
        if (task\import_task::is_queued((int) $book->id)) {
            throw new \moodle_exception('taskinprogress', 'booktool_importpptx');
        }

        $options = self::clean_options($options);
        $file = pending_file::store_path($path, $context);
        try {
            return booktool_importpptx_importer($file, $book, $context, $options)->import($file);
        } finally {
            pending_file::delete($context, (int) $file->get_itemid());
        }
    }

    /**
     * Tidies an activity name the same way for finding and creating, so the two always agree.
     *
     * @param string $name The name as supplied by the caller.
     * @return string The name trimmed and cut to the 255 characters the database holds.
     */
    private static function clean_name(string $name): string {
        return \core_text::substr(trim($name), 0, 255);
    }

    /**
     * Reduces caller-supplied options to the known keys, with safe types and defaults.
     *
     * @param array $options Options as passed to {@see self::import_path()}.
     * @return array The options the importers accept.
     */
    private static function clean_options(array $options): array {
        $mode = (string) ($options['importmode'] ?? 'editable');
        return [
            'importmode' => in_array($mode, self::import_modes(), true) ? $mode : 'editable',
            'imagemaxdim' => max(0, (int) ($options['imagemaxdim'] ?? 1600)),
            'sectioncolour' => (string) ($options['sectioncolour'] ?? '#442980'),
            'cardgroup' => !empty($options['cardgroup']),
            'bodysize' => max(0, (int) ($options['bodysize'] ?? 0)),
            'adjacentsize' => max(0, (int) ($options['adjacentsize'] ?? 0)),
            'smartartimages' => !empty($options['smartartimages']),
            'renderfont' => (string) ($options['renderfont'] ?? ''),
        ];
    }
}
