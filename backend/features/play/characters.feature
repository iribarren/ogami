Feature: Keep a campaign's Characters
  In order to play my own character
  As a solo player
  I want to create and edit Characters from the sheet of my GameSystem release

  Background:
    Given release version 1 of the GameSystem "heist" named "Heist" is published with a character sheet
    And I created the campaign "The job" with the GameSystem "heist"

  Scenario: A campaign offers the sheet of its release and a Character starts from the field defaults
    Then my campaign offers the sheet fields "reflexes, hp"
    When I add the Character "Vi" without values
    Then my Characters are:
      | name | reflexes | hit points |
      | Vi   | 4        | 40/40      |

  Scenario: I create a Character with a value and edit it, which resets what I leave out
    Given I added the Character "Vi" with Reflexes 7
    When I edit the Character "Vi" to have Reflexes 9 and 12 hit points
    Then my Characters are:
      | name | reflexes | hit points |
      | Vi   | 9        | 12/40      |
    When I add the Character "Mira" without values
    Then my Characters are:
      | name | reflexes | hit points |
      | Vi   | 9        | 12/40      |
      | Mira | 4        | 40/40      |

  Scenario: A value out of the bounds of its field changes nothing
    Given I added the Character "Vi" with Reflexes 7
    When I edit the Character "Vi" to have Reflexes 11 and 12 hit points
    Then I am told that the value breaks the sheet
    And my Characters are:
      | name | reflexes | hit points |
      | Vi   | 7        | 40/40      |

  Scenario: I promote an NPC to a Character, which closes the NPC
    Given I added the NPC "Mira" with the Tag "ally" and the free tag "Fence"
    When I promote the NPC "Mira" to a Character
    Then my Characters are:
      | name | reflexes | hit points |
      | Mira | 4        | 40/40      |
    And the NPC "Mira" is closed and became the Character "Mira"
