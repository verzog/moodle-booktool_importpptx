# PowerPoint import for Book (booktool_importpptx)

A [Moodle Book](https://docs.moodle.org/en/Book_resource) tool that imports a
PowerPoint presentation (`.pptx`) and creates **one editable chapter per slide**,
in slide order. Text, lists, tables, SmartArt and images become ordinary Moodle
HTML that a teacher can keep editing in Atto or TinyMCE — not flat page images.

## Why it runs anywhere

The importer is **pure PHP**. A `.pptx` is a ZIP of XML, and this plugin reads it
with PHP's bundled `ZipArchive` and `DOMDocument` only. There are **no external
binaries** (no LibreOffice, Ghostscript or `unoconv`), no shell-outs and no
third-party libraries, so it works on shared and locked-down hosting.

## What it does

- **One slide → one chapter**, using the slide order from `presentation.xml`.
- **Titles.** A slide's title placeholder becomes the chapter title. With no
  placeholder, a short leading line is promoted; otherwise the chapter is
  `Slide N`.
- **Reading order.** Blocks are ordered top-to-bottom in half-inch rows and
  left-to-right within a row, so multi-column slides come out in the right order.
- **Text.** Paragraphs become `<p>`; multi-line text boxes become `<ul>`; bold
  runs and line breaks are preserved; decorative one- or two-character badges are
  dropped.
- **Images.** Pictures are saved into the book's own file area and referenced with
  `@@PLUGINFILE@@`. Vector art uses PowerPoint's raster fallback so it renders in a
  browser. Images can optionally be down-scaled on import.
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

## Requirements

- Moodle 5.0 or later
- PHP 8.2 or later
- The `zip`, `dom` and (for optional image down-scaling) `gd` PHP extensions

## Installation

Copy the plugin so it lives at `mod/book/tool/importpptx/` (or
`public/mod/book/tool/importpptx/` on Moodle 5.1+), then visit
**Site administration → Notifications** to complete the install.

## Usage

1. Open a Book activity as a teacher.
2. From the book's administration, choose **Import PowerPoint**.
3. Upload a `.pptx` file and confirm the number of chapters to create.
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

- PowerPoint `.pptx` only; **PDF is not supported**.
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
