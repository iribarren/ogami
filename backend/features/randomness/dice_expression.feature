Feature: Dice expression
  In order to resolve checks and oracles with uncertain outcomes
  As a solo player
  I want a dice expression to roll its dice and total them

  Scenario: Rolling dice with a modifier
    Given the dice will roll 3 and 5
    When I roll "2d6+1"
    Then the dice show 3 and 5
    And the total is 9

  Scenario: Rolling a single die
    Given the dice will roll 17
    When I roll "d20"
    Then the dice show 17
    And the total is 17

  Scenario: Rolling dice with a negative modifier
    Given the dice will roll 1, 4 and 8
    When I roll "3d8-2"
    Then the dice show 1, 4 and 8
    And the total is 11

  Scenario: Rejecting an invalid dice expression
    When I roll "2d"
    Then the dice expression is rejected
