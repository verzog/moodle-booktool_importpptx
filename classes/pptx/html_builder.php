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
 * Turns ordered slide blocks into editable chapter HTML.
 *
 * @package    booktool_importpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace booktool_importpptx\pptx;

/**
 * Assembles chapter HTML from parsed blocks: paragraphs and lists, responsive
 * image grids with caption pairing, tables/SmartArt, and section-divider heroes.
 *
 * Bootstrap grid classes (bundled with Moodle themes) do the layout so the
 * output inherits the site theme; only the section plate carries bespoke CSS.
 */
class html_builder {
    /** @var int A preceding text block this short (stripped) can be an image caption. */
    const CAPTION_MAX_CHARS = 12;

    /** @var int A single short line up to this length can be promoted to the chapter title. */
    const TITLE_FALLBACK_MAX_CHARS = 60;

    /** @var int Minimum horizontal gap (EMU, ~1 inch) between blocks for a genuine column split. */
    const COLUMN_GAP_EMU = 914400;

    /** @var string Fallback plate colour when a slide's own fill cannot be read. */
    private string $defaultcolour;

    /** @var array Map of chapter filename => source media path in the package. */
    private array $images = [];

    /**
     * Constructor.
     *
     * @param string $defaultcolour Fallback section-plate colour (e.g. "#442980").
     */
    public function __construct(string $defaultcolour) {
        $this->defaultcolour = self::safe_colour($defaultcolour, '#442980');
    }

    /**
     * Builds a chapter from a parsed slide.
     *
     * @param \stdClass $parsed The result of {@see slide::parse()}.
     * @return \stdClass Object with properties:
     *                   - title (?string): chapter title, or null to use "Slide N";
     *                   - html (string): the chapter body HTML;
     *                   - issection (bool): whether this is a section divider;
     *                   - images (array<string,string>): filename => source media path.
     */
    public function build(\stdClass $parsed): \stdClass {
        $this->images = [];
        if ($parsed->section !== null && $parsed->section->panelright !== null) {
            return $this->build_section($parsed);
        }

        $body = $parsed->blocks;
        $title = $parsed->title;
        if ($title === null) {
            [$title, $body] = $this->promote_title(reading_order::sort($body));
        }
        return (object) [
            'title' => $title,
            'html' => $this->render_items($body),
            'issection' => ($parsed->section !== null),
            'images' => $this->images,
        ];
    }

    /**
     * Builds a section-divider chapter as a coloured hero plus content.
     *
     * @param \stdClass $parsed The parsed slide (with a geometry-detected panel).
     * @return \stdClass The chapter object (see {@see html_builder::build()}).
     */
    private function build_section(\stdClass $parsed): \stdClass {
        $panelright = $parsed->section->panelright;

        // Text overlapping the plate is the section label; the rest is content.
        $overlay = [];
        $rest = [];
        foreach ($parsed->blocks as $b) {
            if ($b->type === block::TYPE_TEXT && $b->x < $panelright) {
                $overlay[] = $b;
            } else {
                $rest[] = $b;
            }
        }

        $lines = [];
        foreach (reading_order::sort($overlay) as $b) {
            foreach ($b->content as $para) {
                foreach (explode("\n", $para) as $line) {
                    $line = trim(strip_tags($line));
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                }
            }
        }

        $title = $parsed->title;
        if ($title === null) {
            [$title, $rest] = $this->promote_title(reading_order::sort($rest));
        }

        $lead = array_values(array_filter($rest, static function (block $b): bool {
            return $b->type === block::TYPE_TEXT || $b->type === block::TYPE_HTML;
        }));
        $media = array_values(array_filter($rest, static function (block $b): bool {
            return $b->type === block::TYPE_IMAGE;
        }));

        $colour = self::safe_colour($parsed->section->colour ?? '', $this->defaultcolour);
        $plate = '';
        if (!empty($lines)) {
            $plate = '<div class="booktool-importpptx-plate" style="background-color:' . $colour . ';">'
                . implode('<br>', $lines) . '</div>';
        }
        $lede = $this->render_items($lead);
        $hero = '<div class="booktool-importpptx-section">' . $plate
            . '<div class="booktool-importpptx-lede">' . $lede . '</div></div>';
        $mediahtml = $this->render_items($media);
        $html = trim($hero . ($mediahtml !== '' ? "\n" . $mediahtml : ''));

        return (object) [
            'title' => $title,
            'html' => $html,
            'issection' => true,
            'images' => $this->images,
        ];
    }

