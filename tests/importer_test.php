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
