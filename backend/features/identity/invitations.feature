Feature: Invitations
  In order to let people in without public registration
  As the owner
  I want to invite an email to create an account with the roles I choose

  Background:
    Given an owner exists with email "owner@example.com"

  Scenario: Inviting sends one email with the link to accept
    When "owner@example.com" invites "ada@example.com" as "GAME_MANAGER" and "SOLO_PLAYER"
    Then one invitation email is sent to "ada@example.com"
    And the last invitation email links to "http://localhost:8080/accept-invitation#token=token-1"
    And the pending invitation for "ada@example.com" has roles "GAME_MANAGER" and "SOLO_PLAYER"

  Scenario: An email that already has an account is not invited
    Given a user exists with email "ada@example.com"
    When "owner@example.com" invites "Ada@Example.com" as "SOLO_PLAYER"
    Then the invitation is rejected because the email is already in use
    And no invitation email is sent

  Scenario: An email with a pending invitation is not invited again
    Given "owner@example.com" has invited "ada@example.com" as "SOLO_PLAYER"
    When "owner@example.com" invites "ada@example.com" as "OWNER"
    Then the invitation is rejected because the email already has a pending invitation
    And one invitation email is sent to "ada@example.com"
    And the pending invitation for "ada@example.com" has roles "SOLO_PLAYER"

  Scenario: An expired pending invitation is replaced
    Given "owner@example.com" has invited "ada@example.com" as "SOLO_PLAYER"
    And 8 days pass
    When "owner@example.com" invites "ada@example.com" as "GAME_MANAGER"
    Then there is 1 pending and 1 revoked invitation
    And 2 invitation emails are sent
    And the last invitation email links to "http://localhost:8080/accept-invitation#token=token-2"
    And the pending invitation for "ada@example.com" has roles "GAME_MANAGER"

  Scenario: Roles must be known
    When "owner@example.com" invites "ada@example.com" as "DUNGEON_MASTER"
    Then the invitation is rejected because the role is unknown
    And no invitation email is sent

  Scenario: An invitation gives at least one role
    When "owner@example.com" invites "ada@example.com" as no role
    Then the invitation is rejected because it has no role
    And no invitation email is sent
    And there are 0 pending and 0 revoked invitations
