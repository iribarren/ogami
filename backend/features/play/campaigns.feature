Feature: Play a campaign in sessions and scenes
  In order to play solo on a GameSystem that does not change under my feet
  As a solo player
  I want my campaign pinned to a GameSystem release and my play organized in sessions and scenes

  Background:
    Given release version 1 of the GameSystem "free-journal" named "Free journal" is published

  Scenario: A new campaign is pinned to the latest release of its GameSystem
    Given release version 2 of the GameSystem "free-journal" named "Free journal, revised" is published
    When I create the campaign "The lost mine" with the GameSystem "free-journal"
    Then my campaign "The lost mine" is pinned to version 2 of "free-journal" named "Free journal, revised"
    And my campaign has no session

  Scenario: A campaign stays pinned when a newer release is published
    Given I created the campaign "The lost mine" with the GameSystem "free-journal"
    When release version 2 of the GameSystem "free-journal" named "Free journal, revised" is published
    Then my campaign "The lost mine" is pinned to version 1 of "free-journal" named "Free journal"

  Scenario: A campaign cannot be created with an unknown GameSystem
    When I try to create the campaign "The lost mine" with the GameSystem "unknown"
    Then I am told that the GameSystem has no published release
    And I have no campaigns

  Scenario: Sessions and scenes are numbered from 1
    Given I created the campaign "The lost mine" with the GameSystem "free-journal"
    When I start a session
    And I start the scene "At the gate"
    And I start the scene "In the mine"
    And I start a session
    And I start the scene "Back at camp"
    Then my campaign has 2 sessions
    And session 1 has the scenes "At the gate, In the mine"
    And session 2 has the scenes "Back at camp"
    And the current session is 2 and the current scene is 1

  Scenario: A new session has no current scene
    Given I created the campaign "The lost mine" with the GameSystem "free-journal"
    And I started a session
    And I started the scene "At the gate"
    When I start a session
    Then the current session is 2 and there is no current scene

  Scenario: A scene needs a session
    Given I created the campaign "The lost mine" with the GameSystem "free-journal"
    When I try to start the scene "At the gate"
    Then I am told to start a session first
    And my campaign has no session

  Scenario: Another player cannot see or change my campaign
    Given I created the campaign "The lost mine" with the GameSystem "free-journal"
    When another player looks for my campaign
    Then the campaign is not found
    And another player has no campaigns
    When another player tries to start a session in my campaign
    Then the campaign is not found
    And my campaign has no session
