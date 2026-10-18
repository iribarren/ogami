Feature: Upgrade a campaign to a newer release of its GameSystem
  In order to get the fixes and new content a game manager publishes
  As a solo player
  I want to preview what a release upgrade changes and confirm it before anything changes

  Background:
    Given release version 1 of the GameSystem "heist" named "Heist" is published with a character sheet
    And I created the campaign "The job" with the GameSystem "heist"
    And I added the Character "Vi" with Reflexes 9

  Scenario: A campaign on the latest release has nothing to upgrade
    When I preview the release upgrade
    Then the preview says the campaign is up to date

  Scenario: The preview lists what the upgrade changes and changes nothing
    Given release version 2 of the GameSystem "heist" named "Heist, revised" is published with Reflexes up to 5
    When I preview the release upgrade
    Then the preview upgrades release 1 to release 2 and changes:
      | character | field    | kind    | before | after |
      | Vi        | Reflexes | clamped | 9      | 5     |
    And my campaign "The job" is pinned to version 1 of "heist" named "Heist"

  Scenario: Confirming the previewed upgrade applies exactly that change
    Given release version 2 of the GameSystem "heist" named "Heist, revised" is published with Reflexes up to 5
    And I previewed the release upgrade
    When I confirm the previewed release upgrade
    Then my campaign "The job" is pinned to version 2 of "heist" named "Heist, revised"
    And my Characters are:
      | name | reflexes | hit points |
      | Vi   | 5        | 40/40      |

  Scenario: An upgrade confirmed after the campaign changed is refused and changes nothing
    Given release version 2 of the GameSystem "heist" named "Heist, revised" is published with Reflexes up to 5
    And I previewed the release upgrade
    And I edit the Character "Vi" to have Reflexes 3 and 40 hit points
    When I confirm the previewed release upgrade
    Then I am told that the upgrade changed since the preview
    And my campaign "The job" is pinned to version 1 of "heist" named "Heist"
    And my Characters are:
      | name | reflexes | hit points |
      | Vi   | 3        | 40/40      |

  Scenario: Another player cannot preview my campaign's upgrade
    When another player previews the release upgrade of my campaign
    Then the campaign is not found
