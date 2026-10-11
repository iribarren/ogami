Feature: Keep a campaign's NPCs
  In order to remember the people in my story
  As a solo player
  I want to add NPCs with the Tags of my GameSystem release, edit them, and set them aside and bring them back

  Background:
    Given release version 1 of the GameSystem "heist" named "Heist" is published with Tags
    And I created the campaign "The job" with the GameSystem "heist"

  Scenario: A campaign offers the Tags of its release and I add an NPC with one
    Then my campaign offers the Tags "ally, rival"
    When I add the NPC "Mira" with the Tag "rival" and the free tag "Fence"
    Then my NPCs are:
      | name | agenda | tags  | free tags | weight | status |
      | Mira |        | rival | Fence     | 1      | open   |

  Scenario: An NPC cannot carry a Tag its release does not define
    When I try to add the NPC "Mira" with the Tag "villain"
    Then I am told that the release has no such Tag
    And my NPCs are:
      | name | agenda | tags | free tags | weight | status |

  Scenario: I edit an NPC, close it and reopen it
    Given I added the NPC "Mira" with the Tag "rival" and the free tag "Fence"
    And I added the NPC "Brann" with the Tag "ally" and the free tag "Smith"
    When I rename the NPC "Mira" to "Mira Vane" with the agenda "Wants the ledger" and the weight 3
    And I close the NPC "Mira Vane"
    Then my NPCs are:
      | name      | agenda           | tags  | free tags | weight | status |
      | Brann     |                  | ally  | Smith     | 1      | open   |
      | Mira Vane | Wants the ledger | rival | Fence     | 3      | closed |
    When I reopen the NPC "Mira Vane"
    Then my NPCs are:
      | name      | agenda           | tags  | free tags | weight | status |
      | Mira Vane | Wants the ledger | rival | Fence     | 3      | open   |
      | Brann     |                  | ally  | Smith     | 1      | open   |
