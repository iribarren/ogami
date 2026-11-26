# Validation

Validation tells you, before you try to publish, whether the draft would be accepted as a release. You can trust its answer because it runs the same check the publisher runs, on the saved draft.

## The Validation section

Open **Validation** in the Ship group of the rail, and the page shows one of two things.

- **The draft is valid: it could be published as a release.**
- **The draft is not valid yet**, with how many errors block publishing and how many warnings do not.

The **Check again** button runs the check once more. Once there are errors, the rail's Validation item carries an error-styled pill with their number, so you can see a problem without opening the section.

A library pack has the same section. Its page reads "The pack's items are valid." or "The pack is not valid yet", and it counts its errors without talking about publishing, because a pack is never published.

## Errors

An error is something the release cannot hold. The errors are grouped by the place they are in, for example a Flow, a Scene Type or an oracle table, and each group has a count. Each row gives the message, then where the problem is in words, from the section down to the field (a Flow, then a phase, then a step), and a **Go to field** button that opens the right editor, on the right step in the case of a Flow.

Under the list, **Technical paths** opens the raw path of every error, such as `flows[0].phases[1].name`, for when you need to report one.

A published release is valid by construction. The check enforces the release contract, which is the set of rules every release has to follow, and Studio keeps a draft within part of that contract all the time. The **item rules** are the rules each piece has to follow on its own, and every editor applies them when you save and saves nothing that breaks them, so a name that is too long, or a key that is not lower case letters, digits and hyphens, is refused at the save and never reaches this list. Studio also refuses to rename or delete anything the draft still uses, and its message says where it is used, which means a **reference that points at nothing** cannot appear either.

What the list shows is **flow coherence**, the rules about how the pieces of a Flow fit together. A Flow is built one piece at a time, and much as a jigsaw has gaps until its last piece is in, these rules cannot hold after every save, so Studio lists what breaks them here and only publishing requires them. A coherence error is one of these:

- a piece that **reaches for something it was not given**, such as a Tracker used by a Scene Type that the Flow does not list among its **Trackers**,
- an oracle shortcut of a Scene Type the Flow reaches that the Flow does not list among its **Oracle shortcuts**,
- a placeholder in a Flow's text that names a Tracker or a step the Flow does not have, or, in a phase that runs once, a step that does not come before it,
- an entry of an oracle selection that names no Scene Type.

## Warnings

A warning is a rule that is legal but probably a mistake. Warnings are listed under the errors, each with the same place in words and a **Go to field** button, and they never block publishing, nor are they stored with the release. Read every warning once, and if you meant it, publish.

The release contract defines one warning, for a threshold that may fire every turn. It comes up when a condition on a Tracker forces a Scene Type that does nothing to lower the Tracker, because the Tracker can then stay over the line and the Scene Type can come back on the next World turn, and the one after.

## What blocks publishing

Only errors block publishing. The Publish dialog runs the same check, and when the draft is not valid it says "The draft is not valid yet, so it cannot be published", lists the first three errors with their places and links to the Validation section for the rest, while the Publish button stays disabled. Warnings show in the dialog too, and they do not stop you.

The check reads the **saved** draft, so edits in a form you have not saved are not in it.

Two other things stop a publish without being validation. Publishing a draft that has not changed since the latest release is refused ("nothing changed"), and so is publishing a library pack.

## Working with it

Validate often, since an error is easier to fix the moment you make it, for instance right after you take a Tracker off a Flow's list. **Go to field** saves you hunting for the place, and it opens the step itself rather than the Flow.

## Try it

Make a throwaway copy of a GameSystem and open one of its Flows. Untick a Tracker in the Flow's **Trackers** list while a Scene Type the Flow reaches still uses it, save the Flow and open Validation. Read the error, follow **Go to field** to the step that uses the Tracker, and then go back to the Flow and tick the Tracker again.