    /**
     * Renders a list of blocks to HTML.
     *
     * Blocks are grouped into the horizontal bands reading order already uses;
     * a band holding several side-by-side blocks becomes even Bootstrap columns,
     * so slides laid out in two or three columns (or text beside an image) keep
     * that arrangement. Consecutive image rows collapse into a responsive grid,
     * and a row of short lines directly above an equal-sized row of images is
     * paired as captions.
     *
     * @param block[] $blocks The blocks to render (any order).
     * @return string The rendered HTML.
     */
    private function render_items(array $blocks): string {
        $bands = $this->into_bands(reading_order::sort($blocks));
        $parts = [];
        $count = count($bands);
        $b = 0;
        while ($b < $count) {
            $band = $bands[$b];

            // A row of short lines directly above an equal row of images: captions.
            $caps = $this->caption_texts($band);
            if ($caps !== null && $b + 1 < $count) {
                $next = $this->image_refs($bands[$b + 1]);
                if ($next !== null && count($next) === count($caps)) {
                    $parts[] = $this->render_grid($next, $caps);
                    $b += 2;
                    continue;
                }
            }

            // One or more consecutive image-only rows become a single grid.
            $imgs = $this->image_refs($band);
            if ($imgs !== null) {
                $b2 = $b + 1;
                while ($b2 < $count && ($more = $this->image_refs($bands[$b2])) !== null) {
                    $imgs = array_merge($imgs, $more);
                    $b2++;
                }
                $parts[] = count($imgs) === 1
                    ? $this->render_figure($imgs[0])
                    : $this->render_grid($imgs, null);
                $b = $b2;
                continue;
            }

            // A single block fills the width; several side by side become columns.
            $parts[] = count($band) === 1 ? $this->render_block($band[0]) : $this->render_columns($band);
            $b++;
        }
        return implode("\n", $parts);
    }

    /**
     * Partitions blocks already in reading order into consecutive row bands.
     *
     * @param block[] $blocks Blocks in reading order.
     * @return array[] A list of bands, each a left-to-right block[].
     */
    private function into_bands(array $blocks): array {
        $bands = [];
        $current = [];
        $lastband = null;
        foreach ($blocks as $b) {
            $band = intdiv($b->y, reading_order::ROW_BAND_EMU);
            if ($lastband !== null && $band !== $lastband && $current !== []) {
                $bands[] = $current;
                $current = [];
            }
            $current[] = $b;
            $lastband = $band;
        }
        if ($current !== []) {
            $bands[] = $current;
        }
        return $bands;
    }

    /**
     * Returns the image references for a band if every block in it is an image.
     *
     * @param block[] $band The band's blocks.
     * @return string[]|null Registered @@PLUGINFILE@@ refs, or null if not all images.
     */
    private function image_refs(array $band): ?array {
        $refs = [];
        foreach ($band as $b) {
            if ($b->type !== block::TYPE_IMAGE) {
                return null;
            }
            $refs[] = $this->register_image($b->content);
        }
        return $refs;
    }

    /**
     * Returns caption strings if a band is entirely short, single-line text.
     *
     * @param block[] $band The band's blocks.
     * @return string[]|null Inline caption HTML per block, or null if unsuitable.
     */
    private function caption_texts(array $band): ?array {
        if (count($band) < 2) {
            return null;
        }
        $caps = [];
        foreach ($band as $b) {
            if ($b->type !== block::TYPE_TEXT || count($b->content) !== 1) {
                return null;
            }
            $inline = str_replace("\n", ' ', $b->content[0]);
            if (\core_text::strlen(trim(strip_tags($inline))) > self::CAPTION_MAX_CHARS) {
                return null;
            }
            $caps[] = $inline;
        }
        return $caps;
    }

    /**
     * Renders same-row blocks as columns, but only when they occupy genuinely
     * distinct horizontal regions; blocks sharing an x (stacked or overlaid, such
     * as a picture fill and its text) are stacked in reading order instead.
     *
     * @param block[] $band The band's blocks, left to right.
     * @return string The row HTML.
     */
    private function render_columns(array $band): string {
        $columns = $this->cluster_by_x($band);
        // One horizontal group, or too many to sit side by side cleanly: just stack.
        if (count($columns) < 2 || count($columns) > 4) {
            $stack = '';
            foreach ($band as $b) {
                $stack .= $this->render_block($b);
            }
            return $stack;
        }
        $col = 'col-12 col-md-' . intdiv(12, count($columns));
        $cells = '';
        foreach ($columns as $group) {
            $inner = '';
            foreach ($group as $b) {
                $inner .= $this->render_cell($b);
            }
            $cells .= '<div class="' . $col . '">' . $inner . '</div>';
        }
        return '<div class="row g-3 mb-3 booktool-importpptx-cols">' . $cells . '</div>';
    }

