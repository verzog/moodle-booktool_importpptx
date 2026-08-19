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
     * A tall image and the text beside it, with different tops but overlapping
     * heights, are laid out side by side (image left) rather than stacked.
     */
    public function test_overlapping_blocks_columned_by_height(): void {
        $builder = new html_builder('#442980');
        $image = new block(block::TYPE_IMAGE, 400000, 300000, 'ppt/media/photo.png');
        $image->cy = 5000000;
        $image->cx = 5500000;
        $text = new block(block::TYPE_TEXT, 1500000, 6500000, ['Body text beside the image.']);
        $text->cy = 4000000;
        $text->cx = 5000000;
        $parsed = (object) ['title' => 'Intro', 'section' => null, 'blocks' => [$image, $text]];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('booktool-importpptx-cols', $out->html);
        $this->assertStringContainsString('col-12 col-md-6', $out->html);
        $this->assertStringContainsString('@@PLUGINFILE@@/photo.png', $out->html);
        $this->assertStringContainsString('<p>Body text beside the image.</p>', $out->html);
        // The image sits in the left column, before the text.
        $this->assertLessThan(
            strpos($out->html, 'Body text beside the image.'),
            strpos($out->html, '@@PLUGINFILE@@/photo.png')
        );
    }

    /**
     * Blocks whose heights do not overlap stay stacked, not columned.
     */
    public function test_non_overlapping_blocks_stay_stacked(): void {
        $builder = new html_builder('#442980');
        $image = new block(block::TYPE_IMAGE, 400000, 300000, 'ppt/media/top.png');
        $image->cy = 1500000;
        $image->cx = 5000000;
        $text = new block(block::TYPE_TEXT, 2500000, 300000, ['Text below the image.']);
        $text->cy = 1500000;
        $text->cx = 5000000;
        $parsed = (object) ['title' => 'Stack', 'section' => null, 'blocks' => [$image, $text]];
        $out = $builder->build($parsed);
        $this->assertStringNotContainsString('booktool-importpptx-cols', $out->html);
        $this->assertStringContainsString('<p>Text below the image.</p>', $out->html);
    }

    /**
     * Text overlaid on a wide background picture (its span within the picture's)
     * is stacked, not squeezed into adjacent half-width columns.
     */
    public function test_text_over_photo_not_columned(): void {
        $builder = new html_builder('#442980');
        $photo = new block(block::TYPE_IMAGE, 400000, 400000, 'ppt/media/bg.png');
        $photo->cy = 5000000;
        $photo->cx = 9000000;
        $text = new block(block::TYPE_TEXT, 1000000, 2000000, ['Caption over the photo.']);
        $text->cy = 800000;
        $text->cx = 4000000;
        $parsed = (object) ['title' => 'Overlay', 'section' => null, 'blocks' => [$photo, $text]];
        $out = $builder->build($parsed);
        $this->assertStringNotContainsString('booktool-importpptx-cols', $out->html);
        $this->assertStringContainsString('<p>Caption over the photo.</p>', $out->html);
    }

    /**
     * Stacked text boxes sharing a column keep their top-to-bottom order even when
     * the lower box has a slightly smaller x than the upper one.
     */
    public function test_column_blocks_keep_top_to_bottom_order(): void {
        $builder = new html_builder('#442980');
        $image = new block(block::TYPE_IMAGE, 400000, 300000, 'ppt/media/left.png');
        $image->cy = 5000000;
        $image->cx = 5500000;
        $upper = new block(block::TYPE_TEXT, 1000000, 6500000, ['Upper text.']);
        $upper->cy = 1500000;
        $upper->cx = 5000000;
        $lower = new block(block::TYPE_TEXT, 3000000, 6400000, ['Lower text.']);
        $lower->cy = 1500000;
        $lower->cx = 5000000;
        $parsed = (object) ['title' => 'Order', 'section' => null, 'blocks' => [$image, $upper, $lower]];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('booktool-importpptx-cols', $out->html);
        $this->assertLessThan(
            strpos($out->html, 'Lower text.'),
            strpos($out->html, 'Upper text.')
        );
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
     * A lone image that filled most of the slide (a full-bleed title or break
     * graphic) is rendered at that width so it fills the page, not left small.
     */
    public function test_full_slide_image_fills_width(): void {
        $builder = new html_builder('#442980');
        $image = new block(block::TYPE_IMAGE, 0, 0, 'ppt/media/pic.png');
        $image->widthpct = 95;
        $parsed = (object) ['title' => 'Break', 'section' => null, 'blocks' => [$image]];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('booktool-importpptx-figure', $out->html);
        $this->assertStringContainsString('width:95%', $out->html);
    }

    /**
     * A small lone image keeps its natural size (no forced width), so a logo or
     * thumbnail is not stretched up to the full column width.
     */
    public function test_small_image_keeps_natural_size(): void {
        $builder = new html_builder('#442980');
        $image = new block(block::TYPE_IMAGE, 3000000, 3000000, 'ppt/media/pic.png');
        $image->widthpct = 30;
        $parsed = (object) ['title' => 'Logo', 'section' => null, 'blocks' => [$image]];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('booktool-importpptx-figure', $out->html);
        $this->assertStringNotContainsString('style="width:', $out->html);
    }

    /**
     * A run's on-slide font size is preserved. An explicit run size wins; a run
     * that sets none inherits its paragraph level's size from the slide master's
     * txStyles, so large body text is not flattened to the reader default.
     */
    public function test_font_size_preserved_from_run_and_master(): void {
        $package = new package(__DIR__ . '/fixtures/sample_typography.pptx');
        $parsed = (new slide($package, 'ppt/slides/slide1.xml'))->parse();
        $text = '';
        foreach ($parsed->blocks as $b) {
            if ($b->type === block::TYPE_TEXT) {
                $text .= implode('|', (array) $b->content);
            }
        }
        $package->close();
        // The body placeholder inherits the master body style by outline level:
        // level 1 resolves to 28pt and level 2 to 24pt.
        $this->assertStringContainsString('font-size:28pt', $text);
        $this->assertStringContainsString('font-size:24pt', $text);
        // The free text box sets its own 12pt run size, which wins over inheritance.
        $this->assertStringContainsString('font-size:12pt', $text);
    }

    /**
     * The body-text size selector forces its point size on ordinary body text,
     * overriding (and dropping) the size the slide carried.
     */
    public function test_body_font_size_override(): void {
        $builder = new html_builder('#442980', false, 16, 0);
        $block = new block(block::TYPE_TEXT, 0, 0, ['<span style="font-size:28pt;">A body line long enough to stay body.</span>']);
        $block->levels = [0];
        $block->nobullets = [true];
        $parsed = (object) ['title' => 'Chapter', 'section' => null, 'blocks' => [$block]];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('font-size:16pt', $out->html);
        $this->assertStringNotContainsString('font-size:28pt', $out->html);
    }

    /**
     * The override strips only the parser's own font-size spans, so slide text
     * that literally reads like a CSS size declaration is left untouched.
     */
    public function test_size_override_preserves_literal_size_text(): void {
        $builder = new html_builder('#442980', false, 16, 0);
        $block = new block(block::TYPE_TEXT, 0, 0, ['<span style="font-size:28pt;">Set font-size: 12pt; here.</span>']);
        $block->levels = [0];
        $block->nobullets = [true];
        $parsed = (object) ['title' => 'Chapter', 'section' => null, 'blocks' => [$block]];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('Set font-size: 12pt; here.', $out->html);
        $this->assertStringContainsString('font-size:16pt', $out->html);
        $this->assertStringNotContainsString('font-size:28pt', $out->html);
    }

    /**
     * The text-beside-image size selector forces its point size on text that
     * shares a row with an image, independently of the body-text size.
     */
    public function test_adjacent_font_size_override(): void {
        $builder = new html_builder('#442980', false, 0, 20);
        $image = new block(block::TYPE_IMAGE, 0, 0, 'ppt/media/pic.png');
        $image->cy = 3000000;
        $image->cx = 3000000;
        $text = new block(block::TYPE_TEXT, 0, 4000000, ['<span style="font-size:28pt;">Text beside the image.</span>']);
        $text->levels = [0];
        $text->nobullets = [true];
        $text->cy = 3000000;
        $text->cx = 3000000;
        $parsed = (object) ['title' => 'Chapter', 'section' => null, 'blocks' => [$image, $text]];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('booktool-importpptx-cols', $out->html);
        $this->assertStringContainsString('font-size:20pt', $out->html);
        $this->assertStringNotContainsString('font-size:28pt', $out->html);
    }

    /**
     * Text overlaid on an image (a single cluster, stacked rather than columned)
     * is body text, not beside-image, so it takes the body size not the adjacent.
     */
    public function test_overlaid_text_uses_body_size(): void {
        $builder = new html_builder('#442980', false, 16, 20);
        $image = new block(block::TYPE_IMAGE, 0, 0, 'ppt/media/bg.png');
        $image->cy = 4000000;
        $image->cx = 6000000;
        $text = new block(block::TYPE_TEXT, 500000, 500000, ['<span style="font-size:28pt;">Overlaid caption text.</span>']);
        $text->levels = [0];
        $text->nobullets = [true];
        $text->cy = 1000000;
        $text->cx = 3000000;
        $parsed = (object) ['title' => 'Chapter', 'section' => null, 'blocks' => [$image, $text]];
        $out = $builder->build($parsed);
        $this->assertStringNotContainsString('booktool-importpptx-cols', $out->html);
        $this->assertStringContainsString('font-size:16pt', $out->html);
        $this->assertStringNotContainsString('font-size:20pt', $out->html);
    }

    /**
     * With the card-group option on, a captioned image row becomes a Bootstrap
     * card group: each picture is a card whose caption is the card text and whose
     * click-to-enlarge zoom modal shares the card's id.
     */
    public function test_captioned_images_become_card_group_when_enabled(): void {
        $builder = new html_builder('#442980', true);
        $parsed = (object) [
            'title' => 'Gallery',
            'section' => null,
            'blocks' => [
                new block(block::TYPE_TEXT, 1000000, 0, ['Left']),
                new block(block::TYPE_TEXT, 1000000, 4000000, ['Right']),
                new block(block::TYPE_IMAGE, 2000000, 0, 'ppt/media/a.png'),
                new block(block::TYPE_IMAGE, 2000000, 4000000, 'ppt/media/b.png'),
            ],
        ];
        $out = $builder->build($parsed);
        // Card group, not the plain grid.
        $this->assertStringContainsString('booktool-importpptx-cardgroup', $out->html);
        $this->assertStringNotContainsString('booktool-importpptx-grid', $out->html);
        $this->assertStringContainsString('class="card h-100"', $out->html);
        // Captions become card text.
        $this->assertStringContainsString('<p class="card-text">Left</p>', $out->html);
        $this->assertStringContainsString('<p class="card-text">Right</p>', $out->html);
        // A zoom modal per image, each trigger wired to its own modal by a
        // request-unique id (so cards from different chapters never collide on a
        // shared page such as the Print book view).
        $this->assertStringContainsString('booktool-importpptx-cardmodal', $out->html);
        preg_match_all('/data-bs-target="#(bookImportCard\w+)"/', $out->html, $targets);
        preg_match_all('/id="(bookImportCard\w+)"/', $out->html, $ids);
        $this->assertCount(2, $targets[1]);
        $this->assertCount(2, $ids[1]);
        // Each trigger points at the matching modal, and the two ids differ.
        $this->assertSame($targets[1], $ids[1]);
        $this->assertNotSame($ids[1][0], $ids[1][1]);
        $this->assertStringContainsString('@@PLUGINFILE@@/a.png', $out->html);
        $this->assertStringContainsString('@@PLUGINFILE@@/b.png', $out->html);
    }

    /**
     * With the card-group option on, a lone image becomes a single-card group
     * (no card body, since it has no caption) rather than a centred figure.
     */
    public function test_single_image_becomes_card_when_enabled(): void {
        $builder = new html_builder('#442980', true);
        $parsed = (object) [
            'title' => 'One image',
            'section' => null,
            'blocks' => [
                new block(block::TYPE_IMAGE, 3000000, 3000000, 'ppt/media/pic.png'),
            ],
        ];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('booktool-importpptx-cardgroup', $out->html);
        $this->assertStringContainsString('booktool-importpptx-card', $out->html);
        $this->assertStringNotContainsString('booktool-importpptx-figure', $out->html);
        // No caption, so no card body.
        $this->assertStringNotContainsString('card-body', $out->html);
        $this->assertCount(1, $out->images);
    }

    /**
     * A failed card image drops both its card and its now-imageless zoom modal,
     * and an emptied card group is removed entirely.
     */
    public function test_failed_card_image_removes_card_and_modal(): void {
        $method = new \ReflectionMethod(importer::class, 'strip_images');
        $method->setAccessible(true);
        $builder = new html_builder('#442980', true);
        $parsed = (object) [
            'title' => 'Gallery',
            'section' => null,
            'blocks' => [
                new block(block::TYPE_IMAGE, 2000000, 0, 'ppt/media/keep.png'),
                new block(block::TYPE_IMAGE, 2000000, 4000000, 'ppt/media/gone.png'),
            ],
        ];
        $html = $builder->build($parsed)->html;
        $out = $method->invoke(null, $html, ['gone.png']);
        // The good card and its modal survive; the failed one and its modal go.
        $this->assertStringContainsString('@@PLUGINFILE@@/keep.png', $out);
        $this->assertStringNotContainsString('gone.png', $out);
        $this->assertStringContainsString('booktool-importpptx-cardgroup', $out);
        // Exactly one card and one modal remain, still wired to each other.
        preg_match_all('/id="(bookImportCard\w+)"/', $out, $ids);
        preg_match_all('/data-bs-target="#(bookImportCard\w+)"/', $out, $targets);
        $this->assertCount(1, $ids[1]);
        $this->assertCount(1, $targets[1]);
        $this->assertSame($targets[1][0], $ids[1][0]);
    }

    /**
     * A blank white or transparent placeholder image is detected so it can be
     * pruned, while a solid-colour graphic or a photo is kept.
     */
    public function test_blank_image_detection(): void {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not available.');
        }
        $method = new \ReflectionMethod(importer::class, 'is_blank');
        $method->setAccessible(true);

        $png = static function (callable $paint): string {
            $im = imagecreatetruecolor(40, 30);
            imagealphablending($im, false);
            imagesavealpha($im, true);
            $paint($im);
            ob_start();
            imagepng($im);
            $bytes = ob_get_clean();
            imagedestroy($im);
            return $bytes;
        };

        $white = $png(static function ($im): void {
            imagefilledrectangle($im, 0, 0, 39, 29, imagecolorallocate($im, 255, 255, 255));
        });
        $transparent = $png(static function ($im): void {
            imagefilledrectangle($im, 0, 0, 39, 29, imagecolorallocatealpha($im, 0, 0, 0, 127));
        });
        $solid = $png(static function ($im): void {
            imagefilledrectangle($im, 0, 0, 39, 29, imagecolorallocate($im, 200, 60, 60));
        });
        $mostlywhite = $png(static function ($im): void {
            imagefilledrectangle($im, 0, 0, 39, 29, imagecolorallocate($im, 255, 255, 255));
            imagefilledrectangle($im, 10, 8, 30, 22, imagecolorallocate($im, 20, 40, 90));
        });

        $this->assertTrue($method->invoke(null, $white), 'A white rectangle is blank.');
        $this->assertTrue($method->invoke(null, $transparent), 'A transparent image is blank.');
        $this->assertFalse($method->invoke(null, $solid), 'A solid colour is not blank.');
        $this->assertFalse($method->invoke(null, $mostlywhite), 'White with content is not blank.');
        // Unreadable bytes are kept (returned as not-blank) rather than guessed at.
        $this->assertFalse($method->invoke(null, 'not an image'));
    }

    /**
     * Blocks sharing an x (e.g. a picture fill and its text) stack, not columns.
     */
    public function test_same_x_blocks_are_not_columns(): void {
        $builder = new html_builder('#442980');
        $parsed = (object) [
            'title' => 'Overlay',
            'section' => null,
            'blocks' => [
                new block(block::TYPE_IMAGE, 2000000, 1000000, 'ppt/media/bg.png'),
                new block(block::TYPE_TEXT, 2050000, 1000000, ['Caption over the picture fill.']),
            ],
        ];
        $out = $builder->build($parsed);
        $this->assertStringNotContainsString('booktool-importpptx-cols', $out->html);
        $this->assertStringContainsString('<p>Caption over the picture fill.</p>', $out->html);
    }

    /**
     * A bulleted box keeps its outline: indented paragraphs nest under their parent
     * bullet instead of flattening into one flat list.
     */
    public function test_nested_list_from_indent_levels(): void {
        $builder = new html_builder('#442980');
        $text = new block(block::TYPE_TEXT, 2000000, 1000000, [
            'Always consider culture.',
            'Language Barriers',
            'Patients may be confused.',
            'Brain injuries',
            'Bring a support person.',
        ]);
        $text->levels = [0, 0, 1, 0, 1];
        $parsed = (object) ['title' => 'Body', 'section' => null, 'blocks' => [$text]];
        $out = $builder->build($parsed);
        // The heading bullet owns the indented point that follows it.
        $this->assertStringContainsString(
            '<li>Language Barriers<ul><li>Patients may be confused.</li></ul></li>',
            $out->html
        );
        $this->assertStringContainsString(
            '<li>Brain injuries<ul><li>Bring a support person.</li></ul></li>',
            $out->html
        );
    }

    /**
     * A text box that switches bullets off renders as paragraphs, not a bullet list.
     */
    public function test_unbulleted_text_renders_as_paragraphs(): void {
        $builder = new html_builder('#442980');
        $text = new block(block::TYPE_TEXT, 2000000, 1000000, [
            'First sentence of prose.',
            'Second sentence of prose.',
        ]);
        $text->levels = [0, 0];
        $text->nobullets = [true, true];
        $parsed = (object) ['title' => 'Prose', 'section' => null, 'blocks' => [$text]];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('<p>First sentence of prose.</p>', $out->html);
        $this->assertStringContainsString('<p>Second sentence of prose.</p>', $out->html);
        $this->assertStringNotContainsString('<ul>', $out->html);
    }

    /**
     * A box mixing an unbulleted intro line with a bulleted list renders the intro
     * as prose and only the bulleted paragraphs as a list.
     */
    public function test_mixed_prose_and_bullets_split(): void {
        $builder = new html_builder('#442980');
        $text = new block(block::TYPE_TEXT, 2000000, 1000000, [
            'Here is the introduction.',
            'First bullet point.',
            'Second bullet point.',
        ]);
        $text->levels = [0, 0, 0];
        $text->nobullets = [true, false, false];
        $parsed = (object) ['title' => 'Mixed', 'section' => null, 'blocks' => [$text]];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('<p>Here is the introduction.</p>', $out->html);
        $this->assertStringContainsString(
            '<ul><li>First bullet point.</li><li>Second bullet point.</li></ul>',
            $out->html
        );
        // The intro is not swallowed into the list.
        $this->assertStringNotContainsString('<li>Here is the introduction.', $out->html);
    }

    /**
     * A list that starts deeper than a later item still yields well-formed markup:
     * levels are normalised so no bare <ul> sits inside the root without an <li>.
     */
    public function test_nested_list_normalises_disordered_levels(): void {
        $builder = new html_builder('#442980');
        $text = new block(block::TYPE_TEXT, 2000000, 1000000, ['Indented first.', 'Shallower second.']);
        $text->levels = [1, 0];
        $parsed = (object) ['title' => 'Odd', 'section' => null, 'blocks' => [$text]];
        $out = $builder->build($parsed);
        // Well-formed: DOMDocument parses it without repair changing the structure.
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $out->html . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        // No <ul> is a direct child of another <ul> (which browsers would repair).
        $xpath = new \DOMXPath($doc);
        $this->assertSame(0, $xpath->query('//ul/ul')->length);
        $this->assertStringContainsString('Indented first.', $out->html);
        $this->assertStringContainsString('Shallower second.', $out->html);
    }

    /**
     * A footer placeholder is page furniture and is kept out of the chapter body,
     * while the body placeholder's indent levels drive nesting.
     */
    public function test_footer_dropped_and_levels_parsed_from_slide(): void {
        $body = '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Body"/><p:cNvSpPr/>'
            . '<p:nvPr><p:ph type="body"/></p:nvPr></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="838200" y="1825625"/><a:ext cx="10515600" cy="4351338"/></a:xfrm></p:spPr>'
            . '<p:txBody><a:bodyPr/>'
            . '<a:p><a:r><a:t>Language Barriers</a:t></a:r></a:p>'
            . '<a:p><a:pPr lvl="1"/><a:r><a:t>Patients may be confused.</a:t></a:r></a:p>'
            . '</p:txBody></p:sp>';
        $footer = '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Footer"/><p:cNvSpPr/>'
            . '<p:nvPr><p:ph type="ftr"/></p:nvPr></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="838200" y="6356350"/><a:ext cx="2419350" cy="365125"/></a:xfrm></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:p><a:r><a:t>MC 10 - Cultural Awareness - PPT 1</a:t></a:r></a:p>'
            . '</p:txBody></p:sp>';

        $chapter = $this->build_slide($body . $footer);
        $this->assertStringNotContainsString('MC 10', $chapter->html);
        $this->assertStringContainsString(
            '<li>Language Barriers<ul><li>Patients may be confused.</li></ul></li>',
            $chapter->html
        );
    }

    /**
     * A footer placeholder with an image fill (branded template) is skipped whole:
     * its repeated picture is not imported either.
     */
    public function test_image_filled_footer_is_dropped(): void {
        $footer = '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Footer"/><p:cNvSpPr/>'
            . '<p:nvPr><p:ph type="ftr"/></p:nvPr></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="838200" y="6356350"/><a:ext cx="2419350" cy="365125"/></a:xfrm>'
            . '<a:blipFill><a:blip r:embed="rId5"/></a:blipFill></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:p><a:r><a:t>Brand strip</a:t></a:r></a:p></p:txBody></p:sp>';

        $chapter = $this->build_slide(
            $footer,
            ['rId5' => '../media/brand.png'],
            ['ppt/media/brand.png' => 'PNGDATA']
        );
        $this->assertStringNotContainsString('<img', $chapter->html);
        $this->assertStringNotContainsString('Brand strip', $chapter->html);
        $this->assertSame([], $chapter->images);
    }

    /**
     * WMF/EMF vector images are referenced as PNG (the importer converts them).
     */
    public function test_wmf_image_referenced_as_png(): void {
        $builder = new html_builder('#442980');
        $parsed = (object) [
            'title' => 'Clip',
            'section' => null,
            'blocks' => [
                new block(block::TYPE_IMAGE, 3000000, 3000000, 'ppt/media/image1.wmf'),
            ],
        ];
        $out = $builder->build($parsed);
        $this->assertStringContainsString('@@PLUGINFILE@@/image1.png', $out->html);
        $this->assertStringNotContainsString('image1.wmf', $out->html);
        // The images map still points at the source .wmf for the importer to convert.
        $this->assertSame('ppt/media/image1.wmf', $out->images['image1.png']);
    }

    /**
     * The vector converter fails safely on invalid input, whether or not a tool exists.
     */
    public function test_vector_converter_rejects_invalid_input(): void {
        $this->assertNull(
            \booktool_importpptx\graphics\converter::to_png('not a real metafile', 'wmf')
        );
    }

    /**
     * A bitmap wrapped in a WMF converts to PNG in pure PHP, with no external tool.
     */
    public function test_wmf_bitmap_converted_in_pure_php(): void {
        if (!function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is not available.');
        }
        $png = \booktool_importpptx\graphics\converter::to_png($this->bitmap_wmf(), 'wmf');
        $this->assertNotNull($png);
        $this->assertSame("\x89PNG", substr($png, 0, 4));
    }

    /**
     * A failed image drops its layout container so no empty figure/cell remains.
     */
    public function test_failed_image_removes_its_container(): void {
        $method = new \ReflectionMethod(importer::class, 'strip_images');
        $method->setAccessible(true);
        $html = '<p>Keep</p><div class="booktool-importpptx-figure">'
            . '<img src="@@PLUGINFILE@@/gone.png" alt="" class="img-fluid"></div>';
        $out = $method->invoke(null, $html, ['gone.png']);
        $this->assertStringContainsString('<p>Keep</p>', $out);
        $this->assertStringNotContainsString('gone.png', $out);
        $this->assertStringNotContainsString('booktool-importpptx-figure', $out);
    }

    /**
     * Builds a minimal WMF that wraps a 2x2 24-bit bitmap.
     *
     * @return string The WMF bytes.
     */
    private function bitmap_wmf(): string {
        $bih = pack('VVVvvVVVVVV', 40, 2, 2, 1, 24, 0, 0, 0, 0, 0, 0);
        $row = "\xFF\x00\x00" . "\x00\xFF\x00" . "\x00\x00";
        $dib = $bih . $row . $row;
        $params = pack('V', 0x00CC0020) . pack('v', 0) . pack('vvvv', 2, 2, 0, 0)
            . pack('vvvv', 2, 2, 0, 0) . $dib;
        $recwords = intdiv(6 + strlen($params), 2);
        $stretch = pack('V', $recwords) . pack('v', 0x0F43) . $params;
        $eof = pack('V', 3) . pack('v', 0);
        $totalwords = 9 + intdiv(strlen($stretch), 2) + 3;
        $std = pack('v', 1) . pack('v', 9) . pack('v', 0x0300) . pack('V', $totalwords)
            . pack('v', 0) . pack('V', $recwords) . pack('v', 0);
        $placeable = pack('V', 0x9AC6CDD7) . pack('v', 0) . pack('vvvv', 0, 0, 2, 2)
            . pack('v', 96) . pack('V', 0) . pack('v', 0);
        return $placeable . $std . $stretch . $eof;
    }

    /**
     * A labelled box-and-arrow diagram is reconstructed as an inline SVG figure,
     * keeping the box colours, the arrow and each box's text.
     */
    public function test_diagram_reconstructed_from_labelled_boxes(): void {
        $sptree = $this->diagram_box(10, 'B1', 500000, 2000000, 2600000, 1200000, '442980', 'Step One')
            . $this->diagram_arrow(20, 3200000, 2200000, 800000, 600000)
            . $this->diagram_box(11, 'B2', 4200000, 2000000, 2600000, 1200000, '1F4E79', 'Step Two');
        $chapter = $this->build_slide($sptree);
        $this->assertStringContainsString('booktool-importpptx-diagram', $chapter->html);
        $this->assertStringContainsString('<svg', $chapter->html);
        $this->assertStringContainsString('<polygon', $chapter->html);
        $this->assertStringContainsString('fill="#442980"', $chapter->html);
        $this->assertStringContainsString('fill="#1F4E79"', $chapter->html);
        $this->assertStringContainsString('Step One', $chapter->html);
        $this->assertStringContainsString('Step Two', $chapter->html);
        // The labels live in the figure, not as separate paragraphs.
        $this->assertStringNotContainsString('<p>Step One</p>', $chapter->html);
    }

    /**
     * A single captioned box is not a diagram; it stays editable text.
     */
    public function test_single_box_is_not_a_diagram(): void {
        // A long label avoids the short-line title promotion, so it stays in the body.
        $label = 'This standalone callout box should remain ordinary editable paragraph text.';
        $sptree = $this->diagram_box(10, 'B1', 500000, 2000000, 2600000, 1200000, '442980', $label);
        $chapter = $this->build_slide($sptree);
        $this->assertStringNotContainsString('<svg', $chapter->html);
        $this->assertStringContainsString('<p>' . $label . '</p>', $chapter->html);
    }

    /**
     * Unlabelled shapes (arrows, empty outlines) are treated as annotations and
     * are not reconstructed into a diagram.
     */
    public function test_unlabelled_shapes_are_not_reconstructed(): void {
        $sptree = $this->diagram_arrow(20, 3200000, 2200000, 800000, 600000)
            . $this->diagram_arrow(21, 5200000, 2200000, 800000, 600000)
            . '<p:sp><p:nvSpPr><p:cNvPr id="22" name="O"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="1000000" y="1000000"/><a:ext cx="900000" cy="900000"/></a:xfrm>'
            . '<a:prstGeom prst="ellipse"><a:avLst/></a:prstGeom>'
            . '<a:ln><a:solidFill><a:srgbClr val="FF0000"/></a:solidFill></a:ln></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:p/></p:txBody></p:sp>';
        $chapter = $this->build_slide($sptree);
        $this->assertStringNotContainsString('<svg', $chapter->html);
    }

    /**
     * A slide led by a large photo keeps its image and editable text; captioned
     * boxes on it are not promoted into a diagram figure.
     */
    public function test_photo_slide_suppresses_diagram(): void {
        $pic = '<p:pic><p:nvPicPr><p:cNvPr id="5" name="P"/><p:cNvPicPr/><p:nvPr/></p:nvPicPr>'
            . '<p:blipFill><a:blip r:embed="rId5"/></p:blipFill>'
            . '<p:spPr><a:xfrm><a:off x="400000" y="400000"/><a:ext cx="6000000" cy="4000000"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
        $sptree = $pic
            . $this->diagram_box(10, 'B1', 7000000, 1000000, 2000000, 900000, '442980', 'Alpha')
            . $this->diagram_box(11, 'B2', 7000000, 2200000, 2000000, 900000, '1F4E79', 'Beta');
        $chapter = $this->build_slide(
            $sptree,
            ['rId5' => '../media/image1.png'],
            ['ppt/media/image1.png' => 'PNGDATA']
        );
        $this->assertStringNotContainsString('<svg', $chapter->html);
        $this->assertStringContainsString('@@PLUGINFILE@@/image1.png', $chapter->html);
        $this->assertStringContainsString('<p>Alpha</p>', $chapter->html);
    }

    /**
     * A shape whose fill is a theme scheme colour resolves to the Office default
     * palette when the deck carries no theme part.
     */
    public function test_scheme_colour_fill_resolves_to_default(): void {
        $box1 = '<p:sp><p:nvSpPr><p:cNvPr id="10" name="B1"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="500000" y="2000000"/><a:ext cx="2600000" cy="1200000"/></a:xfrm>'
            . '<a:prstGeom prst="roundRect"><a:avLst/></a:prstGeom>'
            . '<a:solidFill><a:schemeClr val="accent1"/></a:solidFill></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:p><a:r><a:t>Node A</a:t></a:r></a:p></p:txBody></p:sp>';
        $sptree = $box1
            . $this->diagram_box(11, 'B2', 4200000, 2000000, 2600000, 1200000, '1F4E79', 'Node B');
        $chapter = $this->build_slide($sptree);
        // The accent1 slot resolves to #4472C4 in the Office default scheme.
        $this->assertStringContainsString('fill="#4472C4"', $chapter->html);
    }

    /**
     * A diagram label containing markup is escaped, not injected, into the SVG.
     */
    public function test_diagram_label_is_escaped(): void {
        $evil = htmlspecialchars('</tspan><script>alert(1)</script>', ENT_XML1);
        $sptree = $this->diagram_box(10, 'B1', 500000, 2000000, 2600000, 1200000, '442980', $evil)
            . $this->diagram_box(11, 'B2', 4200000, 2000000, 2600000, 1200000, '1F4E79', 'Node B');
        $chapter = $this->build_slide($sptree);
        $this->assertStringContainsString('<svg', $chapter->html);
        $this->assertStringNotContainsString('<script>', $chapter->html);
        $this->assertStringContainsString('&lt;script&gt;', $chapter->html);
    }

    /**
     * Short decision-node labels (Yes/No) still form and populate a diagram.
     */
    public function test_short_label_boxes_reconstruct(): void {
        $sptree = $this->diagram_box(10, 'B1', 500000, 2000000, 1500000, 900000, '6EA9DB', 'Yes')
            . $this->diagram_box(11, 'B2', 4200000, 2000000, 1500000, 900000, '6EA9DB', 'No');
        $chapter = $this->build_slide($sptree);
        $this->assertStringContainsString('<svg', $chapter->html);
        $this->assertStringContainsString('Yes', $chapter->html);
        $this->assertStringContainsString('No', $chapter->html);
    }

    /**
     * Builds a filled, captioned round-rectangle shape for diagram tests.
     *
     * @param int $id The shape id.
     * @param string $name The shape name.
     * @param int $x Left offset in EMU.
     * @param int $y Top offset in EMU.
     * @param int $cx Width in EMU.
     * @param int $cy Height in EMU.
     * @param string $fill Six-hex-digit fill colour (no hash).
     * @param string $text The box label.
     * @return string The p:sp XML.
     */
    private function diagram_box(int $id, string $name, int $x, int $y, int $cx, int $cy, string $fill, string $text): string {
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="' . $name . '"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="roundRect"><a:avLst/></a:prstGeom>'
            . '<a:solidFill><a:srgbClr val="' . $fill . '"/></a:solidFill></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:p><a:r><a:t>' . $text . '</a:t></a:r></a:p></p:txBody></p:sp>';
    }

    /**
     * Builds a right-pointing block-arrow shape for diagram tests.
     *
     * @param int $id The shape id.
     * @param int $x Left offset in EMU.
     * @param int $y Top offset in EMU.
     * @param int $cx Width in EMU.
     * @param int $cy Height in EMU.
     * @return string The p:sp XML.
     */
    private function diagram_arrow(int $id, int $x, int $y, int $cx, int $cy): string {
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Arrow"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rightArrow"><a:avLst/></a:prstGeom>'
            . '<a:solidFill><a:srgbClr val="FF772E"/></a:solidFill></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:p/></p:txBody></p:sp>';
    }

    /**
     * Builds a one-slide deck whose slide shape tree is the given XML, then parses
     * and builds that slide into a chapter (no database needed).
     *
     * @param string $sptree The inner XML of the slide's p:spTree.
     * @param array $rels Optional slide relationships: id => Target (e.g. ['rId5' => '../media/image1.png']).
     * @param array $media Optional media parts: zip path => bytes.
     * @return \stdClass The built chapter object.
     */
    private function build_slide(string $sptree, array $rels = [], array $media = []): \stdClass {
        $nsp = package::NS_P;
        $nsa = package::NS_A;
        $nsr = package::NS_R;
        $nspr = package::NS_PR;

        $dir = make_request_directory();
        $path = $dir . '/one.pptx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>'
        );
        $zip->addFromString(
            'ppt/presentation.xml',
            '<?xml version="1.0"?><p:presentation xmlns:p="' . $nsp . '" xmlns:r="' . $nsr . '">'
                . '<p:sldIdLst><p:sldId id="256" r:id="rId1"/></p:sldIdLst>'
                . '<p:sldSz cx="12192000" cy="6858000"/></p:presentation>'
        );
        $zip->addFromString(
            'ppt/_rels/presentation.xml.rels',
            '<?xml version="1.0"?><Relationships xmlns="' . $nspr . '">'
                . '<Relationship Id="rId1" Type="http://x/slide" Target="slides/slide1.xml"/></Relationships>'
        );
        $zip->addFromString(
            'ppt/slides/slide1.xml',
            '<?xml version="1.0"?><p:sld xmlns:p="' . $nsp . '" xmlns:a="' . $nsa . '" xmlns:r="' . $nsr . '">'
                . '<p:cSld><p:spTree>' . $sptree . '</p:spTree></p:cSld></p:sld>'
        );
        if ($rels !== []) {
            $entries = '';
            foreach ($rels as $id => $target) {
                $entries .= '<Relationship Id="' . $id . '" Type="http://x/image" Target="' . $target . '"/>';
            }
            $zip->addFromString(
                'ppt/slides/_rels/slide1.xml.rels',
                '<?xml version="1.0"?><Relationships xmlns="' . $nspr . '">' . $entries . '</Relationships>'
            );
        }
        foreach ($media as $mpath => $bytes) {
            $zip->addFromString($mpath, $bytes);
        }
        $zip->close();

        $package = new package($path);
        $builder = new html_builder('#442980');
        $paths = $package->get_slide_paths();
        $chapter = $builder->build((new slide($package, $paths[0]))->parse());
        $package->close();
        return $chapter;
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
        // The hero is laid out as a Bootstrap grid: plate in col-3, lede in col-9.
        $this->assertStringContainsString('container-fluid', $section->html);
        $this->assertStringContainsString('<div class="row">', $section->html);
        $this->assertStringContainsString('class="col-12 col-md-3"', $section->html);
        $this->assertStringContainsString('col-12 col-md-9 booktool-importpptx-lede', $section->html);
        // Ordinary slides are not sections.
        $this->assertFalse($chapters[6]->issection);
    }

    /**
     * The section illustration renders inside the lede column, below the lede text,
     * so the coloured plate beside it spans the full height of text plus image.
     */
    public function test_section_media_shares_lede_column(): void {
        $builder = new html_builder('#442980');
        $label = new block(block::TYPE_TEXT, 300000, 300000, ['SECTION TWO']);
        $lede = new block(block::TYPE_TEXT, 4000000, 400000, ['Lede text for the section.']);
        $image = new block(block::TYPE_IMAGE, 4000000, 3000000, 'ppt/media/hero.png');
        $image->cy = 3000000;
        $image->cx = 5000000;
        // A full-bleed slide image: it should be rebased to fill the lede column.
        $image->widthpct = 75;
        $parsed = (object) [
            'title' => 'Section Two',
            'section' => (object) ['panelright' => 3000000, 'colour' => '1F4E79'],
            'blocks' => [$label, $lede, $image],
        ];
        $out = $builder->build($parsed);
        // Parse the fragment and confirm the image is a descendant of the col-md-9
        // lede column (not merely somewhere after its opening tag).
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="root">' . $out->html . '</div>');
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);
        $inlede = $xpath->query('//div[contains(@class, "booktool-importpptx-lede")]//img');
        $this->assertSame(1, $inlede->length);
        // No image escapes the lede column into a trailing figure.
        $this->assertSame(1, $xpath->query('//img')->length);
        // The full-bleed image is rebased to fill the column, not its 75% slide width.
        $this->assertSame('width:100%', $inlede->item(0)->getAttribute('style'));
        // The image follows the lede text within that column.
        $this->assertGreaterThan(
            strpos($out->html, 'Lede text for the section.'),
            strpos($out->html, '@@PLUGINFILE@@/hero.png')
        );
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
     * The importer chosen for an upload follows the file type and the import mode,
     * and the image mode degrades safely to the editable importer when the
     * LibreOffice render backend is unavailable.
     */
    public function test_importer_selection(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->dirroot . '/mod/book/tool/importpptx/locallib.php');

        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $context = \context_module::instance($book->cmid);
        $record = $DB->get_record('book', ['id' => $book->id], '*', MUST_EXIST);

        $pptx = $this->make_named_file('deck.pptx', 'x');
        $pdf = $this->make_named_file('doc.pdf', '%PDF-1.4');

        $this->assertInstanceOf(
            \booktool_importpptx\importer::class,
            booktool_importpptx_importer($pptx, $record, $context, ['importmode' => 'editable'])
        );
        $this->assertInstanceOf(
            \booktool_importpptx\pdf_importer::class,
            booktool_importpptx_importer($pdf, $record, $context, ['importmode' => 'editable'])
        );
        // With no LibreOffice on the test host, "images" mode falls back to editable.
        if (!\booktool_importpptx\office\renderer::is_available()) {
            $this->assertInstanceOf(
                \booktool_importpptx\importer::class,
                booktool_importpptx_importer($pptx, $record, $context, ['importmode' => 'images'])
            );
        }
    }

    /**
     * The image import backend creates one image chapter per rendered slide.
     */
    public function test_office_importer_creates_image_chapters(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $context = \context_module::instance($book->cmid);
        $record = $DB->get_record('book', ['id' => $book->id], '*', MUST_EXIST);

        // A stub renderer stands in for LibreOffice + poppler, yielding fixed pages.
        $renderer = new class extends \booktool_importpptx\office\renderer {
            /**
             * Yields two fixed pages in place of a real LibreOffice render.
             *
             * @param \stored_file $pptx The (ignored) uploaded presentation.
             * @param int $maxdim The (ignored) maximum image dimension.
             * @return \Generator Yields [slidenumber, filename, bytes] arrays.
             */
            public function render_pages(\stored_file $pptx, int $maxdim): \Generator {
                yield [1, 'page-1.png', 'PNGONE'];
                yield [2, 'page-2.png', 'PNGTWO'];
            }
        };
        $file = $this->make_named_file('deck.pptx', 'x', $record->id, $context);
        $importer = new \booktool_importpptx\office_importer($record, $context, ['imagemaxdim' => 0], $renderer);
        $this->assertSame(2, $importer->import($file));

        $chapters = array_values($DB->get_records(
            'book_chapters',
            ['bookid' => $record->id, 'importsrc' => 'deck.pptx'],
            'pagenum ASC'
        ));
        $this->assertCount(2, $chapters);
        $this->assertStringContainsString('@@PLUGINFILE@@/page-1.png', $chapters[0]->content);
        $fs = get_file_storage();
        $this->assertTrue($fs->file_exists(
            $context->id,
            'mod_book',
            'chapter',
            $chapters[0]->id,
            '/',
            'page-1.png'
        ));
    }

    /**
     * The image backend's raw slide counter counts slide parts from the archive,
     * without invoking the editable parser.
     */
    public function test_office_count_slides(): void {
        $this->resetAfterTest();
        $this->assertSame(9, \booktool_importpptx\office_importer::count_slides($this->make_stored_file()));
    }

    /**
     * Image chapters are titled from each slide's own title, falling back to
     * "Slide N" for a slide with no title placeholder.
     */
    public function test_office_importer_titles_from_slides(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $context = \context_module::instance($book->cmid);
        $record = $DB->get_record('book', ['id' => $book->id], '*', MUST_EXIST);

        // Render four fixed pages; the titles come from the real fixture deck.
        $renderer = new class extends \booktool_importpptx\office\renderer {
            /**
             * Yields four fixed pages in place of a real LibreOffice render.
             *
             * @param \stored_file $pptx The (ignored) uploaded presentation.
             * @param int $maxdim The (ignored) maximum image dimension.
             * @return \Generator Yields [slidenumber, filename, bytes] arrays.
             */
            public function render_pages(\stored_file $pptx, int $maxdim): \Generator {
                yield [1, 'page-1.png', 'A'];
                yield [2, 'page-2.png', 'B'];
                yield [3, 'page-3.png', 'C'];
                yield [9, 'page-9.png', 'D'];
            }
        };
        $file = $this->make_stored_file($record->id, $context);
        $importer = new \booktool_importpptx\office_importer($record, $context, ['imagemaxdim' => 0], $renderer);
        $this->assertSame(4, $importer->import($file));

        $chapters = array_values($DB->get_records(
            'book_chapters',
            ['bookid' => $record->id, 'importsrc' => 'sample.pptx'],
            'pagenum ASC'
        ));
        $this->assertSame('Presentation Title', $chapters[0]->title);
        $this->assertSame('Overview', $chapters[1]->title);
        $this->assertSame('Clock', $chapters[2]->title);
        // Slide 9 has no title placeholder, so it falls back to the numbered title.
        $this->assertSame(get_string('slidetitle', 'booktool_importpptx', 9), $chapters[3]->title);
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
