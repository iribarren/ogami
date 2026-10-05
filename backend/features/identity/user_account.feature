Feature: User account
  In order to give each person the right product areas
  As the owner
  I want every user to have a unique email and at least one known role

  Scenario: Creating a user with roles
    When a user is created with email "ada@example.com" and roles "SOLO_PLAYER" and "GAME_MANAGER"
    Then the user "ada@example.com" exists with roles "SOLO_PLAYER" and "GAME_MANAGER"

  Scenario: The email is unique whatever its case
    Given a user exists with email "ada@example.com"
    When a user is created with email "Ada@Example.com" and role "OWNER"
    Then the user is rejected because the email is already in use
    And there is 1 user

  Scenario: A user needs at least one role
    When a user is created with email "ada@example.com" and no role
    Then the user is rejected because it has no role
    And there are 0 users

  Scenario: Only known roles are accepted
    When a user is created with email "ada@example.com" and role "DUNGEON_MASTER"
    Then the user is rejected because the role is unknown
    And there are 0 users
