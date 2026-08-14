@mod @mod_book @booktool @booktool_importpptx @_file_upload
Feature: Import a PowerPoint presentation into a book
  In order to reuse existing slides as course material
  As a teacher
  I need to import a .pptx and get one editable chapter per slide

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "activities" exist:
      | activity | name      | intro           | course | idnumber |
      | book     | Test book | Book for import | C1     | book1    |

  @javascript
  Scenario: The import action is available from the book navigation
    When I am on the "Test book" "book activity" page logged in as "admin"
    Then "Import PowerPoint" "link" should exist

  @javascript
  Scenario: Importing a deck creates a chapter per slide
    When I am on the "book1" "booktool_importpptx > Import" page logged in as "admin"
    And I upload "mod/book/tool/importpptx/tests/fixtures/sample.pptx" file to "PowerPoint or PDF file" filemanager
    And I press "Import"
    Then I should see "Create 9 chapters"
    When I press "Continue"
    Then I should see "Imported 9 chapters"
    And I should see "Overview"
    And I should see "Getting Started"
