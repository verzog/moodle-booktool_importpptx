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
     * Returns the title for a rendered page: the slide's own title, else "Slide N".
     *
     * @param array $titles Map of 1-based slide position to title text.
     * @param int $page The 1-based rendered page (slide) number.
     * @return string The chapter title, bounded to a database-safe length.
     */
    private static function page_title(array $titles, int $page): string {
        $title = isset($titles[$page]) ? trim((string) $titles[$page]) : '';
        if ($title === '') {
            return get_string('slidetitle', 'booktool_importpptx', $page);
        }
        return \core_text::substr($title, 0, 255);
    }

    /**
     * Extracts each slide's title text, in slide order, for the image backend.
     *
     * The rendered pages carry no text, so titles are read straight from the
     * archive with namespace-agnostic XPath — which also copes with Strict Open
     * XML, exactly the kind of deck the image path exists for. Slides with no
     * title placeholder map to an empty string, and any read failure yields an
     * empty map so the caller falls back to numbered titles.
     *
     * @param \stored_file $pptx The uploaded presentation.
     * @return array Map of 1-based slide position to title text.
     */
    private static function extract_titles(\stored_file $pptx): array {
        try {
            $dir = make_request_directory();
            $path = $dir . '/titles.pptx';
            $pptx->copy_content_to($path);
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                return [];
            }
            try {
                $titles = [];
                $position = 0;
                foreach (self::slide_order($zip) as $slidepath) {
                    $position++;
                    $titles[$position] = self::slide_title_text($zip, $slidepath);
                }
                return $titles;
            } finally {
                $zip->close();
            }
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Returns the slide part paths in presentation order.
     *
     * @param \ZipArchive $zip The open package.
     * @return string[] Zip entry names of the slides, in order.
     */
    private static function slide_order(\ZipArchive $zip): array {
        $presentation = self::load_xml($zip, 'ppt/presentation.xml');
        $rels = self::load_xml($zip, 'ppt/_rels/presentation.xml.rels');
        if ($presentation === null || $rels === null) {
            return [];
        }
        $targets = [];
        foreach ($rels->getElementsByTagName('*') as $rel) {
            if ($rel->localName === 'Relationship' && $rel->getAttribute('Id') !== '') {
                $targets[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
            }
        }
        $paths = [];
        $xpath = new \DOMXPath($presentation);
        foreach ($xpath->query("//*[local-name()='sldIdLst']/*[local-name()='sldId']") as $sldid) {
            $rid = self::relationship_id($sldid);
            $target = ($rid !== null && isset($targets[$rid])) ? self::resolve_target($targets[$rid]) : null;
            if ($target !== null && $zip->locateName($target) !== false) {
                $paths[] = $target;
            }
        }
        return $paths;
    }

    /**
     * Reads the relationship id (r:id) of a sldId element, namespace-agnostically.
     *
     * @param \DOMElement $sldid The p:sldId element.
     * @return string|null The relationship id, or null if absent.
     */
    private static function relationship_id(\DOMElement $sldid): ?string {
        foreach (iterator_to_array($sldid->attributes) as $attr) {
            if ($attr->localName === 'id' && (string) $attr->namespaceURI !== '') {
                return $attr->value;
            }
        }
        return null;
    }

    /**
     * Resolves a presentation relationship target to a package (zip) path.
     *
     * @param string $target The relationship Target (relative to ppt/, or absolute).
     * @return string|null The normalised zip entry name, or null if empty.
     */
    private static function resolve_target(string $target): ?string {
        if ($target === '') {
            return null;
        }
        if ($target[0] === '/') {
            return ltrim($target, '/');
        }
        $parts = [];
        foreach (explode('/', 'ppt/' . $target) as $seg) {
            if ($seg === '..') {
                array_pop($parts);
            } else if ($seg !== '.' && $seg !== '') {
                $parts[] = $seg;
            }
        }
        return implode('/', $parts);
    }

    /**
     * Extracts the title placeholder's text from a slide part.
     *
     * @param \ZipArchive $zip The open package.
     * @param string $path The slide's zip entry name.
     * @return string The title text (empty when the slide has no title placeholder).
     */
    private static function slide_title_text(\ZipArchive $zip, string $path): string {
        $doc = self::load_xml($zip, $path);
        if ($doc === null) {
            return '';
        }
        $xpath = new \DOMXPath($doc);
        $query = "//*[local-name()='sp'][.//*[local-name()='ph'][@type='title' or @type='ctrTitle']][1]";
        $sp = $xpath->query($query)->item(0);
        if (!$sp instanceof \DOMElement) {
            return '';
        }
        $lines = [];
        foreach ($xpath->query(".//*[local-name()='p']", $sp) as $paragraph) {
            $line = '';
            foreach ($xpath->query(".//*[local-name()='t']", $paragraph) as $run) {
                $line .= $run->textContent;
            }
            if (trim($line) !== '') {
                $lines[] = $line;
            }
        }
        return trim(preg_replace('/\s+/u', ' ', implode(' ', $lines)));
    }

    /**
     * Loads a package part into a DOMDocument, or null if missing/unparseable.
     *
     * @param \ZipArchive $zip The open package.
     * @param string $name The zip entry name.
     * @return \DOMDocument|null The parsed document, or null.
     */
    private static function load_xml(\ZipArchive $zip, string $name): ?\DOMDocument {
        $data = $zip->getFromName($name);
        if ($data === false || $data === '') {
            return null;
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($data, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok ? $doc : null;
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
        $titles = self::extract_titles($pptx);
        $created = 0;
        foreach ($renderer->render_pages($pptx, $this->imagemaxdim) as [$page, $filename, $bytes]) {
            $title = self::page_title($titles, $page);
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
