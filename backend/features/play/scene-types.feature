Feature: Play scenes of a Scene Type
  In order to frame my scenes with the kinds of scene my GameSystem offers
  As a solo player
  I want to start a scene with a Scene Type, named after it, and switch its type when the story turns

  Background:
    Given release version 1 of the GameSystem "heist" named "Heist" is published with Scene Types
    And I created the campaign "The job" with the GameSystem "heist"
    And I started a session

  Scenario: A scene with a Scene Type is named after it, and its type switches by hand
    When I start a scene of the Scene Type "legwork"
    And I start the scene "Casing the bank" of the Scene Type "legwork"
    And I start a scene of the Scene Type "legwork"
    Then session 1 has the scenes "Legwork 1, Casing the bank, Legwork 3"
    When I switch the current scene to the Scene Type "firefight"
    Then the current scene is "Legwork 3" of the Scene Type "Firefight"
