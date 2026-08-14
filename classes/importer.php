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
 * Orchestrates a PowerPoint import into a book.
 *
 * @package    booktool_importpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace booktool_importpptx;

use booktool_importpptx\pptx\package;
use booktool_importpptx\pptx\slide;
use booktool_importpptx\pptx\html_builder;

/**
 * Reads a .pptx and creates one book chapter per slide, in slide order.
 */
class importer {
    /** @var \stdClass The target book record. */
    private \stdClass $book;

    /** @var \context_module The book's module context. */
    private \context_module $context;

    /**
     * Constructor.
     *
     * @param \stdClass $book The book activity record.
     * @param \context_module $context The book's module context.
     */
    public function __construct(\stdClass $book, \context_module $context) {
        $this->book = $book;
        $this->context = $context;
    }

    /**
     * Counts the slides in a presentation without importing it.
     *
     * @param \stored_file $pptx The uploaded presentation.
     * @return int The number of slides.
     */
    public static function count_slides(\stored_file $pptx): int {
        $path = self::stage($pptx);
        $package = new package($path);
        try {
            return count($package->get_slide_paths());
        } finally {
            $package->close();
        }
    }

    /**
     * Imports the presentation, creating chapters and saving images.
     *
     * @param \stored_file $pptx The uploaded presentation.
     * @return int The number of chapters created.
     */
    public function import(\stored_file $pptx): int {
        global $DB;

        $maxdim = (int) get_config('booktool_importpptx', 'imagemaxdim');
        $defaultcolour = (string) get_config('booktool_importpptx', 'sectionpanelcolour');
        if ($defaultcolour === '') {
            $defaultcolour = '#442980';
        }

        $path = self::stage($pptx);
        $package = new package($path);
        $builder = new html_builder($defaultcolour);

        try {
            $slidepaths = $package->get_slide_paths();

            $pagenum = (int) $DB->get_field_sql(
                'SELECT MAX(pagenum) FROM {book_chapters} WHERE bookid = ?',
                [$this->book->id]
            );
            $insection = false;
            $created = 0;

            foreach ($slidepaths as $index => $slidepath) {
                $parsed = (new slide($package, $slidepath))->parse();
                $chapter = $builder->build($parsed);

                if ($chapter->issection) {
                    $subchapter = 0;
                    $insection = true;
                } else {
                    $subchapter = $insection ? 1 : 0;
                }

                $title = $chapter->title;
                if ($title === null || trim($title) === '') {
                    $title = get_string('slidetitle', 'booktool_importpptx', $index + 1);
                }

                $pagenum++;
                $this->write_chapter(
                    $package,
                    $pptx->get_filename(),
                    $title,
                    $chapter->html,
                    $chapter->images,
                    $pagenum,
                    $subchapter,
                    $maxdim
                );
                $created++;
            }

            // Bump the book so caches and the TOC refresh on next view.
            $DB->set_field('book', 'revision', $this->book->revision + 1, ['id' => $this->book->id]);
            $DB->set_field('book', 'timemodified', time(), ['id' => $this->book->id]);

            return $created;
        } finally {
            $package->close();
        }
    }

    /**
     * Writes one chapter row and saves its images into mod_book's file area.
     *
     * @param package $package The open package (source of image bytes).
     * @param string $importsrc The uploaded file name, recorded on the chapter.
     * @param string $title The chapter title (plain text).
     * @param string $html The chapter body HTML (with @@PLUGINFILE@@ references).
     * @param array $images Map of chapter filename to source media path in the package.
     * @param int $pagenum The chapter's page number within the book.
     * @param int $subchapter 1 if this is a subchapter, otherwise 0.
     * @param int $maxdim Maximum image dimension in px (0 keeps originals).
     * @return void
     */
    private function write_chapter(
        package $package,
        string $importsrc,
        string $title,
        string $html,
        array $images,
        int $pagenum,
        int $subchapter,
        int $maxdim
    ): void {
        global $DB;

        $now = time();
        $chapter = (object) [
            'bookid' => $this->book->id,
            'pagenum' => $pagenum,
            'subchapter' => $subchapter,
            'title' => $title,
            'content' => $html,
            'contentformat' => FORMAT_HTML,
            'hidden' => 0,
            'importsrc' => $importsrc,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $chapter->id = $DB->insert_record('book_chapters', $chapter);

        $fs = get_file_storage();
        foreach ($images as $filename => $mediapath) {
            $bytes = $package->get_bytes($mediapath);
            if ($bytes === null || $bytes === '') {
                continue;
            }
            if ($maxdim > 0) {
                $bytes = self::downscale($bytes, $maxdim);
            }
            $filerecord = [
                'contextid' => $this->context->id,
                'component' => 'mod_book',
                'filearea' => 'chapter',
                'itemid' => $chapter->id,
                'filepath' => '/',
                'filename' => $filename,
            ];
            if (!$fs->file_exists($this->context->id, 'mod_book', 'chapter', $chapter->id, '/', $filename)) {
                $fs->create_file_from_string($filerecord, $bytes);
            }
        }

        $event = \mod_book\event\chapter_created::create([
            'context' => $this->context,
            'objectid' => $chapter->id,
        ]);
        $event->add_record_snapshot('book_chapters', $chapter);
        $event->add_record_snapshot('book', $this->book);
        $event->trigger();
    }

    /**
     * Copies a stored file to a local temporary path ZipArchive can open.
     *
     * @param \stored_file $file The stored file.
     * @return string Absolute path to the staged copy.
     */
    private static function stage(\stored_file $file): string {
        $dir = make_request_directory();
        $path = $dir . '/import.pptx';
        $file->copy_content_to($path);
        return $path;
    }

    /**
     * Down-scales image bytes so the longest edge is at most $maxdim, using GD.
     *
     * Falls back to the original bytes when GD is unavailable, the format is
     * unsupported, or the image is already within bounds. GD is a bundled PHP
     * extension, not an external binary, so this respects the no-shell-out rule.
     *
     * @param string $bytes The original image bytes.
     * @param int $maxdim Maximum longest-edge size in pixels.
     * @return string The resized bytes, or the originals if no change was made.
     */
    private static function downscale(string $bytes, int $maxdim): string {
        if (!function_exists('imagecreatefromstring')) {
            return $bytes;
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return $bytes;
        }
        [$width, $height] = $info;
        if ($width <= $maxdim && $height <= $maxdim) {
            return $bytes;
        }
        $type = $info[2];
        $supported = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
        if (!in_array($type, $supported, true)) {
            return $bytes;
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return $bytes;
        }
        $scale = $maxdim / max($width, $height);
        $newwidth = max(1, (int) round($width * $scale));
        $newheight = max(1, (int) round($height * $scale));
        $resized = imagescale($source, $newwidth, $newheight);
        imagedestroy($source);
        if ($resized === false) {
            return $bytes;
        }

        ob_start();
        switch ($type) {
            case IMAGETYPE_PNG:
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagepng($resized);
                break;
            case IMAGETYPE_GIF:
                imagegif($resized);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($resized);
                break;
            default:
                imagejpeg($resized, null, 85);
                break;
        }
        $out = ob_get_clean();
        imagedestroy($resized);
        return ($out === false || $out === '') ? $bytes : $out;
    }
}
