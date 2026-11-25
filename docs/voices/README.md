# Voice packs

A voice pack describes how one GameSystem sounds when the manual lets it speak. The [style guide](../style/manual-voice.md) has a game dial that decides where the game's voice may appear in a chapter, and the pack supplies the voice itself: who is speaking, in what register, with which words in English and in Spanish, and what that speaker would never say. There is one pack per GameSystem, named after its key, so the pack for the `hard-country` preset is [`hard-country.md`](hard-country.md).

We keep packs as files in the repository for now. Their sections are shaped so that each one can later become part of a "Voice" field on a GameSystem in Studio, which is why the format below is stricter than an ordinary document needs to be.

## The format

A pack is a Markdown file with YAML front matter and a fixed set of sections, in the order below. Section headings are the field names, written exactly as shown, because an agent (and later an importer) finds a field by its heading.

### Front matter

```yaml
---
key: hard-country          # the GameSystem key, required
gameSystem: Hard Country   # its display name, required
status: draft              # draft | confirmed, required
languages: [en, es]        # languages the pack covers, required
---
```

A pack is `draft` until a person has read every proposed Spanish term and confirmed or replaced it. Agents may use a draft pack, but they carry its proposed terms into the draft as proposed and say so in their report.

### Fields

| Heading | Required | Holds |
|---|---|---|
| `## Sources` | Yes | The preset files and manual chapters the pack is drawn from. Facts in samples must match them |
| `## Persona` | Yes | Who speaks at dial 2, and what they know and do not know. Prose, one or two paragraphs |
| `## Register` | Yes | How the voice sounds: sentence shape, vocabulary level, what moods it carries. Prose |
| `## Lexicon EN` | Yes | A table of `Term`, `Use`, `Avoid`: the game's words and the words that would break it |
| `## Lexicon ES` | Yes | A table of `EN`, `ES`, `Status`, `Note`. `Status` is `confirmed`, `proposed` or `gap` |
| `## Samples EN` | Yes | At least two short passages, each headed with its dial level and kind |
| `## Samples ES` | Yes | At least two, written natively in Spanish, never translated from the English samples |
| `## Taboos` | Yes | What the voice never does: tones, words, jokes, borrowings from other games |
| `## Dial notes` | Yes | How the voice behaves at dial 1 and at dial 2 for this game, and anything specific to its rules text |
| `## Open questions` | No | Decisions a person still has to make, such as a Spanish term with no good candidate |

A sample is headed `### Dial 1, played example`, `### Dial 2, chapter opener` or `### Dial 2, sidebar`, so an agent can pick the sample that matches the place it is writing.

### The Spanish lexicon

The Spanish lexicon is the one part of a pack that only a person may settle. Genre language in Spanish has conventions that a translator, human or not, can easily get wrong, and a made-up word in a game's voice is worse than a plain one. So the rules are strict. A `confirmed` term is used as written. A `proposed` term may be used in a draft, which then lists it in its report so a person can decide. A `gap` row, or a term that is missing from the table, means the writer uses neutral Spanish for that idea and flags it, and it never invents a word to fill the hole. The one exception is a choice that Spanish grammar forces, such as the gender of the saint in Hard Country before the user settled it, since no neutral wording survives a whole chapter. In a calibration sample the writer may then fill the gap provisionally and use the choice consistently, provided the sample lists it at the top as provisional and the report repeats it, so that a person sees the choice at work before making it. A manual chapter never ships with a provisional fill.

## From pack to Studio

When Studio gains a Voice field, each heading above maps to one part of it, which is why the headings are fixed.

| Pack | Future Voice field |
|---|---|
| Front matter `status`, `languages` | Voice status and languages |
| Persona, Register, Taboos, Dial notes | Text fields, one per language when they differ |
| Lexicon EN, Lexicon ES | One term list with a row per term and a column per language, with `Status` kept per Spanish term |
| Samples EN, Samples ES | A list of samples, each with a language, a dial level and a kind |
| Sources | Not stored; a release already knows its own content |

The field would belong to the GameSystem draft and travel with each GameSystem release, like the rest of a release's content. Until it exists, the file here is the source.

## How agents use a pack

The [`doc-writer`](../../.claude/agents/doc-writer.md) agent reads the pack whenever its brief sets the dial above 0. It takes the persona and register from the pack, uses only the lexicon's terms for the game's words, checks its passages against the samples for tone, and treats the taboos as hard limits. The [`doc-editor`](../../.claude/agents/doc-editor.md) agent reads the same pack and reports any passage that uses a term outside the lexicon, breaks a taboo or lets the game's voice into a rules statement.

Neither agent edits a pack. Changes to a pack are made by a person, or by an agent under a brief that names the pack as its target.

## Writing a new pack

A pack evokes a genre, and it never copies the text, names or invented slang of an existing game, even when the GameSystem was influenced by one. Start from the GameSystem's own presets and chapters, which are the only sources a pack may draw facts from, and write the persona and register first, since the lexicon and the samples follow from who is speaking. Leave every Spanish term `proposed` and set `status: draft` until a person has confirmed them.
