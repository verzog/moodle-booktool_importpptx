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
 * Strings for component booktool_importpptx.
 *
 * @package    booktool_importpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['asyncqueued'] = 'This presentation has {$a} slides, so it is being imported in the background. The chapters will appear in this book shortly.';
$string['confirmimport'] = 'Import PowerPoint';
$string['confirmimportdetail'] = 'Create {$a} chapters in this book from the uploaded presentation? Existing chapters will be kept and the new chapters added after them.';
$string['disabled'] = 'The PowerPoint importer has been disabled by the site administrator.';
$string['errornopptx'] = 'The uploaded file is not a valid PowerPoint (.pptx) presentation.';
$string['errornoslides'] = 'No slides could be found in the uploaded presentation.';
$string['errorstrictooxml'] = 'This presentation was saved as "Strict Open XML". Please re-save it as a standard PowerPoint (.pptx) presentation and try again.';
$string['errortoolarge'] = 'The presentation contains a part that is too large to process safely.';
$string['errortoomanyslides'] = 'The presentation contains too many slides to import ({$a->count}; the limit is {$a->max}).';
$string['eventimportcompleted'] = 'PowerPoint import completed';
$string['file'] = 'PowerPoint presentation';
$string['file_help'] = 'Upload a PowerPoint presentation in .pptx format. Each slide becomes one chapter in the book, with its text, lists, tables and images converted to editable HTML.';
$string['import'] = 'Import';
$string['importpptx'] = 'Import PowerPoint';
$string['importpptx:import'] = 'Import PowerPoint presentations into a book';
$string['importresult'] = 'Imported {$a} chapters from the presentation.';
$string['pluginname'] = 'PowerPoint import';
$string['privacy:metadata'] = 'The PowerPoint import tool does not store any personal data. It creates book chapters and files, which are stored and managed by the Book activity.';
$string['sectiondefault'] = 'Section';
$string['setting_asyncthreshold'] = 'Background task threshold';
$string['setting_asyncthreshold_desc'] = 'Presentations with more slides than this number are imported in the background as a scheduled task rather than during the web request. Set to 0 to always import in the background.';
$string['setting_enabled'] = 'Enable importer';
$string['setting_enabled_desc'] = 'When disabled, the "Import PowerPoint" action is hidden from all books and imports cannot be run. Use this as a kill-switch if the importer misbehaves in production.';
$string['setting_imagemaxdim'] = 'Maximum image dimension (px)';
$string['setting_imagemaxdim_desc'] = 'Images larger than this on their longest edge are down-scaled on import to keep books lean. Set to 0 to keep the original images unchanged.';
$string['setting_sectionpanelcolour'] = 'Section panel colour';
$string['setting_sectionpanelcolour_desc'] = 'Fallback colour for the coloured plate on section-divider chapters. The importer uses the colour detected on the slide when it can; this value is used only when no fill can be read.';
$string['slidetitle'] = 'Slide {$a}';
$string['taskimport'] = 'Import a PowerPoint presentation into a book';
$string['taskinprogress'] = 'A PowerPoint import is already queued for this book. Please wait for it to finish before importing again.';
