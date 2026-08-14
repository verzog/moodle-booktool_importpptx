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
    And I log in as "admin"

  Scenario: Importing a deck creates a chapter per slide
    Given I am on the "Test book" "book activity" page
    When I navigate to "Import PowerPoint" in current page administration
    And I upload "mod/book/tool/importpptx/tests/fixtures/sample.pptx" file to "PowerPoint presentation" filemanager
    And I press "Import"
    Then I should see "Create 9 chapters"
    When I press "Continue"
    Then I should see "Imported 9 chapters"
    And I should see "Overview"
    And I should see "Getting Started"

  Scenario: The importer can be disabled by an administrator
    Given the following config values are set as admin:
      | enabled | 0 | booktool_importpptx |
    And I am on the "Test book" "book activity" page
    Then "Import PowerPoint" "link" should not exist in current page administration
