# PowerPoint import for Book (booktool_importpptx)

A [Moodle Book](https://docs.moodle.org/en/Book_resource) tool that imports a
PowerPoint presentation (`.pptx`) and creates **one editable chapter per slide**,
in slide order. Text, lists, tables, SmartArt and images become ordinary Moodle
HTML that a teacher can keep editing in Atto or TinyMCE — not flat page images.

## Why it runs anywhere

The PowerPoint importer is **pure PHP** for text, tables, SmartArt and raster
images: a `.pptx` is a ZIP of XML, read with PHP's bundled `ZipArchive` and
`DOMDocument`, so that path has no third-party libraries and works on shared and
locked-down hosting.

Two things are handled outside pure PHP, each optional and gated:

- **PDF import** rasterises pages to images and needs the `poppler-utils` binaries
  (`pdfinfo`, `pdftoppm`). When they are absent the PDF option simply does not appear.
- **Vector clip-art (WMF/EMF)** cannot be shown by browsers. A WMF that merely wraps
  a bitmap is unpacked in pure PHP with no dependency; a true vector metafile is
  converted to PNG using whichever of ImageMagick, LibreOffice or Inkscape is
  installed, and is dropped cleanly when none is (see "Vector clip-art" below).

Neither affects the core PowerPoint text/image path, which stays pure PHP.

## What it does

- **One slide → one chapter**, using the slide order from `presentation.xml`.
- **Titles.** A slide's title placeholder becomes the chapter title. With no
  placeholder, a short leading line is promoted; otherwise the chapter is
  `Slide N`.
- **Reading order and columns.** Blocks are ordered top-to-bottom in half-inch
  rows and left-to-right within a row. When a row holds several blocks side by
  side — text beside an image, or two/three columns — they become even Bootstrap
  columns that keep the arrangement on desktop and stack on mobile.
- **Text.** Paragraphs become `<p>`; multi-line text boxes become `<ul>`; bold
  runs and line breaks are preserved; decorative one- or two-character badges are
  dropped.
- **Images.** Pictures are saved into the book's own file area and referenced with
  `@@PLUGINFILE@@`. Both standalone pictures and images used as a shape's fill
  (styled frames and picture placeholders) are recovered. A lone image is centred
  and height-capped rather than stretched full width, and images can optionally be
  down-scaled on import.
- **Vector clip-art (WMF/EMF).** Older decks store clip-art as Windows metafiles,
  which browsers cannot display. A metafile that only wraps a bitmap is unpacked to
  PNG in pure PHP; a true vector metafile is converted to PNG when a converter
  (ImageMagick with a WMF/EMF delegate, LibreOffice, or Inkscape) is installed. When
  a figure cannot be converted it — and any layout container it would leave empty —
  is dropped, so the chapter never shows a broken image.
- **Image grids.** Consecutive images become a responsive Bootstrap grid (up to
  three across, two images split 50/50). A run of images preceded by the same
  number of short lines is captioned, each caption above its image.
- **SmartArt and tables.** SmartArt text is recovered as a list; tables become
  HTML tables with the first row as headers.
- **Section dividers.** A slide with a full-height coloured side panel (detected by
  geometry, using the slide's own fill colour) becomes a styled section chapter,
  and the slides that follow it are nested as subchapters in the book's table of
  contents.
- **Large decks.** Above a fixed slide threshold (30) the import runs as a
  background task, with a confirmation step before any chapters are written.

## PDF import (optional)

When the `poppler-utils` binaries are available on the server, the import form also
accepts a `.pdf`. Because a PDF carries no reliable text or layout structure, each
page is **rendered to a web image** (WebP where GD supports it, otherwise JPEG) and
becomes one chapter — one page → one chapter, in order. These chapters are images
rather than editable HTML, so use PDF import when you want a faithful page-by-page
copy and PowerPoint import when you want editable text.

- Rendering is done with `pdfinfo` and `pdftoppm` at 150 DPI, invoked with argument
  arrays (never a shell string), so there is no command-injection surface.
- Images honour the same **Maximum image dimension** option as slide images.
- A hard cap of 500 pages guards against abusive uploads.
- If the binaries live outside the system path, set their directory in
  `$CFG->forced_plugin_settings['booktool_importpptx']['popplerpath']`.

## Requirements

- Moodle 5.0 or later
- PHP 8.2 or later
- The `zip`, `dom` and (for optional image down-scaling) `gd` PHP extensions
- **Optional, for PDF import only:** the `poppler-utils` package (`pdfinfo`,
  `pdftoppm`) and the `gd` extension

## Installation

Copy the plugin so it lives at `mod/book/tool/importpptx/` (or
`public/mod/book/tool/importpptx/` on Moodle 5.1+), then visit
**Site administration → Notifications** to complete the install.

## Usage

1. Open a Book activity as a teacher.
2. From the book's administration, choose **Import PowerPoint**.
3. Upload a `.pptx` file (or a `.pdf` when the PDF backend is available) and confirm
   the number of chapters to create.
4. The new chapters are added after any existing ones.

## Import options

Book tools cannot expose a Site administration settings page (the Book module
does not load subplugin settings), so the tunable options live on the import
form itself, under **Show more**:

- **Maximum image dimension (px)** — down-scale images on import (`0` keeps
  originals). Default 1600.
- **Section panel colour** — fallback plate colour (e.g. `#442980`) used only
  when a section slide's own fill cannot be read.

Access to the importer is controlled by the `booktool/importpptx:import`
capability. The background-task threshold defaults to 30 slides.

## Honest limits

- PowerPoint gives **editable** chapters; PDF gives **image** chapters (one
  rendered page each) and needs `poppler-utils` on the server.
- SmartArt is flattened to a list — its hierarchy is not preserved.
- Grids re-flow images into an even layout rather than reproducing a slide's exact
  geometry.
- Complex slides (overlapping shapes, charts, animations, embedded video, WordArt)
  are best-effort: text and raster images are recovered; bespoke visuals may not be.
- Section-divider detection relies on a consistent full-height side panel (or the
  section-header layout); decks without one import those slides as ordinary chapters.

## Licence

2026 Vernon Spain.

This program is free software: you can redistribute it and/or modify it under the
terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this
program. If not, see <https://www.gnu.org/licenses/>.
