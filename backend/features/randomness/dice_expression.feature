Feature: Dice expression
  In order to resolve checks and oracles with uncertain outcomes
  As a solo player
  I want a dice expression to roll its dice and total them

  Scenario: Rolling dice with a modifier
    Given the dice will roll 3 and 5
    When I roll "2d6+1"
    Then the "2d6" dice show 3 and 5
    And the total is 9

  Scenario: Rolling a single die
    Given the dice will roll 17
    When I roll "d20"
    Then the "1d20" dice show 17
    And the total is 17

  Scenario: Keeping the highest dice
    Given the dice will roll 3, 5, 2 and 6
    When I roll "4d6kh3"
    Then the "4d6kh3" dice show 3, 5, 2 and 6
    And the die showing 2 is dropped
    And the total is 14

  Scenario: Adding and subtracting two groups of dice
    Given the dice will roll 4, 6 and 3
    When I roll "2d6 + 1d4 - 2"
    Then the "2d6" dice show 4 and 6
    And the "1d4" dice show 3
    And no dice are dropped
    And the total is 11

  Scenario: Multiplying a parenthesized expression
    Given the dice will roll 4
    When I roll "(1d6+2)*3"
    Then the total is 18

  Scenario Outline: Rejecting an invalid dice expression
    When I roll "<notation>"
    Then the dice expression is rejected because "<reason>"

    Examples:
      | notation | reason                                          |
      | 2d       | Unexpected end of the dice expression           |
      | 4d6kh5   | keeps between 1 and 4 dice, 5 given             |
      | 1d1001   | A die has between 2 and 1000 sides, 1001 given  |
