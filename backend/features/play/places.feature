Feature: Keep a campaign's Places
  In order to keep track of the sites of my campaign
  As a solo player
  I want to add Places with notes, edit them, and set them aside and bring them back

  Background:
    Given release version 1 of the GameSystem "heist" named "Heist" is published with Tags
    And I created the campaign "The job" with the GameSystem "heist"

  Scenario: I add a Place with notes and a free tag
    When I add the Place "Saltmarsh" with the notes "A fishing town" and the free tag "Town"
    Then my Places are:
      | name      | notes          | free tags | weight | status |
      | Saltmarsh | A fishing town | Town      | 1      | open   |

  Scenario: A Place takes a Tag of the release
    When I try to add the Place "Saltmarsh" with the Tag "rival"
    Then my Places are:
      | name      | notes | free tags | weight | status |
      | Saltmarsh |       |           | 1      | open   |

  Scenario: I edit a Place, close it and reopen it
    Given I added the Place "Saltmarsh" with the notes "A fishing town" and the free tag "Town"
    And I added the Place "Dunmere" with the notes "A ruined keep" and the free tag "Ruin"
    When I rename the Place "Saltmarsh" to "Saltmarsh Bay" with the weight 3
    And I close the Place "Saltmarsh Bay"
    Then my Places are:
      | name          | notes          | free tags | weight | status |
      | Dunmere       | A ruined keep  | Ruin      | 1      | open   |
      | Saltmarsh Bay | A fishing town | Town      | 3      | closed |
    When I reopen the Place "Saltmarsh Bay"
    Then my Places are:
      | name          | notes          | free tags | weight | status |
      | Saltmarsh Bay | A fishing town | Town      | 3      | open   |
      | Dunmere       | A ruined keep  | Ruin      | 1      | open   |

  Scenario: Blank notes are no notes
    When I add the Place "Saltmarsh" with the notes "   " and the free tag "Town"
    Then my Places are:
      | name      | notes | free tags | weight | status |
      | Saltmarsh |       | Town      | 1      | open   |
