Feature: Play a campaign along a Flow, one session at a time
  In order to play my campaign in sittings, guided by the way of playing I chose
  As a solo player
  I want to pick a Flow when I create a campaign and end each session before the next one starts

  Scenario: A campaign plays the Flow chosen, and an ended session lets no scene start until the next one
    Given release version 1 of the GameSystem "heist" named "Heist" is published with Flows
    When I create the campaign "The job" with the GameSystem "heist" playing the Flow "one-shot"
    Then my campaign plays the Flow "one-shot"
    When I start a session
    And I start the scene "Casing the bank"
    And I end the session
    Then session 1 has ended
    When I try to start the scene "Too late"
    Then I am told to start a session first
    When I start a session
    And I start the scene "The vault"
    Then the current session is 2 and the current scene is 1
    And session 1 has the scenes "Casing the bank"
