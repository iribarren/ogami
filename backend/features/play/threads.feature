Feature: Keep a campaign's Threads
  In order to follow the story lines of my campaign
  As a solo player
  I want to add Threads with the Tags of my GameSystem release, edit them, and set them aside and bring them back

  Background:
    Given release version 1 of the GameSystem "heist" named "Heist" is published with Tags
    And I created the campaign "The job" with the GameSystem "heist"

  Scenario: I add a Thread with a Tag of the release
    When I add the Thread "Rob the vault" with the Tag "rival" and the free tag "Vault"
    Then my Threads are:
      | name          | tags  | free tags | weight | status |
      | Rob the vault | rival | Vault     | 1      | open   |

  Scenario: A Thread cannot carry a Tag its release does not define
    When I try to add the Thread "Rob the vault" with the Tag "villain"
    Then I am told that the release has no such Tag
    And my Threads are:
      | name | tags | free tags | weight | status |

  Scenario: I edit a Thread, close it and reopen it
    Given I added the Thread "Rob the vault" with the Tag "rival" and the free tag "Vault"
    And I added the Thread "Find the ledger" with the Tag "ally" and the free tag "Docks"
    When I rename the Thread "Rob the vault" to "Rob the bank" with the weight 3
    And I close the Thread "Rob the bank"
    Then my Threads are:
      | name            | tags  | free tags | weight | status |
      | Find the ledger | ally  | Docks     | 1      | open   |
      | Rob the bank    | rival | Vault     | 3      | closed |
    When I reopen the Thread "Rob the bank"
    Then my Threads are:
      | name            | tags  | free tags | weight | status |
      | Rob the bank    | rival | Vault     | 3      | open   |
      | Find the ledger | ally  | Docks     | 1      | open   |
