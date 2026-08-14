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
 * Unit tests for the PowerPoint importer.
 *
 * @package    booktool_importpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace booktool_importpptx;

use booktool_importpptx\pptx\package;
use booktool_importpptx\pptx\slide;
use booktool_importpptx\pptx\html_builder;
use booktool_importpptx\pptx\block;

/**
 * Tests extraction and chapter creation against a synthetic fixture deck.
 *
 * The fixture (tests/fixtures/sample.pptx) contains one slide of each supported
 * case: a title slide, a bullets+image slide, three captioned images, a SmartArt
 * slide, a table slide, a section divider, an ordinary follower, a decorative
 * badge, and a no-title fallback slide.
 *
 * @covers \booktool_importpptx\importer
 */
final class importer_test extends \advanced_testcase {
    /**
     * Returns the absolute path to the fixture deck.
     *
     * @return string
     */
    private function fixture(): string {
        return __DIR__ . '/fixtures/sample.pptx';
    }

    /**
     * Parses every slide of the fixture into chapter objects (no database needed).
     *
     * @return \stdClass[] One built-chapter object per slide, in order.
     */
    private function build_all(): array {
        $package = new package($this->fixture());
        $builder = new html_builder('#442980');
        $chapters = [];
        foreach ($package->get_slide_paths() as $path) {
            $parsed = (new slide($package, $path))->parse();
            $chapters[] = $builder->build($parsed);
        }
        $package->close();
        return $chapters;
    }

    /**
     * The fixture is recognised as a nine-slide presentation.
     */
    public function test_count_slides(): void {
        $this->resetAfterTest();
        $file = $this->make_stored_file();
        $this->assertSame(9, importer::count_slides($file));
    }

    /**
     * Title placeholders map to chapter titles; a short line is promoted when absent.
     */
    public function test_titles_and_fallback(): void {
        $chapters = $this->build_all();
        $titles = array_map(static fn($c) => $c->title, $chapters);
        $this->assertSame('Presentation Title', $titles[0]);
        $this->assertSame('Overview', $titles[1]);
        $this->assertSame('Clock', $titles[2]);
        // Slide 9 has no title placeholder: the first short line is promoted.
        $this->assertSame('Short Heading', $titles[8]);
    }

    /**
     * Text becomes lists and paragraphs; bold survives; a decorative badge is dropped.
     */
    public function test_text_lists_bold_and_badges(): void {
        $chapters = $this->build_all();
        // Two paragraphs become a list; the bold run is preserved.
        $this->assertStringContainsString('<ul>', $chapters[1]->html);
        $this->assertStringContainsString('<strong>First point</strong>', $chapters[1]->html);
        // The two-line "Real / content" keeps its line break.
        $this->assertStringContainsString('Real<br>content', $chapters[7]->html);
        // The decorative "AT" badge (<= 4 chars) is not emitted.
        $this->assertStringNotContainsString('>AT<', $chapters[7]->html);
    }

    /**
     * Reading order keeps a same-row set left-to-right (13:00, 14:00, 15:00).
     */
    public function test_reading_order_left_to_right(): void {
        $chapters = $this->build_all();
        $html = $chapters[2]->html;
        $pos1 = strpos($html, '13:00');
        $pos2 = strpos($html, '14:00');
        $pos3 = strpos($html, '15:00');
        $this->assertNotFalse($pos1);
        $this->assertLessThan($pos2, $pos1);
        $this->assertLessThan($pos3, $pos2);
    }

    /**
     * Consecutive images form a Bootstrap grid, with preceding short lines as captions.
     */
    public function test_image_grid_with_captions(): void {
        $chapters = $this->build_all();
        $html = $chapters[2]->html;
        $this->assertStringContainsString('booktool-importpptx-grid', $html);
        $this->assertStringContainsString('col-12 col-md-6 col-lg-4', $html);
        $this->assertStringContainsString('<div class="booktool-importpptx-cap">13:00</div>', $html);
        $this->assertCount(3, $chapters[2]->images);
    }

    /**
     * Two blocks sharing a horizontal band are laid out as side-by-side columns.
     */
    public function test_side_by_side_blocks_become_columns(): void {
        $builder = new html_builder('#442980');
        $parsed = (object) [
            'title' => 'Two up',
            'section' => null,
            'blocks' => [
                new block(block::TYPE_TEXT, 2000000, 0, ['Left column paragraph.']),
                new block(block::TYPE_IMAGE, 2000000, 7000000, 'ppt/media/image1.png'),
            ],
        ];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('booktool-importpptx-cols', $out->html);
        $this->assertStringContainsString('col-12 col-md-6', $out->html);
        $this->assertStringContainsString('<p>Left column paragraph.</p>', $out->html);
        $this->assertStringContainsString('@@PLUGINFILE@@/image1.png', $out->html);
    }

