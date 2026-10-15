Feature: Publish a GameSystem draft
  In order to let solo players play what I authored
  As a game manager
  I want to publish my draft as a new release, read the history and go back to an earlier release

  Background:
    Given the game manager "ann"
    And ann created the blank GameSystem "my-system" named "My system"

  Scenario: A draft is published with release notes
    When ann publishes the GameSystem "my-system" with the notes "First cut."
    Then the latest release of "my-system" is version 1 named "My system"
    And ann sees release 1 of "my-system" with the notes "First cut."
    And ann sees the GameSystem "my-system" at release 1 without unpublished changes

  Scenario: An invalid draft is not published
    Given the sections of ann's draft "my-system" are emptied
    When ann publishes the GameSystem "my-system"
    Then the publish is refused at "oracles"
    And the GameSystem "my-system" has 0 releases

  Scenario: A draft equal to the latest release is not published again
    Given ann published the GameSystem "my-system"
    When ann publishes the GameSystem "my-system"
    Then the publish is refused because there are no changes
    And the GameSystem "my-system" has 1 release

  Scenario: The history lists the releases newest first
    Given ann published the GameSystem "my-system" with the notes "First cut."
    And ann renames the GameSystem "my-system" to "Renamed" with the theme "noir"
    And ann published the GameSystem "my-system"
    Then ann sees the history of "my-system" with the versions "2, 1"
    And ann sees release 2 of "my-system" named "Renamed" with 0 flows

  Scenario: Restoring a release replaces the draft
    Given ann published the GameSystem "my-system"
    And ann renames the GameSystem "my-system" to "Renamed" with the theme "noir"
    When ann restores release 1 of "my-system"
    Then ann sees the GameSystem "my-system" named "My system" at release 1 without unpublished changes

  Scenario: Restoring an unknown release changes nothing
    Given ann renames the GameSystem "my-system" to "Renamed" with the theme "noir"
    When ann restores release 4 of "my-system"
    Then the publish is refused because the release does not exist
    And ann sees the GameSystem "my-system" named "Renamed"

  Scenario: Another game manager cannot publish the draft
    Given the game manager "bob"
    When bob publishes the GameSystem "my-system"
    Then the GameSystem is not found
    And the GameSystem "my-system" has 0 releases
