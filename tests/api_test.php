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
 * Unit tests for the API other plugins use to import presentations.
 *
 * @package    booktool_importpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace booktool_importpptx;

/**
 * Tests importing from a server path, and finding and creating the target book.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\booktool_importpptx\api::class)]
final class api_test extends \advanced_testcase {
    /**
     * Returns the absolute path to the fixture deck (nine slides).
     *
     * @return string
     */
    private function fixture(): string {
        return __DIR__ . '/fixtures/sample.pptx';
    }

    /**
     * The API reports its version and the formats that always work.
     */
    public function test_describes_itself(): void {
        // Probing for the optional server tools can cache what it finds.
        $this->resetAfterTest();
        $this->assertSame(1, api::API_VERSION);
        $this->assertContains('pptx', api::extensions());
        $this->assertContains('editable', api::import_modes());
    }

    /**
     * A created book can be found again by name, in its own course only.
     */
    public function test_create_and_find_activity(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();

        $this->assertSame(0, api::find_activity((int) $course->id, 'Induction slides'));
        $cmid = api::create_activity((int) $course->id, 'Induction slides', 1);

        $cm = get_coursemodule_from_id('book', $cmid, 0, true, MUST_EXIST);
        $this->assertEquals($course->id, $cm->course);
        $this->assertEquals(1, $cm->sectionnum);
        $this->assertSame('Induction slides', $DB->get_field('book', 'name', ['id' => $cm->instance]));
        $this->assertSame($cmid, api::find_activity((int) $course->id, 'Induction slides'));
        $this->assertSame(0, api::find_activity((int) $other->id, 'Induction slides'));
    }

    /**
     * Importing from a path creates one chapter per slide and leaves nothing staged.
     */
    public function test_import_path(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $context = \context_module::instance($book->cmid);

        // No user is set: this is how a scheduled task calls it.
        $count = api::import_path($this->fixture(), (int) $book->cmid, ['importmode' => 'nonsense']);

        $this->assertSame(9, $count);
        $this->assertEquals(9, $DB->count_records('book_chapters', ['bookid' => $book->id]));
        $fs = get_file_storage();
        $staged = $fs->get_area_files($context->id, pending_file::COMPONENT, pending_file::FILEAREA, false, 'id', false);
        $this->assertCount(0, $staged);
        $this->assertFileExists($this->fixture());
    }

    /**
     * A second import adds its chapters after the existing ones.
     */
    public function test_import_path_appends(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);

        api::import_path($this->fixture(), (int) $book->cmid);
        api::import_path($this->fixture(), (int) $book->cmid);

        $this->assertEquals(18, $DB->count_records('book_chapters', ['bookid' => $book->id]));
        $this->assertEquals(18, $DB->get_field('book_chapters', 'MAX(pagenum)', ['bookid' => $book->id]));
    }

    /**
     * Files that are missing, or not an importable type, are refused before anything is written.
     *
     * @param string $filename File to create in a temporary folder ('' for none).
     * @param string $errorcode The expected error string identifier.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_file_provider')]
    public function test_import_path_refuses_bad_files(string $filename, string $errorcode): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $path = make_request_directory() . '/' . ($filename === '' ? 'missing.pptx' : $filename);
        if ($filename !== '') {
            file_put_contents($path, 'Not a presentation.');
        }

        try {
            api::import_path($path, (int) $book->cmid);
            $this->fail('Expected a moodle_exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
        $this->assertEquals(0, $DB->count_records('book_chapters', ['bookid' => $book->id]));
        $this->assertEquals(0, $DB->count_records('files', ['component' => pending_file::COMPONENT]));
    }

    /**
     * Data provider for {@see self::test_import_path_refuses_bad_files()}.
     *
     * @return array[]
     */
    public static function bad_file_provider(): array {
        return [
            'missing file' => ['', 'errorfilemissing'],
            'wrong extension' => ['notes.docx', 'errorfiletype'],
            'script extension' => ['deck.pptx.php', 'errorfiletype'],
            'not really a pptx' => ['fake.pptx', 'errornopptx'],
        ];
    }
}