    /**
     * A lone image is wrapped in a centred, size-capped figure, not left full-bleed.
     */
    public function test_single_image_is_a_constrained_figure(): void {
        $builder = new html_builder('#442980');
        $parsed = (object) [
            'title' => 'One image',
            'section' => null,
            'blocks' => [
                new block(block::TYPE_IMAGE, 3000000, 3000000, 'ppt/media/pic.png'),
            ],
        ];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('booktool-importpptx-figure', $out->html);
        $this->assertStringNotContainsString('booktool-importpptx-grid', $out->html);
        $this->assertCount(1, $out->images);
    }

    /**
     * SmartArt text is recovered as a flat list; tables become HTML tables.
     */
    public function test_smartart_and_table(): void {
        $chapters = $this->build_all();
        $this->assertStringContainsString(
            '<ul><li>Step A</li><li>Step B</li><li>Step C</li></ul>',
            $chapters[3]->html
        );
        $this->assertStringContainsString('<table', $chapters[4]->html);
        $this->assertStringContainsString('<th>Day</th>', $chapters[4]->html);
        $this->assertStringContainsString('<td>Mon</td>', $chapters[4]->html);
    }

    /**
     * A section divider is detected by geometry, styled with the slide's own colour.
     */
    public function test_section_detection(): void {
        $chapters = $this->build_all();
        $section = $chapters[5];
        $this->assertTrue($section->issection);
        $this->assertSame('Getting Started', $section->title);
        $this->assertStringContainsString('booktool-importpptx-plate', $section->html);
        $this->assertStringContainsString('background-color:#1f4e79', $section->html);
        $this->assertStringContainsString('SECTION ONE', $section->html);
        // Ordinary slides are not sections.
        $this->assertFalse($chapters[6]->issection);
    }

    /**
     * A full import creates one chapter per slide, nests followers under the divider,
     * and saves images into mod_book's chapter file area.
     */
    public function test_full_import_into_book(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $context = \context_module::instance($book->cmid);
        $record = $DB->get_record('book', ['id' => $book->id], '*', MUST_EXIST);

        $file = $this->make_stored_file($record->id, $context);
        $importer = new importer($record, $context);
        $created = $importer->import($file);
        $this->assertSame(9, $created);

        // Consider only the chapters this import created (a book may seed one).
        $all = $DB->get_records('book_chapters', ['bookid' => $record->id], 'pagenum ASC');
        $chapters = array_values(array_filter($all, static function ($c) {
            return $c->importsrc === 'sample.pptx';
        }));
        $this->assertCount(9, $chapters);

        // Page numbers are contiguous and increasing.
        $this->assertSame((int) $chapters[0]->pagenum + 8, (int) $chapters[8]->pagenum);

        // The divider is a top-level chapter; the slides after it are subchapters.
        $this->assertSame(0, (int) $chapters[5]->subchapter);
        $this->assertSame('Getting Started', $chapters[5]->title);
        $this->assertSame(1, (int) $chapters[6]->subchapter);
        $this->assertSame(1, (int) $chapters[7]->subchapter);

        // Slides before any divider stay top-level.
        $this->assertSame(0, (int) $chapters[0]->subchapter);

        // The image on slide 2 was saved into mod_book's chapter area and referenced.
        $this->assertStringContainsString('@@PLUGINFILE@@/image1.png', $chapters[1]->content);
        $fs = get_file_storage();
        $this->assertTrue($fs->file_exists(
            $context->id,
            'mod_book',
            'chapter',
            $chapters[1]->id,
            '/',
            'image1.png'
        ));
    }

    /**
     * A non-zip upload is rejected with a clear error.
     */
    public function test_rejects_non_pptx(): void {
        $this->resetAfterTest();
        $dir = make_request_directory();
        $path = $dir . '/notreal.pptx';
        file_put_contents($path, 'this is not a zip');

        $this->expectException(\moodle_exception::class);
        new package($path);
    }

