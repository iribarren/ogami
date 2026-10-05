Feature: Oracle table
  In order to let the dice decide what my character finds, meets or faces
  As a solo player
  I want an oracle table to select an entry at random, following nested tables

  Scenario: Rolling on a ranged oracle table
    Given the oracle table "weather" rolls "1d6":
      | min | max | text  |
      | 1   | 3   | Clear |
      | 4   | 5   | Rain  |
      | 6   | 6   | Storm |
    And the dice will roll 4
    When I consult the oracle table "weather"
    Then the oracle table answers "Rain"

  Scenario: Rolling on a weighted oracle table
    Given the weighted oracle table "storm-kind":
      | weight | text         |
      | 3      | Thunderstorm |
      | 1      | Hail         |
      | 2      | Blizzard     |
    And the dice will roll 4
    When I consult the oracle table "storm-kind"
    Then the oracle table answers "Hail"

  Scenario: Following a nested oracle table
    Given the oracle table "weather" rolls "1d6":
      | min | max | text  | table      |
      | 1   | 5   | Clear |            |
      | 6   | 6   | Storm | storm-kind |
    And the weighted oracle table "storm-kind":
      | weight | text         |
      | 3      | Thunderstorm |
      | 1      | Hail         |
      | 2      | Blizzard     |
    And the dice will roll 6 and 5
    When I consult the oracle table "weather"
    Then the oracle table steps are:
      | table      | dice | total | text     |
      | weather    | 1d6  | 6     | Storm    |
      | storm-kind | 1d6  | 5     | Blizzard |

  Scenario: Rolling a total no entry covers
    Given the oracle table "gaps" rolls "1d6":
      | min | max | text |
      | 1   | 2   | Low  |
      | 5   | 6   | High |
    And the dice will roll 3
    When I consult the oracle table "gaps"
    Then the oracle table is rejected because "Rolled 3 on table "gaps", but no entry covers it."

  Scenario: Rejecting overlapping ranges
    Given the oracle table "weather" rolls "1d6":
      | min | max | text  |
      | 1   | 3   | Clear |
      | 3   | 6   | Rain  |
    When I consult the oracle table "weather"
    Then the oracle table is rejected because "Entries 1 (1 to 3) and 2 (3 to 6) of oracle table "weather" overlap."

  Scenario: Rejecting a nested table that does not exist
    Given the weighted oracle table "mood":
      | text | table   |
      | Calm |         |
      | Odd  | missing |
    When I consult the oracle table "mood"
    Then the oracle table is rejected because "nests "missing", but there is no oracle table "missing""

  Scenario: Rejecting oracle tables that nest in a cycle
    Given the weighted oracle table "a":
      | text | table |
      | B    | b     |
    And the weighted oracle table "b":
      | text | table |
      | A    | a     |
    When I consult the oracle table "a"
    Then the oracle table is rejected because "Oracle tables nest in a cycle: "a" > "b" > "a"."
