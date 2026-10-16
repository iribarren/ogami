Feature: GameSystem draft oracles
  In order to tune the oracles of my GameSystem before publishing it
  As a game manager
  I want to create, change and delete the oracle tables and likelihood oracles of my draft

  Background:
    Given the game manager "ann"
    And a published GameSystem release "heist" from the fixture "valid/v2-every-part"
    And ann created the GameSystem "my-heist" named "My heist" as a copy of "heist"

  Scenario: The oracles are listed with where they are used
    Then ann sees the tables "heist-scenes, complications, escape-routes" and the likelihood oracles "fate" in "my-heist"
    And the table "complications" of "my-heist" is used at "sceneTypes[1].play[1]"

  Scenario: A table is created
    When ann saves the table "rumours" named "Rumours" with the entries "True, False" in "my-heist"
    Then ann sees the tables "heist-scenes, complications, escape-routes, rumours" and the likelihood oracles "fate" in "my-heist"
    And ann's draft "my-heist" is valid

  Scenario: An unreferenced table is renamed and keeps its position
    Given ann saved the table "rumours" named "Rumours" with the entries "True, False" in "my-heist"
    When ann renames the table "rumours" of "my-heist" to "gossip"
    Then ann sees the tables "heist-scenes, complications, escape-routes, gossip" and the likelihood oracles "fate" in "my-heist"

  Scenario: A table that the draft references cannot be renamed
    When ann renames the table "complications" of "my-heist" to "troubles"
    Then the oracle change is refused because it is in use at "sceneTypes[1].play[1]"
    And ann sees the tables "heist-scenes, complications, escape-routes" and the likelihood oracles "fate" in "my-heist"

  Scenario: A table that the draft references cannot be deleted
    When ann deletes the table "heist-scenes" of "my-heist"
    Then the oracle change is refused because it is in use at "flows[0].phases[1].selection"

  Scenario: An invalid table is refused and changes nothing
    When ann saves the table "rumours" named "Rumours" with the entries "" in "my-heist"
    Then the oracle change is refused at "oracles.tables"
    And ann sees the tables "heist-scenes, complications, escape-routes" and the likelihood oracles "fate" in "my-heist"

  Scenario: A key that another oracle uses is taken
    When ann saves the table "fate" named "Fate" with the entries "True" in "my-heist"
    Then the oracle change is refused because the key is taken

  Scenario: A change made on a stale version is refused
    When ann saves the table "rumours" named "Rumours" with the entries "True" in "my-heist" on a stale version
    Then the oracle change is refused because the draft changed meanwhile
    And ann sees the tables "heist-scenes, complications, escape-routes" and the likelihood oracles "fate" in "my-heist"

  Scenario: An unreferenced table is deleted
    Given ann saved the table "rumours" named "Rumours" with the entries "True, False" in "my-heist"
    When ann deletes the table "rumours" of "my-heist"
    Then ann sees the tables "heist-scenes, complications, escape-routes" and the likelihood oracles "fate" in "my-heist"

  Scenario: A likelihood oracle that the draft references cannot be deleted
    When ann deletes the likelihood oracle "fate" of "my-heist"
    Then the oracle change is refused because it is in use at "sceneTypes[3].play[0]"

  Scenario: Another game manager cannot change the oracles
    When bob saves the table "rumours" named "Rumours" with the entries "True" in "my-heist"
    Then the GameSystem is not found
