Feature: Keep a campaign's facts
  In order to remember what is true in my story beside the journal
  As a solo player
  I want to add free facts, link them to NPCs and other facts, and edit them

  Background:
    Given release version 1 of the GameSystem "heist" named "Heist" is published with Tags
    And I created the campaign "The job" with the GameSystem "heist"
    And I added the NPC "Mira" with the Tag "rival" and the free tag "Fence"

  Scenario: I add a free fact linked to an NPC
    When I add the free fact "Guild" with the text "Owes a debt" linked to the NPC "Mira"
    Then my facts are:
      | label | text        | links |
      | Guild | Owes a debt | Mira  |

  Scenario: I edit the text of a free fact and its links stay
    Given I added the free fact "Guild" with the text "Owes a debt"
    And I link the fact "Guild" to the NPC "Mira"
    When I change the text of the fact "Guild" to "Owes two debts"
    Then my facts are:
      | label | text           | links |
      | Guild | Owes two debts | Mira  |

  Scenario: A fact cannot link to the same NPC twice
    Given I added the free fact "Guild" with the text "Owes a debt"
    And I link the fact "Guild" to the NPC "Mira"
    When I try to link the fact "Guild" to the NPC "Mira"
    Then I am told that the fact links to the same NPC twice
    And my facts are:
      | label | text        | links |
      | Guild | Owes a debt | Mira  |
