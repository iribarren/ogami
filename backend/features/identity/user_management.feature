Feature: User management
  In order to control who can use the app and what they can do
  As the owner
  I want to list users, change their roles, and disable or enable their accounts

  Background:
    Given an owner exists with email "owner@example.com"
    And a user exists with email "ada@example.com"

  Scenario: Listing users by email with their status
    When "owner@example.com" disables "ada@example.com"
    Then the users listed are "ada@example.com" as "disabled" and "owner@example.com" as "active"

  Scenario: Changing a user's roles
    When "owner@example.com" changes the roles of "ada@example.com" to "GAME_MANAGER" and "SOLO_PLAYER"
    Then the user "ada@example.com" has roles "GAME_MANAGER" and "SOLO_PLAYER"

  Scenario: Roles must be known
    When "owner@example.com" changes the roles of "ada@example.com" to "DUNGEON_MASTER"
    Then the user is rejected because the role is unknown
    And the user "ada@example.com" has roles "SOLO_PLAYER"

  Scenario: A user keeps at least one role
    When "owner@example.com" changes the roles of "ada@example.com" to no role
    Then the user is rejected because it has no role
    And the user "ada@example.com" has roles "SOLO_PLAYER"

  Scenario: The owner cannot drop their own OWNER role
    When "owner@example.com" changes the roles of "owner@example.com" to "GAME_MANAGER"
    Then the change is rejected because the own account is protected
    And the user "owner@example.com" has roles "OWNER"

  Scenario: The owner can change their other roles
    When "owner@example.com" changes the roles of "owner@example.com" to "OWNER" and "GAME_MANAGER"
    Then the user "owner@example.com" has roles "OWNER" and "GAME_MANAGER"

  Scenario: The owner cannot disable their own account
    When "owner@example.com" disables "owner@example.com"
    Then the change is rejected because the own account is protected
    And the user "owner@example.com" is "active"

  Scenario: The only active owner cannot be disabled by someone else
    When "ada@example.com" disables "owner@example.com"
    Then the change is rejected because it would leave no active owner
    And the user "owner@example.com" is "active"

  Scenario: The only active owner cannot lose the OWNER role through someone else
    When "ada@example.com" changes the roles of "owner@example.com" to "GAME_MANAGER"
    Then the change is rejected because it would leave no active owner
    And the user "owner@example.com" has roles "OWNER"

  Scenario: An owner can be disabled while another owner is active
    Given an owner exists with email "second@example.com"
    When "second@example.com" disables "owner@example.com"
    Then the user "owner@example.com" is "disabled"

  Scenario: Disabling and enabling a user
    When "owner@example.com" disables "ada@example.com"
    Then the user "ada@example.com" is "disabled"
    When "owner@example.com" enables "ada@example.com"
    Then the user "ada@example.com" is "active"

  Scenario: Disabling a disabled user changes nothing
    When "owner@example.com" disables "ada@example.com"
    When "owner@example.com" disables "ada@example.com"
    Then the user "ada@example.com" is "disabled"

  Scenario: An unknown user cannot be managed
    When "owner@example.com" disables an unknown user
    Then the change is rejected because the user is not found
    When "owner@example.com" enables an unknown user
    Then the change is rejected because the user is not found
    When "owner@example.com" changes the roles of an unknown user to "OWNER"
    Then the change is rejected because the user is not found
