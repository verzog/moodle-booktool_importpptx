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
 * Imports a presentation into a book as one rendered image per slide.
 *
 * @package    booktool_importpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace booktool_importpptx;

use booktool_importpptx\office\renderer;

/**
 * "Whole deck as images" backend: renders every slide to a faithful image with
 * LibreOffice (via {@see renderer}) and creates one image chapter per slide, in
 * order. Use this when a deck's slides must look exactly as in PowerPoint and
 * editable text is not required.
 */
class office_importer {
    /** @var \stdClass The target book record. */
    private \stdClass $book;

    /** @var \context_module The book's module context. */
    private \context_module $context;

    /** @var int Maximum image dimension in px (0 keeps the rendered size). */
    private int $imagemaxdim;

    /** @var renderer|null The render backend (injectable for testing). */
    private ?renderer $renderer;

    /**
     * Constructor.
     *
     * @param \stdClass $book The book activity record.
     * @param \context_module $context The book's module context.
     * @param array $options Import options ('imagemaxdim' int).
     * @param renderer|null $renderer The render backend, or null to build the default.
     */
    public function __construct(\stdClass $book, \context_module $context, array $options = [], ?renderer $renderer = null) {
        $this->book = $book;
        $this->context = $context;
        $this->imagemaxdim = (int) ($options['imagemaxdim'] ?? 1600);
        $this->renderer = $renderer;
    }

    /**
     * Counts the slides in a presentation for the image backend.
     *
     * The whole-deck image path does not use the transitional-OOXML parser, so a
     * deck that parser rejects (for example Strict Open XML) can still be
     * rendered by LibreOffice. Counting its slide parts straight from the archive
     * keeps the confirmation count and async threshold working for those decks,
     * instead of failing them at the counting step the editable path uses.
     *
     * @param \stored_file $pptx The uploaded presentation.
     * @return int The number of slide parts in the package.
     * @throws \moodle_exception If the file is not a readable .pptx package.
     */
    public static function count_slides(\stored_file $pptx): int {
        $dir = make_request_directory();
        $path = $dir . '/count.pptx';
        $pptx->copy_content_to($path);
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \moodle_exception('errornopptx', 'booktool_importpptx');
        }
        try {
            $count = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false && preg_match('#^ppt/slides/slide\d+\.xml$#', $name)) {
                    $count++;
                }
            }
            return $count;
        } finally {
            $zip->close();
        }
    }

    /**
     * Imports the presentation, creating one image chapter per slide.
     *
     * @param \stored_file $pptx The uploaded presentation.
     * @return int The number of chapters created.
     */
    public function import(\stored_file $pptx): int {
        global $DB;

        $importsrc = $pptx->get_filename();
        $pagenum = (int) $DB->get_field_sql(
            'SELECT MAX(pagenum) FROM {book_chapters} WHERE bookid = ?',
            [$this->book->id]
        );

        $renderer = $this->renderer ?? new renderer();
        $created = 0;
        foreach ($renderer->render_pages($pptx, $this->imagemaxdim) as [$page, $filename, $bytes]) {
            $title = get_string('slidetitle', 'booktool_importpptx', $page);
            $html = '<img src="@@PLUGINFILE@@/' . $filename . '" alt="' . s($title) . '" class="img-fluid">';
            $pagenum++;
            chapter_writer::write(
                $this->book,
                $this->context,
                $importsrc,
                $title,
                $html,
                [$filename => $bytes],
                $pagenum,
                0
            );
            $created++;
        }

        if ($created > 0) {
            $DB->set_field('book', 'revision', $this->book->revision + 1, ['id' => $this->book->id]);
            $DB->set_field('book', 'timemodified', time(), ['id' => $this->book->id]);
        }
        return $created;
    }
}