    /**
     * A Strict Open XML presentation is rejected rather than imported empty.
     */
    public function test_rejects_strict_ooxml(): void {
        $this->resetAfterTest();
        $dir = make_request_directory();
        $path = $dir . '/strict.pptx';
        $strictns = 'http://purl.oclc.org/ooxml/presentationml/main';

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>'
        );
        $zip->addFromString(
            'ppt/presentation.xml',
            '<?xml version="1.0"?><p:presentation xmlns:p="' . $strictns . '"><p:sldIdLst/></p:presentation>'
        );
        $zip->close();

        $this->expectException(\moodle_exception::class);
        new package($path);
    }

    /**
     * The backend is chosen by file extension.
     */
    public function test_backend_type_detection(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/book/tool/importpptx/locallib.php');

        $this->assertSame('pdf', booktool_importpptx_type($this->make_named_file('doc.pdf', '%PDF-1.4')));
        $this->assertSame('pptx', booktool_importpptx_type($this->make_named_file('deck.pptx', 'x')));
    }

    /**
     * When poppler is available, a PDF imports one image chapter per page.
     */
    public function test_pdf_import(): void {
        global $DB;
        $this->resetAfterTest();
        if (!\booktool_importpptx\pdf\renderer::is_available()) {
            $this->markTestSkipped('The poppler utilities (pdfinfo, pdftoppm) are not installed on this host.');
        }

        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $context = \context_module::instance($book->cmid);
        $record = $DB->get_record('book', ['id' => $book->id], '*', MUST_EXIST);

        $file = $this->make_named_file('doc.pdf', $this->make_pdf(3), $record->id, $context);
        $this->assertSame(3, \booktool_importpptx\pdf_importer::count_pages($file));

        $importer = new \booktool_importpptx\pdf_importer($record, $context, ['imagemaxdim' => 1000]);
        $this->assertSame(3, $importer->import($file));

        $chapters = array_values($DB->get_records(
            'book_chapters',
            ['bookid' => $record->id, 'importsrc' => 'doc.pdf'],
            'pagenum ASC'
        ));
        $this->assertCount(3, $chapters);
        $this->assertStringContainsString('@@PLUGINFILE@@/page-1.', $chapters[0]->content);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_book', 'chapter', $chapters[0]->id, 'id', false);
        $this->assertNotEmpty($files);
    }

    /**
     * Builds a valid PDF with the given number of blank pages.
     *
     * @param int $pages The number of pages.
     * @return string The PDF bytes.
     */
    private function make_pdf(int $pages): string {
        $objs = [
            1 => '<</Type/Catalog/Pages 2 0 R>>',
        ];
        $kids = [];
        for ($i = 0; $i < $pages; $i++) {
            $kids[] = (3 + $i) . ' 0 R';
            $objs[3 + $i] = '<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>';
        }
        $objs[2] = '<</Type/Pages/Kids[' . implode(' ', $kids) . ']/Count ' . $pages . '>>';
        ksort($objs);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefpos = strlen($pdf);
        $size = count($objs) + 1;
        $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
        for ($n = 1; $n < $size; $n++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $pdf .= "trailer\n<</Size " . $size . "/Root 1 0 R>>\nstartxref\n" . $xrefpos . "\n%%EOF";
        return $pdf;
    }

    /**
     * Stores a named file with the given content in the plugin's import area.
     *
     * @param string $filename The file name (its extension drives backend selection).
     * @param string $content The file bytes.
     * @param int|null $itemid Item id to store under.
     * @param \context|null $context Context to store in (defaults to system).
     * @return \stored_file
     */
    private function make_named_file(
        string $filename,
        string $content,
        ?int $itemid = null,
        ?\context $context = null
    ): \stored_file {
        $context = $context ?? \context_system::instance();
        $fs = get_file_storage();
        return $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'booktool_importpptx',
            'filearea' => 'import',
            'itemid' => $itemid ?? 1,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * Builds a stored_file from the fixture in the plugin's import area.
     *
     * @param int|null $bookid Item id to store under (defaults to a constant).
     * @param \context|null $context Context to store in (defaults to system).
     * @return \stored_file
     */
    private function make_stored_file(?int $bookid = null, ?\context $context = null): \stored_file {
        $context = $context ?? \context_system::instance();
        $fs = get_file_storage();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'booktool_importpptx',
            'filearea' => 'import',
            'itemid' => $bookid ?? 1,
            'filepath' => '/',
            'filename' => 'sample.pptx',
        ];
        // Remove any earlier copy so the test is repeatable within a run.
        $fs->delete_area_files($context->id, 'booktool_importpptx', 'import', $bookid ?? 1);
        return $fs->create_file_from_pathname($filerecord, $this->fixture());
    }
}
