Feature: Publish a GameSystem release
  In order to let solo players play my GameSystem as it was when they started
  As a game manager
  I want each GameSystem release I publish to become a new immutable version

  Background:
    Given a GameSystem release file for the GameSystem "free-journal" named "Free journal"

  Scenario: The first release of a GameSystem is version 1
    When I publish the GameSystem release
    Then the GameSystem "free-journal" has 1 release
    And the latest release of "free-journal" is version 1 named "Free journal"

  Scenario: Publishing again adds the next version and keeps the previous one
    Given I published the GameSystem release
    And the GameSystem is renamed "Free journal, revised" in the release file
    When I publish the GameSystem release
    Then the GameSystem "free-journal" has 2 releases
    And the latest release of "free-journal" is version 2 named "Free journal, revised"
    And release version 1 of "free-journal" is still named "Free journal"

  Scenario: An unchanged GameSystem release is not published again when publishing only if changed
    Given I published the GameSystem release
    When I publish the GameSystem release only if it changed
    Then nothing is published
    And the GameSystem "free-journal" has 1 release

  Scenario: A changed GameSystem release is published when publishing only if changed
    Given I published the GameSystem release
    And the GameSystem is renamed "Free journal, revised" in the release file
    When I publish the GameSystem release only if it changed
    Then the latest release of "free-journal" is version 2 named "Free journal, revised"

  Scenario: An invalid GameSystem release is rejected
    Given the release file has the flow step key "Set Scene"
    When I publish the GameSystem release
    Then the GameSystem release is rejected at "flow.steps[0].key"
    And the GameSystem "free-journal" has 0 releases
