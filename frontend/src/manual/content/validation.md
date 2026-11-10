# Validation

Validation tells you whether the draft would be accepted as a release, before you try to publish. It runs the same check the publisher runs, on the saved draft.

## The Validation section

Open **Validation** in the Ship group of the rail. The page shows one of two things.

- **The draft is valid: it could be published as a release.**
- **The draft is not valid yet**, with how many errors block publishing and how many warnings do not.

The **Check again** button runs it again. The rail's Validation item carries an error-styled pill with the number of errors once there are any, so you can see a problem without opening the section.

A library pack has the same section. It reads "The pack's items are valid." or "The pack is not valid yet", and counts its errors without talking about publishing, because a pack is never published.

## Errors

An error is something the release cannot hold. Errors are grouped by the place they are in, for example a Flow, a Scene Type or an oracle table, and each group has a count. Each row gives:

- the message,
- where the problem is in words, from the section down to the field (a Flow, then a phase, then a step),
- a **Go to field** button that opens the right editor, on the right step for a Flow.

Under the list, **Technical paths** opens the raw path of every error, such as `flows[0].phases[1].name`, for when you need to report one.

A published release is valid by construction. What the check enforces is the release contract, so most errors are one of:

- a rule of an item, such as a name that is too long or a key that is not lower case letters, digits and hyphens,
- a **reference that points at nothing**: a step that names a table, a Tracker or a Scene Type the draft does not have,
- a piece that **reaches for something it was not given**, such as a Tracker used by a Scene Type that the Flow does not list.

When an editor lets you pick from a list, you can seldom make the second kind. It appears when you delete or rename something that is still in use.

## Warnings

A warning is a rule that is legal but probably a mistake. Warnings are listed under the errors, each with the same place in words and a **Go to field** button. They never block publishing and they are not stored with the release.

The one the release contract defines is a threshold that fires every turn. When a condition on a Tracker forces a Scene Type, and that Scene Type does nothing to lower the Tracker, the Tracker stays over the line and the Scene Type fires again on the next World turn.

Read every warning once. If you meant it, publish.

## What blocks publishing

Only errors block. The Publish dialog runs the same check: when the draft is not valid it says "The draft is not valid yet, so it cannot be published", lists the first three errors with their places, and links to the Validation section for the rest. The Publish button stays disabled. Warnings show in the dialog and do not stop you.

The check reads the **saved** draft. Edits in a form you have not saved are not in it.

Two other things stop a publish and are not validation: publishing a draft that has not changed since the latest release is refused ("nothing changed"), and so is publishing a library pack.

## Working with it

- Validate often. A dozen errors from one deleted table are easier to fix the moment you delete it.
- Fix the first error in a group first. Later ones are often the same cause.
- Use **Go to field** instead of hunting. It opens the step, not the Flow.

## Try it

Make a throwaway copy of a GameSystem. Rename a table that a step uses, then open Validation. Read the error, follow Go to field and put the name back.
