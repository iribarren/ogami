Feature: Continue a campaign in a new one
  In order to let a one-shot grow into a campaign without retyping its world
  As a solo player
  I want a new campaign with another Flow that carries over my NPCs, Threads, facts and chosen Characters

  Background:
    Given release version 1 of the GameSystem "heist" named "Heist" is published with a character sheet, Trackers and Flows
    And I created the campaign "The job" with the GameSystem "heist" playing the Flow "one-shot"
    And I added the Character "Vi" with Reflexes 9
    And I added the Character "Jax" with Reflexes 6
    And I added the NPC "Mira" with the Tag "ally" and the free tag "Fence"
    And I added the Thread "The vault" with the Tag "ally" and the free tag "Gig"
    And I set the Tracker "heat" to 3

  Scenario: The new campaign carries the people, the Threads and the chosen Characters
    When I continue my campaign in "Making a Name" playing the Flow "the-heist" with the Characters "Vi"
    Then my campaign "Making a Name" is pinned to version 1 of "heist" named "Heist"
    And my campaign plays the Flow "the-heist"
    And my Characters are:
      | name | reflexes | hit points |
      | Vi   | 9        | 40/40      |
    And my NPCs are:
      | name | agenda | tags | free tags | weight | status |
      | Mira |        | ally | Fence     | 1      | open   |
    And my Threads are:
      | name      | tags | free tags | weight | status |
      | The vault | ally | Gig       | 1      | open   |
    And my campaign has no session

  Scenario: The Trackers of the new campaign start over and the source campaign is left as it was
    When I continue my campaign in "Making a Name" playing the Flow "the-heist" with the Characters "Vi"
    Then my campaign's Trackers are:
      | tracker | value | level |
      | alarm   | 0     |       |
      | heat    | -5    | Cold  |
      | chaos   | 5     |       |
    And my campaign "The job" still plays the Flow "one-shot" with the Characters "Vi, Jax" and the Tracker "heat" at 3

  Scenario: A campaign on an older release is refused until it is upgraded
    Given release version 2 of the GameSystem "heist" named "Heist, revised" is published with a character sheet, Trackers and Flows
    When I try to continue my campaign in "Making a Name" playing the Flow "the-heist" with the Characters "Vi"
    Then I am told to upgrade the campaign first
    And I have only the campaign "The job"
