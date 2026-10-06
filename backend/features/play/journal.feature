Feature: Keep the journal of a campaign
  In order to remember what happened in my solo play
  As a solo player
  I want my notes, rolls and oracle results recorded in the current scene, in order

  Background:
    Given release version 1 of the GameSystem "free-journal" named "Free journal" is published

  Scenario: Notes, rolls and oracle results are recorded in the current scene, in order
    Given release version 2 of the GameSystem "free-journal" named "Free journal, with oracles" is published with oracles
    And I created the campaign "The lost mine" with the GameSystem "free-journal"
    And I started a session
    And I started the scene "At the gate"
    When I write the note "The gate is shut."
    And I roll "2d6+1" and the dice show "4, 5"
    And I start the scene "In the mine"
    And I roll on the oracle table "weather" and the dice show "5, 2"
    And I ask the oracle "fate" "Is the mine flooded?" as "unlikely" with chaos factor 7 and the dice show "40"
    Then my journal holds, in order:
      | session | scene | kind         | summary                                           |
      | 1       | 1     | note         | The gate is shut.                                 |
      | 1       | 1     | roll         | 2d6+1 = 10                                        |
      | 1       | 2     | oracle-table | Weather: Storm, Hail                              |
      | 1       | 2     | likelihood   | Fate question: Is the mine flooded? Unlikely, chaos 7: yes (40 vs 45) |

  Scenario: An entry needs a scene
    Given I created the campaign "The lost mine" with the GameSystem "free-journal"
    And I started a session
    When I try to write the note "The gate is shut."
    Then I am told to start a scene first
    And my journal is empty

  Scenario: An oracle that is not in the pinned release records nothing
    Given I created the campaign "The lost mine" with the GameSystem "free-journal"
    And I started a session
    And I started the scene "At the gate"
    And release version 2 of the GameSystem "free-journal" named "Free journal, with oracles" is published with oracles
    When I try to roll on the oracle table "weather" and the dice show "5, 2"
    Then I am told that the GameSystem has no such oracle
    And my journal is empty