    /**
     * Groups a band's blocks into horizontal clusters (columns): consecutive
     * blocks whose x offsets are within {@see self::COLUMN_GAP_EMU} share a column.
     *
     * @param block[] $band The band's blocks, sorted left to right.
     * @return array[] A list of columns, each a block[] in reading order.
     */
    private function cluster_by_x(array $band): array {
        $clusters = [];
        $current = [];
        $lastx = null;
        foreach ($band as $b) {
            if ($lastx !== null && $b->x - $lastx > self::COLUMN_GAP_EMU && $current !== []) {
                $clusters[] = $current;
                $current = [];
            }
            $current[] = $b;
            $lastx = $b->x;
        }
        if ($current !== []) {
            $clusters[] = $current;
        }
        return $clusters;
    }

    /**
     * Renders a single block's inner HTML with no column or figure wrapper.
     *
     * @param block $b The block.
     * @return string The inner HTML.
     */
    private function render_cell(block $b): string {
        if ($b->type === block::TYPE_IMAGE) {
            return '<img src="' . $this->register_image($b->content) . '" alt="" class="img-fluid">';
        }
        if ($b->type === block::TYPE_HTML) {
            return $b->content;
        }
        if ($b->type === block::TYPE_TEXT) {
            return $this->text_html($b);
        }
        return '';
    }

    /**
     * Renders one full-width block, constraining a lone image to a centred figure.
     *
     * @param block $b The block.
     * @return string The HTML.
     */
    private function render_block(block $b): string {
        if ($b->type === block::TYPE_IMAGE) {
            return $this->render_figure($this->register_image($b->content));
        }
        return $this->render_cell($b);
    }

    /**
     * Renders a text block. A box that suppresses bullets, or holds a single
     * line, becomes paragraphs; a multi-line bulleted box becomes a list that
     * nests wherever the slide indented its bullets, so an outline keeps its
     * heading-and-sub-point structure instead of flattening to one flat list.
     *
     * @param block $b The text block.
     * @return string The rendered HTML.
     */
    private function text_html(block $b): string {
        $paras = array_map(static function (string $p): string {
            return str_replace("\n", '<br>', $p);
        }, (array) $b->content);
        if ($paras === []) {
            return '';
        }
        // Prose (bullets explicitly off) or a single line reads as paragraphs.
        if (!$b->bulleted || count($paras) < 2) {
            $html = '';
            foreach ($paras as $p) {
                $html .= '<p>' . $p . '</p>';
            }
            return $html;
        }
        $levels = $b->levels;
        return $this->nested_list($paras, $levels);
    }

    /**
     * Builds a (possibly nested) unordered list from paragraph HTML and the
     * per-paragraph indent levels PowerPoint recorded.
     *
     * @param string[] $paras Paragraph HTML strings.
     * @param int[] $levels Indent level per paragraph, aligned to $paras.
     * @return string The <ul> HTML.
     */
    private function nested_list(array $paras, array $levels): string {
        // Normalise so the shallowest paragraph sits at depth 0, guarding against
        // decks whose outermost bullets start at a non-zero level.
        $base = $levels === [] ? 0 : min($levels);
        $html = '<ul>';
        $depth = 0;
        $open = false;
        foreach ($paras as $i => $p) {
            $level = max(0, (int) ($levels[$i] ?? 0) - $base);
            if ($open) {
                if ($level > $depth) {
                    // Nest the deeper items inside the item just opened.
                    $html .= '<ul>';
                    $depth++;
                    while ($level > $depth) {
                        $html .= '<li><ul>';
                        $depth++;
                    }
                } else {
                    $html .= '</li>';
                    while ($level < $depth) {
                        $html .= '</ul></li>';
                        $depth--;
                    }
                }
            } else {
                // First item may itself be deeper than the list root.
                while ($level > $depth) {
                    $html .= '<ul>';
                    $depth++;
                }
            }
            $html .= '<li>' . $p;
            $open = true;
        }
        $html .= '</li>';
        while ($depth > 0) {
            $html .= '</ul></li>';
            $depth--;
        }
        return $html . '</ul>';
    }

