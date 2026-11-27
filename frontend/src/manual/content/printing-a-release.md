# Printing a Release

A flow that you play on screen can also be played at a table, in the way a board game comes as a box of cards and sheets, and Studio prints it in that form. This chapter goes through the print page and what it puts on paper. How to design cards that play well, and how to draw from a deck, is the subject of [Cards and Print](/manual/cards-and-print), which this chapter does not repeat.

## A print is always of a release

Studio prints one flow of a published release, and never the draft. Because a release stays exactly as it was published, the cards you print match a version that campaigns can play, whereas a print of the draft would describe something no campaign has.

In the **Releases** tab, each release has a **Print** link beside **View**, which opens the print page for that version. If you want your latest edits on paper, publish them first.

## Card images

Cards can carry two images of your own, which you upload in the **Card images** section of the **Overview**. One slot holds the card back, and the other holds the card front, a frame that the text of the card is printed over. Each image is a PNG or a JPEG of at most 5 MB, and SVG and every other format are refused. The server re-encodes the image you upload, so what is stored is a clean picture with no hidden data, which is also why only those two formats are taken.

| Size         | Value                                                                                      |
| ------------ | ------------------------------------------------------------------------------------------ |
| Recommended  | 815 × 1110 px, which is 69 × 94 mm (63 × 88 mm plus 3 mm of bleed on each edge) at 300 dpi |
| Accepted     | From 408 × 555 to 1630 × 2220 px                                                           |
| Aspect ratio | Within 2 % of 69:94                                                                        |

The text of a front sits in a safe area 5 mm inside the edge of the card, so keep the middle of the frame quiet, since whatever you draw there ends up behind the words.

The front frame sits behind every face that carries text, which means both faces of a Scene Type card, every "(cont.)" card and the front of every deck card. The back image is the back of every deck card.

### The images belong to the release

Publishing pins the images the GameSystem has at that moment, so a release always prints with them, even after you replace or remove the images later. That is also why a changed image counts as a change when you publish. A release published without images prints plain cards, and restoring a release into the draft restores its images along with the rest of its content.

## The options

The options sit at the top of the print page, where they stay on the screen and are left off the paper.

| Option               | What it does                                                                                            |
| -------------------- | ------------------------------------------------------------------------------------------------------- |
| Flow                 | The flow to print. Nothing is chosen until you pick one, and until then the page shows only the options |
| Paper                | A4 or A5, for the whole print                                                                           |
| Bleed and crop marks | Prints the cards with bleed, and crop marks at the trim                                                 |
| Extra decks          | A checkbox for each oracle table of the flow that is not already a deck                                 |

The tables that an `oracle` selection of the flow draws from are always printed as decks, and **Extra decks** lets you add any other table of the flow to them.

The options live in the page address, so a link you copy prints the same thing for whoever opens it. When a value in the address is wrong, the page lists it as a problem above the options and prints nothing, and the next option you change writes an address that keeps only the values the page can print.

Once the options are set, the **Print** button opens the browser's print dialog.

When the back of a Scene Type does not fit on one card, it continues on a "(cont.)" card. A note under the options names those Scene Types and says how many cards each one takes, so that you can shorten them if you prefer one card each.

## What prints

The print comes out in the order of this table. A sheet with nothing to show is left out, except the campaign log, which always prints.

| Part                      | What it holds                                                                                                                           |
| ------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| Scene Type cards          | Poker size, 63 × 88 mm. The front has the name, the purpose and the tips, and the back has the setup, play and closing steps            |
| Decks                     | Each entry as copies, by its weight or by the width of its range. A deck over 54 cards prints one card per entry, with its range        |
| Flow sheet                | The name, the description and the introduction, then each Phase with its mode, description, selection and hooks                         |
| Flow diagram              | Scaled to the page. If it is too tall, one diagram per Phase, each on its own page                                                      |
| Oracle tables             | Each oracle table of the flow with its die, and each entry with its numbers, its text and its effects in words                          |
| Checks reference          | The Checks, each with its hint, dice and outcome bands, and the flow's likelihood oracles with their levels                             |
| Trackers and clocks sheet | Clocks as segments to shade, and counters as boxes from minimum to maximum with their level labels, or as a write-in box above 20 boxes |
| Campaign log              | The Fact slots, the Tags, then ruled lines                                                                                              |

The wording of each step on a card comes from the release. A Check step prints its outcomes from their bands, and any other step prints its title and its prompt.

## Layout

Cards butt against each other on shared cut lines, so a single cut separates two cards. With **Bleed and crop marks**, each card grows to 69 × 94 mm and fewer of them fit on a sheet.

| Cards                                 | A4    | A5    |
| ------------------------------------- | ----- | ----- |
| 63 × 88 mm, on shared cut lines       | 3 × 3 | 2 × 2 |
| 69 × 94 mm, with bleed and crop marks | 2 × 3 | 2 × 2 |

The backs are mirrored for double-sided printing on the long edge, which puts each back behind its own front when the sheet turns over.

## Printing it

The browser's print dialog decides how the page reaches the paper, and a short note on the print page names the paper, the bleed, the margins and the background graphics. In the dialog, set the following.

- **Destination:** "Save as PDF", or a printer.
- **Paper size:** the one you picked under **Paper**.
- **Margins:** "None".
- **Background graphics:** on.
- **Double-sided:** on, flipping on the long edge.

Two things are still to come: fonts, colours and themes of your own for the print, and printing from Play.

## Try it

Print your flow as a PDF on A5 and read the note under the options. If a Scene Type takes two cards, shorten its steps in the draft, publish, and print the new version to check that it now fits on one.
