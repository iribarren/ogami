Feature: Password reset
  In order to get back into my account without help
  As a user who forgot the password
  I want a link by email to choose a new one, and the owner can send it too

  Background:
    Given the account "ada@example.com" has the password "old password"

  Scenario: Requesting a reset sends one email with the link
    When a password reset is requested for "ada@example.com"
    Then one password reset email is sent to "ada@example.com"
    And the last password reset email links to "http://localhost:8080/reset-password#token=token-1"
    And the last password reset email expires at "2026-10-18T10:00:00+00:00"

  Scenario: The email is matched whatever its case
    When a password reset is requested for "ADA@Example.com"
    Then one password reset email is sent to "ada@example.com"

  Scenario: Requesting a reset for an unknown account does nothing
    When a password reset is requested for "nobody@example.com"
    Then the request is accepted
    And no password reset email is sent
    And there are 0 stored password resets

  Scenario: Requesting a reset for a disabled account does nothing
    Given the account "bob@example.com" is disabled
    When a password reset is requested for "bob@example.com"
    Then the request is accepted
    And no password reset email is sent
    And there are 0 stored password resets

  Scenario: A malformed email is refused
    When a password reset is requested for "not an email"
    Then the request is rejected because the email is invalid
    And no password reset email is sent

  Scenario: A second request replaces the first link
    Given a password reset is requested for "ada@example.com"
    When a password reset is requested for "ada@example.com"
    Then there are 1 stored password resets
    When the password is reset with the token "token-1" to "new password"
    Then the reset link is rejected as invalid
    When the password is reset with the token "token-2" to "new password"
    Then the password of "ada@example.com" is "new password"

  Scenario: The owner sends a reset to a user
    When the owner sends a password reset to "ada@example.com"
    Then one password reset email is sent to "ada@example.com"

  Scenario: The owner cannot send a reset to an unknown or disabled user
    Given the account "bob@example.com" is disabled
    When the owner sends a password reset to an unknown user
    Then the request is rejected because the user is not found
    When the owner sends a password reset to "bob@example.com"
    Then the request is rejected because the user is disabled
    And no password reset email is sent
    And there are 0 stored password resets

  Scenario: A reset id cannot be used twice
    Given a password reset is requested for "ada@example.com"
    When a password reset is requested again with the same id for "ada@example.com"
    Then the request is rejected because the reset id is in use
    And one password reset email is sent to "ada@example.com"

  Scenario: A reused reset id is refused the same for an unknown email
    Given a password reset is requested for "ada@example.com"
    When a password reset is requested again with the same id for "nobody@example.com"
    Then the request is rejected because the reset id is in use

  Scenario: Following the link changes the password
    Given a password reset is requested for "ada@example.com"
    When the link sent to "ada@example.com" is used to choose "new password"
    Then the password of "ada@example.com" is "new password"
    And the account "ada@example.com" is not left with the password "old password"

  Scenario: A link works only once
    Given a password reset is requested for "ada@example.com"
    And the link sent to "ada@example.com" is used to choose "new password"
    When the link sent to "ada@example.com" is used to choose "another password"
    Then the reset link is rejected as invalid
    And the password of "ada@example.com" is "new password"

  Scenario: A link expires exactly after one hour
    Given a password reset is requested for "ada@example.com"
    And 59 minutes and 59 seconds pass
    When the link sent to "ada@example.com" is used to choose "new password"
    Then the password of "ada@example.com" is "new password"

  Scenario: An expired link is invalid
    Given a password reset is requested for "ada@example.com"
    And 60 minutes pass
    When the link sent to "ada@example.com" is used to choose "new password"
    Then the reset link is rejected as invalid
    And the password of "ada@example.com" is "old password"

  Scenario Outline: A blank or unknown link is invalid
    Given a password reset is requested for "ada@example.com"
    When the password is reset with the token "<token>" to "new password"
    Then the reset link is rejected as invalid

    Examples:
      | token   |
      | unknown |
      |         |

  Scenario: A weak password is refused and the link stays usable
    Given a password reset is requested for "ada@example.com"
    When the link sent to "ada@example.com" is used to choose "short"
    Then the reset is rejected because the password is weak
    And the password of "ada@example.com" is "old password"
    When the link sent to "ada@example.com" is used to choose "long enough password"
    Then the password of "ada@example.com" is "long enough password"

  Scenario: A link of a user disabled meanwhile is refused
    Given a password reset is requested for "ada@example.com"
    And the account "ada@example.com" gets disabled
    When the link sent to "ada@example.com" is used to choose "new password"
    Then the reset link is rejected as invalid
    And the password of "ada@example.com" is "old password"
