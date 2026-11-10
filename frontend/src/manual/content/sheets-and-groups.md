# Sheets and Groups

A **sheet** is the definition of a Character: the fields a player fills in or rolls when making one. Studio's **Sheet** section holds it. Every field has a key, a label, a hint, a group and a type.

## Field types

| Type       | It holds                                                                         | Used for               |
| ---------- | -------------------------------------------------------------------------------- | ---------------------- |
| Number     | A whole number with a minimum, a maximum, a default and an optional dice default | Traits, Wounds, money  |
| Text       | A short string, optionally multiline, with a maximum length                      | A name, a calling      |
| List       | Short strings, with an item label and a maximum count                            | Notes, contacts, vows  |
| Track      | A resource that goes down and up to a maximum                                    | Breath, nerve          |
| Conditions | A list of named conditions a Character can mark                                  | Hurt, Frightened       |
| Select     | One option out of a list, or none                                                | A die size per Station |
| Inventory  | Stacks of Gear, limited by slots                                                 | A pack                 |
| Derived    | A number computed from other fields by a Formula                                 | A modifier             |

The next three pages return to Derived, Checks and Inventory. This page is about the rest and about how the sheet is laid out.

## Hard Country's sheet

The Long Carry's bearers are made from eight fields.

- **Calling** is Text with a maximum of 80 characters: a ferryman, a nun, a deserter.
- **Back, Feet and Head** are Number fields from 2 to 18, default 8, each with the dice default `2d6+3`. The dice default is what a new Character rolls. Where nothing is rolled the default, 8, is used.
- **Wind** is a Track with a fixed maximum of 8. A Track's maximum can be fixed, set by each Character, or a Formula evaluated when the Character is created. Hard Country fixes it.
- **Wounds** is a Number from 0 to 3, default 0. Three is down. It is a Number, not a Track: it counts up to three and stops.
- **Pennies** is a Number from 0 to 100,000, default 60, with the dice default `2d6*10`.
- **Load** is an Inventory with a capacity of 8.

Wind and Wounds are two fields on purpose. Wind is what harm takes first, and Wounds is what harm takes when Wind is gone. With the right two fields, the harm rule fits in one sentence.

## Dogwatch's sheet

Dogwatch has four Stations, Helm, Hands, Eyes and Voice. Each is a Select over d4, d6, d8 and d10, default d6. A crew card hands the four dice out across the Stations.

A Select is not a Number. A Character picks an option, and the hint on the field is plain: no Formula reads it. That is why Dogwatch has eight Checks (see _Checks and Outcome Bands_). The die lives in the Select, and a Check's dice notation cannot read the sheet.

The rest: **Name and role** (Text), **Nerve** (Track, maximum 6), **Injuries** (Number, 0 to 3), **Chits** (Number, default 20) and **Kit** (Inventory, capacity 6).

## Groups and layout

Fields are placed in **groups**, the named boxes of the sheet. Each group has its own number of field columns, from one to four. The sheet itself lays its groups out in one or two columns.

Hard Country has four groups: Bearer, Traits (three columns, one per trait), Body (two columns, Wind beside Wounds) and Pack (two columns, Pennies beside Load). Dogwatch has Crew, Stations (four columns), Body and Kit. Once a sheet declares groups, every field must name one of them.

Group the fields as you would draw them on a card. Put the three things the player reads before every roll next to each other. Put money and gear away from the body.

## Building it in Studio

Open **Sheet** in the rail. **Add field** opens the form for one field: pick a type and the form shows what that type needs. **Paste fields** adds several at once from a pasted list. The **Layout** panel adds groups, orders them and sets their columns. A preview shows the sheet as a player sees it, with sample values.

Keys are 1 to 64 lower case letters, digits and hyphens, and a Formula refers to a field by its key, so choose a key you will like to read in `{back}`.

## Try it

Draw your Character on a card. For each thing you wrote, name its type from the table. If you reach for a Number to hold "which of these four", you wanted a Select. If a Number only climbs, ask whether it is a Track in disguise.
