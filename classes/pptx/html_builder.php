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
     * Renders a list of blocks to HTML, grouping consecutive images into grids
     * and pairing short preceding captions with them.
     *
     * @param block[] $blocks The blocks to render (any order).
     * @return string The rendered HTML.
     */
    private function render_items(array $blocks): string {
        $tokens = [];
        foreach (reading_order::sort($blocks) as $b) {
            if ($b->type === block::TYPE_IMAGE) {
                $tokens[] = ['img', $this->register_image($b->content)];
            } else if ($b->type === block::TYPE_HTML) {
                $tokens[] = ['block', $b->content];
            } else if ($b->type === block::TYPE_TEXT) {
                $paras = array_map(static function (string $p): string {
                    return str_replace("\n", '<br>', $p);
                }, $b->content);
                if (count($paras) >= 2) {
                    $lis = '';
                    foreach ($paras as $p) {
                        $lis .= '<li>' . $p . '</li>';
                    }
                    $tokens[] = ['block', '<ul>' . $lis . '</ul>'];
                } else {
                    $tokens[] = ['p', $paras[0]];
                }
            }
        }

        $out = $this->group_images($tokens);

        $html = [];
        foreach ($out as $tok) {
            switch ($tok[0]) {
                case 'p':
                    $html[] = '<p>' . $tok[1] . '</p>';
                    break;
                case 'block':
                    $html[] = $tok[1];
                    break;
                case 'img1':
                    $html[] = '<img src="' . $tok[1] . '" alt="" class="img-fluid">';
                    break;
                case 'grid':
                    $html[] = $this->render_grid($tok[1], $tok[2]);
                    break;
            }
        }
        return implode("\n", $html);
    }

    /**
     * Collapses runs of image tokens into single-image or grid tokens, pairing
     * captions when a run of N images is preceded by exactly N short paragraphs.
     *
     * @param array[] $tokens The flat token list.
     * @return array[] The regrouped token list.
     */
    private function group_images(array $tokens): array {
        $out = [];
        $i = 0;
        $n = count($tokens);
        while ($i < $n) {
            if ($tokens[$i][0] !== 'img') {
                $out[] = $tokens[$i];
                $i++;
                continue;
            }
            $j = $i;
            while ($j < $n && $tokens[$j][0] === 'img') {
                $j++;
            }
            $imgs = array_map(static function (array $t): string {
                return $t[1];
            }, array_slice($tokens, $i, $j - $i));
            $k = count($imgs);
            if ($k === 1) {
                $out[] = ['img1', $imgs[0]];
            } else {
                $caps = null;
                if (count($out) >= $k) {
                    $tail = array_slice($out, -$k);
                    $allshort = true;
                    foreach ($tail as $t) {
                        if (!$this->is_short_paragraph($t)) {
                            $allshort = false;
                            break;
                        }
                    }
                    if ($allshort) {
                        $caps = array_map(static function (array $t): string {
                            return $t[1];
                        }, $tail);
                        array_splice($out, -$k);
                    }
                }
                $out[] = ['grid', $imgs, $caps];
            }
            $i = $j;
        }
        return $out;
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
        return '<div class="row booktool-importpptx-grid">' . $cells . '</div>';
    }

    /**
     * Whether a token is a paragraph short enough to serve as an image caption.
     *
     * @param array $token A single token.
     * @return bool True for a short 'p' token.
     */
    private function is_short_paragraph(array $token): bool {
        if ($token[0] !== 'p') {
            return false;
        }
        $text = str_replace('<br>', ' ', strip_tags($token[1]));
        return \core_text::strlen(trim($text)) <= self::CAPTION_MAX_CHARS;
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
