Feature: GameSystem drafts
  In order to author a GameSystem before anyone plays it
  As a game manager
  I want my own GameSystem drafts that I can create, describe, validate and discard

  Background:
    Given the game manager "ann"

  Scenario: A blank draft is created and listed
    When ann creates the blank GameSystem "my-system" named "My system"
    Then ann sees the GameSystem "my-system" named "My system" with no release and unpublished changes
    And ann's draft "my-system" is valid

  Scenario: A draft copies the latest published release
    Given a published GameSystem release "free-journal" named "Free journal"
    When ann creates the GameSystem "my-journal" named "My journal" as a copy of "free-journal"
    Then ann sees the GameSystem "my-journal" named "My journal" with no release and unpublished changes
    And ann's draft "my-journal" is valid

  Scenario: A key used by a release is taken
    Given a published GameSystem release "free-journal" named "Free journal"
    When ann creates the blank GameSystem "free-journal" named "Mine"
    Then the GameSystem is refused because the key is taken

  Scenario: Metadata is edited as a whole
    Given ann created the blank GameSystem "my-system" named "My system"
    When ann renames the GameSystem "my-system" to "Renamed" with the theme "noir"
    Then ann sees the GameSystem "my-system" named "Renamed" with the theme "noir"

  Scenario: Invalid metadata is refused and changes nothing
    Given ann created the blank GameSystem "my-system" named "My system"
    When ann renames the GameSystem "my-system" to "Renamed" with the theme "pastel"
    Then the GameSystem is refused at "theme"
    And ann sees the GameSystem "my-system" named "My system" with no release and unpublished changes

  Scenario: An invalid draft reports its first error
    Given a published GameSystem release "free-journal" named "Free journal"
    And ann created the GameSystem "my-journal" named "My journal" as a copy of "free-journal"
    When the sections of ann's draft "my-journal" are emptied
    Then ann's draft "my-journal" is invalid at "oracles"

  Scenario: A never-published draft is discarded
    Given ann created the blank GameSystem "my-system" named "My system"
    When ann discards the GameSystem "my-system"
    Then ann has no GameSystems

  Scenario: A draft with releases cannot be discarded
    Given ann created the blank GameSystem "my-system" named "My system"
    And ann's draft "my-system" was published
    When ann discards the GameSystem "my-system"
    Then the GameSystem is refused because it has releases

  Scenario: Another game manager cannot see the draft
    Given ann created the blank GameSystem "my-system" named "My system"
    And the game manager "bob"
    Then bob cannot find the GameSystem "my-system"
    And bob has no GameSystems
