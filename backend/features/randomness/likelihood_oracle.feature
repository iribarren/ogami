Feature: Likelihood oracle
  In order to answer the yes/no questions my story raises
  As a solo player
  I want a likelihood oracle to answer yes or no, sometimes exceptionally, given how likely it is

  Background:
    Given a likelihood oracle rolls 1d100 with 20% exceptional results and the levels:
      | key      | label    | target |
      | unlikely | Unlikely | 35     |
      | likely   | Likely   | 65     |
    And its chaos factor goes from 1 to 9, neutral at 5, shifting the target 5 per point

  Scenario Outline: Answering at the neutral chaos factor
    Given the dice will roll <roll>
    When I ask the likelihood oracle with the level "likely"
    Then the likelihood oracle answers "<answer>" with a roll of <roll> against a target of 65

    Examples:
      | roll | answer          |
      | 13   | exceptional_yes |
      | 14   | yes             |
      | 65   | yes             |
      | 66   | no              |
      | 93   | no              |
      | 94   | exceptional_no  |

  Scenario: Raising the chaos factor makes yes more likely
    Given the dice will roll 85
    When I ask the likelihood oracle with the level "likely" and the chaos factor 9
    Then the likelihood oracle answers "yes" with a roll of 85 against a target of 85

  Scenario: Lowering the chaos factor makes yes less likely
    Given the dice will roll 46
    When I ask the likelihood oracle with the level "likely" and the chaos factor 1
    Then the likelihood oracle answers "no" with a roll of 46 against a target of 45

  Scenario: Rejecting an unknown likelihood level
    When I ask the likelihood oracle with the level "certain"
    Then the likelihood oracle is rejected because "There is no likelihood level "certain"; the levels are "unlikely", "likely"."

  Scenario: Rejecting a chaos factor out of range
    When I ask the likelihood oracle with the level "likely" and the chaos factor 10
    Then the likelihood oracle is rejected because "The chaos factor is between 1 and 9, 10 given."
