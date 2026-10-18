Feature: Invitation lifecycle
  In order to keep invitations under control
  As the owner
  I want to list, resend and revoke invitations, and let the invited person accept one

  Background:
    Given an owner exists with email "owner@example.com"
    And "owner@example.com" has invited "ada@example.com" as "GAME_MANAGER"

  Scenario: Listing pending invitations by email with an expiry flag
    Given 8 days pass
    And "owner@example.com" has invited "zoe@example.com" as "SOLO_PLAYER"
    Then the invitations listed are "ada@example.com" as "expired" and "zoe@example.com" as "valid"

  Scenario: Only pending invitations are listed
    Given the invitation of "ada@example.com" is revoked
    And "owner@example.com" has invited "zoe@example.com" as "SOLO_PLAYER"
    And "zoe@example.com" accepts the invitation with the password "correct horse"
    Then no invitations are listed

  Scenario: Resending an invitation replaces its link
    When the invitation of "ada@example.com" is resent
    Then 2 invitation emails are sent
    And the last invitation email links to "http://localhost:8080/accept-invitation#token=token-2"
    When the token "token-1" is looked up
    Then the link is rejected as invalid
    When the token "token-2" is looked up
    Then the link is for "ada@example.com" with roles "GAME_MANAGER" until "2026-10-25T09:00:00+00:00"

  Scenario: Resending renews an expired invitation
    Given 8 days pass
    When the invitation of "ada@example.com" is resent
    Then the invitation of "ada@example.com" is listed as "valid"
    And the link is for "ada@example.com" with roles "GAME_MANAGER" until "2026-11-02T09:00:00+00:00"

  Scenario: Only a pending invitation can be resent or revoked
    Given the invitation of "ada@example.com" is revoked
    When the invitation of "ada@example.com" is resent
    Then the invitation is rejected because it is not found
    When the invitation of "ada@example.com" is revoked
    Then the invitation is rejected because it is not found
    When an unknown invitation is resent
    Then the invitation is rejected because it is not found
    When an unknown invitation is revoked
    Then the invitation is rejected because it is not found
    And 1 invitation email is sent

  Scenario: Revoking an invitation stops its link
    When the invitation of "ada@example.com" is revoked
    Then there are 0 pending and 1 revoked invitations
    When "ada@example.com" accepts the invitation with the password "correct horse"
    Then the link is rejected as invalid
    And there is 1 user

  Scenario: Accepting an invitation creates the account with the invited roles
    When "ada@example.com" accepts the invitation with the password "correct horse"
    Then the user "ada@example.com" exists with roles "GAME_MANAGER"
    And the user "ada@example.com" is "active"
    And the user "ada@example.com" has the password "correct horse"
    And there are 0 pending and 0 revoked invitations

  Scenario: An invitation can be accepted only once
    Given "ada@example.com" accepts the invitation with the password "correct horse"
    When "ada@example.com" accepts the invitation with the password "another horse"
    Then the link is rejected as invalid
    And the user "ada@example.com" has the password "correct horse"

  Scenario Outline: The password has between 8 and 4,096 characters
    When "ada@example.com" accepts the invitation with a password of <length> characters
    Then <outcome>

    Examples:
      | length | outcome                                                  |
      | 7      | the invitation is rejected because the password is weak  |
      | 8      | the invitation is accepted                               |
      | 4096   | the invitation is accepted                               |
      | 4097   | the invitation is rejected because the password is weak  |

  Scenario: A weak password leaves the invitation pending
    When "ada@example.com" accepts the invitation with the password "short"
    Then the invitation is rejected because the password is weak
    And there are 1 pending and 0 revoked invitations
    And there are 1 user

  Scenario: The password is checked before the link
    When someone accepts with the token "unknown" and a password of 3 characters
    Then the invitation is rejected because the password is weak

  Scenario Outline: A link that is unknown, blank or expired is invalid
    Given <time>
    When someone accepts with the token "<token>" and a password of 8 characters
    Then the link is rejected as invalid

    Examples:
      | time           | token   |
      | 1 days pass    | unknown |
      | 1 days pass    |         |
      | 8 days pass    | token-1 |

  Scenario: An expired invitation cannot be accepted
    Given 8 days pass
    When "ada@example.com" accepts the invitation with the password "correct horse"
    Then the link is rejected as invalid
    And there is 1 user

  Scenario: An email that got an account meanwhile cannot accept
    Given a user exists with email "ada@example.com"
    When "ada@example.com" accepts the invitation with the password "correct horse"
    Then the invitation is rejected because the email is already in use
    And there are 1 pending and 0 revoked invitations
    And there are 2 users

  Scenario: Looking up a link tells who is invited, with which roles and until when
    When the link of "ada@example.com" is looked up
    Then the link is for "ada@example.com" with roles "GAME_MANAGER" until "2026-10-25T09:00:00+00:00"

  Scenario Outline: Looking up a link that cannot be used
    Given <time>
    When the token "<token>" is looked up
    Then the link is rejected as invalid

    Examples:
      | time        | token   |
      | 1 days pass | unknown |
      | 1 days pass |         |
      | 8 days pass | token-1 |

  Scenario: Looking up a revoked link
    Given the invitation of "ada@example.com" is revoked
    When the token "token-1" is looked up
    Then the link is rejected as invalid

  Scenario: Looking up an accepted link
    Given "ada@example.com" accepts the invitation with the password "correct horse"
    When the token "token-1" is looked up
    Then the link is rejected as invalid
