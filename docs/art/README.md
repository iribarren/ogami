# Art style guides

An art style guide says how one GameSystem, or the book itself, looks when the manual shows an illustration. The [voice packs](../voices/README.md) say how a game sounds; these guides say how it is drawn. They exist so that images generated one by one, by different people on different days, still read as one book and never break a voice pack's taboos.

There is one guide per style. `house.md` is the neutral style for the method and Studio chapters, and every GameSystem with a flow in the manual has one guide named after its key, such as [`hard-country.md`](hard-country.md).

The illustrations are AI-generated from the prompts these guides produce. The book credits that in its two credits chapters, and releases the images with the book under CC BY-SA 4.0.

## The format

A guide is a Markdown file with YAML front matter and a fixed set of sections, in the order below. Headings are the field names and are written exactly as shown.

```yaml
---
key: hard-country        # the style key, required; the same key as in styles.ts
gameSystem: Hard Country # its display name, or — for the house style, required
status: draft            # draft | confirmed, required
---
```

| Heading | Holds |
|---|---|
| `## Sources` | The voice pack and manual chapters the guide is drawn from, as repository paths |
| `## Setting` | Where and when the pictures take place |
| `## Palette and materials` | The colours and the technique, and the things the world is made of |
| `## Motifs` | The recurring subjects that are welcome |
| `## Mood` | The feeling a picture must carry |
| `## Composition` | How a picture is framed, and where the figures stand |
| `## Taboos` | What a picture never shows. Each line traces back to a voice pack line or to a shared rule |
| `## Prompt preamble` | One paragraph in a fenced `text` block, put in front of every prompt of the style |

## How to add a style

1. Write one file here with the format above.
2. Add one entry `{ key, name, preamble }` to `frontend/src/manual/images/styles.ts`.

The preamble in `styles.ts` is copied verbatim from the guide's `## Prompt preamble`. The node container mounts only `frontend/`, so no test can compare the two: they change together, by hand.

## Shared rules

Every style obeys these, whatever its setting.

- **Grayscale.** The Paper edition prints black on white, so every image must read clearly in grayscale.
- **No lettering.** No text, numbers, symbols or logos anywhere. Card faces, pages and screens are blank or illegible.
- **No faces.** Figures are seen from behind or at a distance, or only their hands appear.
- **No gore.**

## The directive

A manual chapter places an image with one line of its own, outside any `:::in-ogami` block, with the attributes in this order and no `"` inside a value:

```text
::image{file="hc-ford.webp" style="hard-country" size="half" alt="…" subject="…"}
```

| Attribute | Rule |
|---|---|
| `file` | Lower-case letters, digits and hyphens, then `.webp`, `.png` or `.jpg`. Short and unique, with a prefix per style (`hc-`, `dw-`, `house-`) |
| `style` | A key of `styles.ts` |
| `size` | `banner`, `half` or `spot` |
| `alt` | One plain sentence for a reader who cannot see the image |
| `subject` | One concrete sentence of what to draw, obeying the style's taboos |

The prompt to give the image tool is the style's preamble, a space and the subject. Until the file exists, the manual shows a placeholder with that full prompt, the alt text and the file name, in the web edition and in the Paper PDF.

## Sizes

| Size | In the book | Generate at |
|---|---|---|
| `banner` | Full width, 3:1 (Paper: 170 × about 57 mm) | 2400 × 800 |
| `half` | About 45% of the width, floated right, 4:3 (Paper: 80 mm wide) | 1600 × 1200 |
| `spot` | About 30% of the width, floated right, 1:1 (Paper: 50 mm wide) | 1200 × 1200 |

Save every image as `.webp`. Drop the file into `frontend/src/manual/images/` under the name the directive gives: the placeholder is replaced by the image with no code change.
