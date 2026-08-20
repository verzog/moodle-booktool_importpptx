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
 * Upload form for the PowerPoint import tool.
 *
 * @package    booktool_importpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace booktool_importpptx\form;

/**
 * Presents the .pptx file picker and the Import button.
 */
class import_form extends \moodleform {
    /**
     * Defines the form elements.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;

        // PDF is only offered when the server has a working PDF renderer.
        $pdfenabled = !empty($this->_customdata['pdfenabled']);
        $accepted = $pdfenabled ? ['.pptx', '.pdf'] : ['.pptx'];
        $labelkey = $pdfenabled ? 'filewithpdf' : 'file';

        $mform->addElement(
            'filepicker',
            'pptxfile',
            get_string($labelkey, 'booktool_importpptx'),
            null,
            ['accepted_types' => $accepted, 'maxfiles' => 1]
        );
        $mform->addRule('pptxfile', null, 'required', null, 'client');
        $mform->addHelpButton('pptxfile', $labelkey, 'booktool_importpptx');

        // A quick read-out of which rendering features this server can offer, and
        // the binary any unavailable one still needs.
        $mform->addElement('static', 'availability', '', $this->availability_html());

        // How to import: editable HTML, or faithful slide images (LibreOffice).
        // The image mode is only offered when the render backend is available.
        if (!empty($this->_customdata['officeenabled'])) {
            $mform->addElement('select', 'importmode', get_string('optionimportmode', 'booktool_importpptx'), [
                'editable' => get_string('importmodeeditable', 'booktool_importpptx'),
                'images' => get_string('importmodeimages', 'booktool_importpptx'),
            ]);
            $mform->setDefault('importmode', 'editable');
            $mform->addHelpButton('importmode', 'optionimportmode', 'booktool_importpptx');
        } else {
            $mform->addElement('hidden', 'importmode', 'editable');
        }
        $mform->setType('importmode', PARAM_ALPHA);

        // Advanced, per-import options (booktool subplugins cannot expose site
        // admin settings, so the tunables live on the import form instead).
        $mform->addElement('text', 'imagemaxdim', get_string('optionimagemaxdim', 'booktool_importpptx'));
        $mform->setType('imagemaxdim', PARAM_INT);
        $mform->setDefault('imagemaxdim', 1600);
        $mform->addHelpButton('imagemaxdim', 'optionimagemaxdim', 'booktool_importpptx');
        $mform->setAdvanced('imagemaxdim');

        // Editable-mode only: render plain image runs as a Bootstrap card group
        // (the same markup the tiny_bootstrap editor plugin inserts).
        $mform->addElement('advcheckbox', 'cardgroup', get_string('optioncardgroup', 'booktool_importpptx'));
        $mform->setType('cardgroup', PARAM_BOOL);
        $mform->setDefault('cardgroup', 0);
        $mform->addHelpButton('cardgroup', 'optioncardgroup', 'booktool_importpptx');
        $mform->setAdvanced('cardgroup');
        $mform->hideIf('cardgroup', 'importmode', 'eq', 'images');

        // Editable-mode only: keep SmartArt slides (which flatten to a bare list)
        // as faithful rendered images. Only offered when the render backend exists.
        if (!empty($this->_customdata['officeenabled'])) {
            $mform->addElement('advcheckbox', 'smartartimages', get_string('optionsmartartimages', 'booktool_importpptx'));
            $mform->setDefault('smartartimages', 0);
            $mform->addHelpButton('smartartimages', 'optionsmartartimages', 'booktool_importpptx');
            $mform->setAdvanced('smartartimages');
            $mform->hideIf('smartartimages', 'importmode', 'eq', 'images');
        } else {
            $mform->addElement('hidden', 'smartartimages', 0);
        }
        $mform->setType('smartartimages', PARAM_BOOL);

        // Editable-mode only: force a point size on body text and on text that
        // sits beside an image, overriding the sizes carried over from the slide.
        $sizes = [0 => get_string('fontsizekeep', 'booktool_importpptx')];
        foreach ([12, 14, 16, 18, 20, 24, 28, 32, 36] as $pt) {
            $sizes[$pt] = get_string('fontsizeoption', 'booktool_importpptx', $pt);
        }
        $mform->addElement('select', 'bodysize', get_string('optionbodysize', 'booktool_importpptx'), $sizes);
        $mform->setType('bodysize', PARAM_INT);
        $mform->setDefault('bodysize', 0);
        $mform->addHelpButton('bodysize', 'optionbodysize', 'booktool_importpptx');
        $mform->setAdvanced('bodysize');
        $mform->hideIf('bodysize', 'importmode', 'eq', 'images');

        $mform->addElement('select', 'adjacentsize', get_string('optionadjacentsize', 'booktool_importpptx'), $sizes);
        $mform->setType('adjacentsize', PARAM_INT);
        $mform->setDefault('adjacentsize', 0);
        $mform->addHelpButton('adjacentsize', 'optionadjacentsize', 'booktool_importpptx');
        $mform->setAdvanced('adjacentsize');
        $mform->hideIf('adjacentsize', 'importmode', 'eq', 'images');

        $mform->addElement(
            'text',
            'sectioncolour',
            get_string('optionsectioncolour', 'booktool_importpptx'),
            ['size' => 8, 'maxlength' => 7]
        );
        $mform->setType('sectioncolour', PARAM_TEXT);
        $mform->setDefault('sectioncolour', '#442980');
        $mform->addHelpButton('sectioncolour', 'optionsectioncolour', 'booktool_importpptx');
        $mform->setAdvanced('sectioncolour');
        $mform->hideIf('sectioncolour', 'importmode', 'eq', 'images');

        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons(true, get_string('import', 'booktool_importpptx'));
    }

    /**
     * Builds the "rendering features on this server" read-out shown on the form.
     *
     * Lists the three binary-dependent features, each with a tick or cross, and —
     * when a feature is unavailable — the binaries it still needs.
     *
     * @return string The panel HTML.
     */
    private function availability_html(): string {
        $poppler = !empty($this->_customdata['popplerenabled']);
        $libreoffice = !empty($this->_customdata['libreofficeenabled']);
        // Missing binaries for the two features that need LibreOffice and poppler.
        $imagemissing = [];
        if (!$libreoffice) {
            $imagemissing[] = 'LibreOffice';
        }
        if (!$poppler) {
            $imagemissing[] = 'Poppler';
        }
        $rows = $this->availability_row(
            get_string('availabilitypdf', 'booktool_importpptx'),
            $poppler,
            $poppler ? [] : ['Poppler']
        );
        $rows .= $this->availability_row(
            get_string('availabilityfaithful', 'booktool_importpptx'),
            $poppler && $libreoffice,
            $imagemissing
        );
        $rows .= $this->availability_row(
            get_string('availabilitycomplex', 'booktool_importpptx'),
            $poppler && $libreoffice,
            $imagemissing
        );
        return '<div class="booktool-importpptx-availability mb-2">'
            . '<p class="fw-bold mb-1">' . get_string('availabilityheading', 'booktool_importpptx') . '</p>'
            . '<ul class="list-unstyled mb-0">' . $rows . '</ul></div>';
    }

    /**
     * Renders one availability row: a tick or cross, the feature name, and any
     * missing binaries.
     *
     * @param string $label The feature's display name.
     * @param bool $available Whether the feature can run on this server.
     * @param string[] $missing The binaries the feature still needs (empty if available).
     * @return string The row's list-item HTML.
     */
    private function availability_row(string $label, bool $available, array $missing): string {
        if ($available) {
            $status = get_string('availabilityyes', 'booktool_importpptx');
            $mark = '<span class="text-success" aria-hidden="true">&#10004;</span>';
            $note = '';
        } else {
            $status = get_string('availabilityno', 'booktool_importpptx');
            $mark = '<span class="text-danger" aria-hidden="true">&#10008;</span>';
            $note = ' <span class="text-muted">&mdash; '
                . get_string('availabilityrequires', 'booktool_importpptx', implode(' + ', $missing))
                . '</span>';
        }
        return '<li>' . $mark . ' <span class="visually-hidden">' . $status . ': </span>'
            . s($label) . $note . '</li>';
    }
}
