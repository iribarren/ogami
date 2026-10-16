Feature: GameSystem draft narrative items
  In order to author the narrative of my GameSystem before publishing it
  As a game manager
  I want to create, change and delete the trackers, tags, fact slots, Scene Types and flows of my draft

  Background:
    Given the game manager "ann"
    And a published GameSystem release "heist" from the fixture "valid/v2-every-part"
    And ann created the GameSystem "my-heist" named "My heist" as a copy of "heist"

  Scenario: The narrative items are listed with where they are used
    Then ann sees the trackers "alarm, edge, chaos, heat" and the tags "" in "my-heist"
    And the tracker "chaos" of "my-heist" is used at "flows[0].trackers"

  Scenario: A tracker is created
    When ann saves the tracker "noise" named "Noise" in "my-heist"
    Then ann sees the trackers "alarm, edge, chaos, heat, noise" and the tags "" in "my-heist"
    And ann's draft "my-heist" is valid

  Scenario: A tag is created and renamed
    Given ann saved the tag "gang" labelled "Gang" in "my-heist"
    When ann renames the tag "gang" of "my-heist" to "crew"
    Then ann sees the trackers "alarm, edge, chaos, heat" and the tags "crew" in "my-heist"

  Scenario: A tracker that the draft references cannot be deleted
    When ann deletes the tracker "chaos" of "my-heist"
    Then the narrative change is refused because it is in use at "flows[0].trackers"
    And ann sees the trackers "alarm, edge, chaos, heat" and the tags "" in "my-heist"

  Scenario: An invalid tracker is refused and changes nothing
    When ann saves a counter "noise" from 9 to 1 in "my-heist"
    Then the narrative change is refused at "trackers[4].max"
    And ann sees the trackers "alarm, edge, chaos, heat" and the tags "" in "my-heist"

  Scenario: A change made on a stale version is refused
    When ann saves the tag "gang" labelled "Gang" in "my-heist" on a stale version
    Then the narrative change is refused because the draft changed meanwhile
    And ann sees the trackers "alarm, edge, chaos, heat" and the tags "" in "my-heist"

  Scenario: A schema version 1 draft has no narrative sections
    Given a published GameSystem release "journal" from the fixture "valid/contract-doc-example"
    And ann created the GameSystem "my-journal" named "My journal" as a copy of "journal"
    When ann saves the tag "gang" labelled "Gang" in "my-journal"
    Then the narrative change is refused at "schemaVersion"

  Scenario: Another game manager cannot change the narrative
    When bob saves the tag "gang" labelled "Gang" in "my-heist"
    Then the GameSystem is not found