    /**
     * Renders a lone image as a centred, size-capped figure.
     *
     * @param string $ref The image @@PLUGINFILE@@ reference.
     * @return string The figure HTML.
     */
    private function render_figure(string $ref): string {
        return '<div class="booktool-importpptx-figure"><img src="' . $ref . '" alt="" class="img-fluid"></div>';
    }

    /**
     * Renders a responsive Bootstrap grid of images with optional captions above.
     *
     * @param string[] $imgs Image src references (already @@PLUGINFILE@@ links).
     * @param string[]|null $caps Captions aligned to $imgs, or null for none.
     * @return string The grid HTML.
     */
    private function render_grid(array $imgs, ?array $caps): string {
        // Two images sit 50/50; three or more wrap after three across on large screens.
        $col = count($imgs) === 2 ? 'col-12 col-md-6' : 'col-12 col-md-6 col-lg-4';
        $cells = '';
        foreach ($imgs as $idx => $ref) {
            $cap = '';
            if ($caps !== null && isset($caps[$idx])) {
                $cap = '<div class="booktool-importpptx-cap">' . $caps[$idx] . '</div>';
            }
            $cells .= '<div class="' . $col . '">' . $cap
                . '<img src="' . $ref . '" alt="" class="img-fluid"></div>';
        }
        return '<div class="row g-3 booktool-importpptx-grid">' . $cells . '</div>';
    }

    /**
     * Promotes a short leading line to the chapter title.
     *
     * Only the first block in reading order is considered: if the slide's leading
     * content is not a short single-line text box, no title is promoted, so an
     * image caption or footer further down cannot be pulled out of the body.
     *
     * @param block[] $blocks Blocks in reading order.
     * @return array The [title, remaining-blocks] pair.
     */
    private function promote_title(array $blocks): array {
        if (empty($blocks)) {
            return [null, $blocks];
        }
        $first = $blocks[0];
        if ($first->type === block::TYPE_TEXT && count($first->content) === 1) {
            $plain = self::plain_text($first->content[0]);
            if ($plain !== '' && \core_text::strlen($plain) <= self::TITLE_FALLBACK_MAX_CHARS) {
                array_shift($blocks);
                return [$plain, $blocks];
            }
        }
        return [null, $blocks];
    }

    /**
     * Registers an image for saving and returns its @@PLUGINFILE@@ reference.
     *
     * @param string $mediapath Source media path within the package.
     * @return string The @@PLUGINFILE@@ link to embed in the HTML.
     */
    private function register_image(string $mediapath): string {
        $existing = array_search($mediapath, $this->images, true);
        if ($existing !== false) {
            return '@@PLUGINFILE@@/' . $existing;
        }
        $base = preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename($mediapath));
        if ($base === '' || $base === null) {
            $base = 'image';
        }
        // WMF/EMF are vector formats a browser cannot display; the importer
        // converts them to PNG, so reference the converted name here.
        $base = preg_replace('/\.(wmf|emf)$/i', '.png', $base);
        $name = $base;
        if (isset($this->images[$name])) {
            $dot = strrpos($base, '.');
            $stem = $dot === false ? $base : substr($base, 0, $dot);
            $ext = $dot === false ? '' : substr($base, $dot);
            $counter = 1;
            do {
                $name = $stem . '_' . $counter . $ext;
                $counter++;
            } while (isset($this->images[$name]));
        }
        $this->images[$name] = $mediapath;
        return '@@PLUGINFILE@@/' . $name;
    }

    /**
     * Reduces an escaped HTML fragment to trimmed, decoded plain text.
     *
     * @param string $html The fragment (may contain <br>, <strong>, entities).
     * @return string The plain-text equivalent.
     */
    private static function plain_text(string $html): string {
        $spaced = str_replace(['<br>', '<br/>', '<br />'], ' ', $html);
        return trim(html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Validates a colour string, returning a fallback if it is not #RRGGBB.
     *
     * @param string $colour The candidate colour.
     * @param string $fallback The value to use when $colour is invalid.
     * @return string A safe #RRGGBB colour.
     */
    private static function safe_colour(string $colour, string $fallback): string {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $colour) ? $colour : $fallback;
    }
}
