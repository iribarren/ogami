Feature: Play a campaign guided by its Flow
  In order to be led through a scene instead of facing a blank page
  As a solo player
  I want the Flow to name my next step, so that I pick a Scene Type, answer or skip its steps and end the scene

  Background:
    Given the guided release of the GameSystem "guided" is published

  Scenario: Picking a Scene Type starts its scene at its first step, and answering moves on and journals the answer
    Given I created the campaign "The tour" with the GameSystem "guided" playing the Flow "tour"
    And I started a session
    Then the FlowRun waits at the scene pick
    When I pick the Scene Type "tour"
    Then the FlowRun waits at the step "intro"
    When I answer the step "intro" with "Ada, a fixer"
    Then the FlowRun waits at the step "dice"
    And my journal holds, in order:
      | session | scene | kind | summary      |
      | 1       | 1     | note | Ada, a fixer |
    When I skip the step "dice"
    Then the FlowRun waits at the step "omen"
    And my journal holds, in order:
      | session | scene | kind | summary      |
      | 1       | 1     | note | Ada, a fixer |

  Scenario: A mandatory step cannot be skipped, and ending the scene leads to the next one
    Given I created the campaign "The chain" with the GameSystem "guided" playing the Flow "chain"
    And I started a session
    When I end the guided scene 1
    Then the FlowRun waits at the step "wrap"
    When I try to skip the step "wrap"
    Then I am told the step is mandatory
    And the FlowRun waits at the step "wrap"
    When I answer the step "wrap" with "The crew split up"
    Then the current session is 1 and the current scene is 2

  Scenario: Paused guidance refuses the commands until it is resumed
    Given I created the campaign "The tour" with the GameSystem "guided" playing the Flow "tour"
    And I started a session
    When I pause the guidance
    Then the guidance is "paused"
    When I try to pick the Scene Type "tour"
    Then I am told the guidance is paused
    When I resume the guidance
    Then the guidance is "active"
    When I pick the Scene Type "tour"
    Then the FlowRun waits at the step "intro"
