@editor @editor_tiny @tiny_recordrtc
Feature: Configure the default video recording size for TinyMCE RecordRTC
  In order to control the video recording resolution used by RecordRTC
  As an admin
  I need to be able to view and change the "Video size" setting

  Background:
    Given I log in as "admin"
    And I navigate to "Plugins > Text editors > TinyMCE editor > RecordRTC" in site administration

  @javascript
  Scenario: The default video size setting is 640 x 480
    Then the field "Video size" matches value "640 x 480 (4:3)"

  @javascript
  Scenario: An admin can change and save the video size setting
    When I set the field "Video size" to "1280 x 720 (16:9)"
    And I click on "Save changes" "button"
    Then I should see "Changes saved"
    And I navigate to "Plugins > Text editors > TinyMCE editor > RecordRTC" in site administration
    And the field "Video size" matches value "1280 x 720 (16:9)"
