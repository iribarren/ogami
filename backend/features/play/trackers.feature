Feature: Keep a campaign's Trackers
  In order to follow the pressure building in my story
  As a solo player
  I want my campaign to hold a value for every Tracker of its GameSystem release, and to set them by hand

  Background:
    Given release version 1 of the GameSystem "heist" named "Heist" is published with trackers

  Scenario: A new campaign starts its Trackers, and a hand edit stays within the Tracker's range
    Given I created the campaign "The job" with the GameSystem "heist"
    Then my campaign's Trackers are:
      | tracker | value | level |
      | alarm   | 0     |       |
      | heat    | -5    | Cold  |
      | chaos   | 5     |       |
    When I set the Tracker "heat" to 12
    And I set the Tracker "alarm" to 4
    Then my campaign's Trackers are:
      | tracker | value | level |
      | alarm   | 4     |       |
      | heat    | 5     | Hot   |
      | chaos   | 5     |       |
