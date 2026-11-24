# Manual voice

This guide describes how we write the Ogami manuals: the Studio manual, the Play and Admin manuals that will follow it, and the rules and flow chapters of each GameSystem. It exists because the first manual, although it was accurate, read as if a machine had written it, and readers trust a text less once they notice that. We wrote the guide for two kinds of reader. The first is anyone who writes or edits a chapter by hand. The second is the pair of agents that draft and review chapters for us, [`doc-writer`](../../.claude/agents/doc-writer.md) and [`doc-editor`](../../.claude/agents/doc-editor.md), which read this file before they touch a page. Since the guide is written in the voice it describes, a paragraph here that breaks one of its own rules is a bug, and whoever finds it should fix it.

## Who we write for

Our reader is someone who wants to run a story alone, or to build a game that other people will run alone. They are patient enough to read a chapter from start to end, but they are reading it to do something afterwards, so every paragraph has to leave them better able to do it. They already know what a die and a character sheet are. They may not know why a solo game needs an oracle, and they almost certainly have not thought about how a table's weights shape a story's tone.

That reader does not need to be entertained, and they do not want to be sold to. What they want is to understand why the method works, because a method you understand is one you can bend to your own game without breaking it.

## The house voice

### We argue for the method

The model is the designer essay, the kind of writing found in books such as *Blades in the Dark* or *Shape Up*, where the author explains a method and argues for it at the same time. A rule in that kind of book rarely arrives alone; it comes with the problem it solves and with what goes wrong when you leave it out. We write the same way. When a chapter tells you to give the quiet results of a table the most weight, it also tells you what happens to a table that is all trouble.

The narrator is "we", the people who make Ogami, and the reader is "you". We speak from experience of having built and played these flows, and we say so when it helps ("we tried a twelve-box clock first, and the middle sagged"). The voice is earnest. There are no jokes, and the warmth in it comes from taking the reader's problem seriously and explaining things properly, which is a kind of care that readers notice. In the Paper edition the common text never names the app, so "we" stays unnamed there.

### Sentences carry their reasons

Most of the machine-made feeling in a text comes from what is missing between its sentences. A writer who knows why one thing follows from another says so with a connective (because, so, which means, although, since, while, that is why), and the reader follows the argument without having to rebuild it. A writer who leaves the connectives out produces a list of true statements that the reader has to join up alone.

So we let sentences depend on one another. A claim is followed by its reason, or by its consequence, or by the case where it fails. Sentence length varies with the thought. A long sentence holds a cause and its effect together, and a short one can land a point once the argument has earned it. If every sentence in a paragraph is the same length and starts with its subject, the paragraph will read as a list even when it is not formatted as one.

### A paragraph is one line of reasoning

Each paragraph makes one argument from its first sentence to its last. It usually opens with the claim, works through why the claim holds or how to act on it, and stops when the reasoning is complete. It does not need a closing line that sums it up, because a reader who has followed the argument already has the summary. Paragraphs of one sentence are rare; we use one only when a single sentence really is a whole step of the argument, and never to make a line sound important.

### Prose for method, tables for reference

Lists and tables are right for material the reader looks things up in, such as the kit and its costs, the steps of a World turn, the scene types of a deck or the ending a pair of Tracker values selects. A reader comes back to that material in the middle of play and needs to find one row quickly. Method is different, because it is an argument, and an argument broken into bullets loses the connectives that made it one. When we explain why a flow needs a clock, or how to choose a premise, we write paragraphs.

A numbered list is right when the order of the steps matters and the reader will follow them one at a time, as with the steps of filling in a promise card. Even then, each item can be a full sentence with its reason attached.

## Tells that make a text read machine-made

The tells below are the ones we found in the first manual and in drafts since. None of them is wrong on its own; a colon or a list of three is sometimes exactly right. They become tells when they repeat, because repetition shows that a pattern is producing the text rather than the thought. Each entry names the tell, says why readers notice it, and shows a sentence from the first draft of *The Promise* beside a rewrite.

### Staccato assertions

A run of short, independent claims, each true, with the reasoning between them left out. It reads as machine-made because a person who understood the link between the claims would have stated it.

> **Before.** Tone shows in the weights. A table that is all trouble wears you down by the third roll. A table where most results are quiet makes the bad ones land.
>
> **After.** Tone lives in the weights of your tables more than in their words. If every result brings trouble, you learn to brace for it, and by the third roll trouble has stopped meaning anything. When most results are quiet, the bad ones break a pattern you had started to trust, which is why they land.

### Missing connectives

This is the cause of the staccato tell, and it is worth checking separately because it also appears in long sentences. Count the words that join reasons to claims in a paragraph. If there are none, the paragraph is a set of statements rather than an explanation, however fluent each statement is.

> **Before.** Alone, you have no one to do these jobs. You also cannot surprise yourself by deciding something, because you already know it.
>
> **After.** When you play alone, nobody else is there to do these jobs, and you cannot do the surprising one yourself, since anything you decide is already known to you by the time you decide it.

### The punchline closer

A paragraph that ends on a short, quotable line which restates what came before it. It reads as machine-made because it is a habit of the form rather than a need of the argument, and once the reader has seen it twice they start to skim for it.

> **Before.** Every flow starts with a promise: what this story is about, how it feels, and how long it lasts. You make it before you design a single scene. Everything below it exists to keep it.
>
> **After.** Every flow starts with a promise about what the story is about, how it should feel and how long it will last. We ask you to make it before you design a single scene, because each later piece of the flow is chosen to keep that promise, and you cannot choose well without knowing what it is.

### The colon reveal

A sentence that sets up a mystery and resolves it after a colon, as if the explanation were a surprise. Used once it is fine, but used as the default way to explain something it turns every point into a small performance.

> **Before.** That is what a good game master does: it makes the plan fail in a way that is interesting.
>
> **After.** A good game master would have done the same thing, letting your plan fail in a way that gives you something better to do.

### The reflexive triad

Three jobs, three properties, three tone words, three check questions. Lists of three feel complete, which is why a pattern reaches for them, but real material rarely comes in threes every time. When a draft has a triad, ask whether the material has three parts or whether the third was added for rhythm. If it was added, cut it, and if the real number is two or four, use that.

> **Before.** A good premise has three properties.
>
> **After.** A premise works when it can be answered and when the answer has a price. It also helps if you want to know the answer, although that is harder to test before you play.

### The contrast for effect

"X, not Y" and "not X but Y" are honest when the reader is likely to believe Y and needs correcting. When nobody believed Y in the first place, the contrast is there only for emphasis, and a text full of them sounds as if it is arguing with someone who is not in the room.

> **Before.** The ship is not hostile; it follows rules written by someone else.
>
> **After.** The ship follows rules that someone else wrote and nobody aboard can read, which is more frightening than hostility would be.

### Lists that should be prose

Bullets break an argument into pieces and drop the connectives between them. If the items of a list depend on one another, or if the reader needs to understand why each is there, the list should be a paragraph. The test is whether the items could be read in any order without loss. If they could, a list may be right. If they could not, write prose.

### Uniform rhythm

Every sentence opens with its subject, runs to about the same length and ends with a full stop. No single sentence is wrong, but together they produce the flat, even rhythm that readers associate with generated text. Vary where sentences start (with a condition, a time, a reason) and let their length follow the thought.

### Signposting and filler

"Here is the thing", "let's dive in", "it's worth noting that", "in this section we will". These phrases announce content instead of delivering it. Hedges such as "arguably", "in many ways" and "it can be said that" do the same work from the other direction, by weakening a claim the writer was not willing to make plainly. Cut both. If a claim needs qualifying, say what the exception is.

### Dash overuse

The em dash is a useful mark that drafts lean on until every second sentence has an aside in it. We prefer commas, brackets or a new sentence, and we use a dash only where none of those works, which in most chapters is nowhere.

## Writing in English

We write British-leaning English, as the existing manual does: "colour", "travellers", "for ever". We use the imperative for steps the reader takes ("Shuffle the road deck"), and the second person for everything else. Contractions are fine in the essay passages and kept out of rules statements, where "do not" is easier to read as a rule than "don't".

Game terms are capitalised as the glossary and the presets capitalise them (Tracker, Fact, Scene Type, World turn), and we never swap one for a synonym to avoid repetition, because a reader who meets "counter" in one paragraph and "track" in the next will assume they are two things. Repetition of a term is clearer than variety.

## Writing in Spanish

The Spanish edition is written in the Spanish of Spain, addressing the reader as *tú* and a group as *vosotros*, in a neutral professional register. It is written natively, which means the writer works from what a paragraph has to say and builds the Spanish paragraph from that meaning, in Spanish order and rhythm. It is never a translation sentence by sentence of the English, because a Spanish paragraph that follows English sentence boundaries inherits the English rhythm, and Spanish readers hear that at once.

The tells in the catalogue above apply to Spanish too. Spanish tolerates longer sentences with more subordination than English, so a run of short independent claims sounds even more mechanical there. The connectives that carry reasons are *porque, ya que, así que, por eso, de modo que, aunque, mientras, lo cual*, and a paragraph without any of them has the same problem it would have in English.

This is the tone paragraph from the catalogue, written in Spanish from its meaning:

> El tono de un flujo está más en el peso de sus tablas que en lo que dicen. Si cada resultado trae problemas, aprendes a esperarlos, y hacia la tercera tirada ya no significan nada. Cuando casi todos los resultados son tranquilos, los malos rompen una costumbre en la que habías empezado a confiar, y por eso pesan tanto. Dale a los resultados tranquilos la mayor parte de las opciones y deja que los pocos malos sostengan la escena.

### Calques and other tells in Spanish

| Avoid | Write instead | Why |
|---|---|---|
| *hacer sentido* | *tener sentido* | Calque of "make sense" |
| *en orden de* + infinitive | *para* | Calque of "in order to" |
| *aplicar* a un puesto | *solicitar, presentarse a* | Calque of "apply for"; *aplicar una regla* is correct |
| *eventualmente* meaning "in the end" | *al final, con el tiempo* | In Spanish it means "possibly" |
| *asumir* meaning "suppose" | *suponer, dar por hecho* | *Asumir* is to take on a responsibility |
| *es hecho por*, *fue decidido por* | *lo hace*, *se decide* | Spanish prefers the active voice or the *pasiva refleja* |
| *Levanta su mano*, *tira sus dados* | *Levanta la mano*, *tira los dados* | Spanish uses the article where English uses a possessive |
| *Tirando el dado, sumando el rasgo y comparando…* | Finite verbs in separate clauses | Gerund chains read as translated; a gerund of afterwards (*sacando un 5*) is incorrect |
| *Es por eso que* | *Por eso* | Calque of "it is for that reason that" |
| *usted* | *tú*, *vosotros* | Our register throughout |
| English title case (*La Larga Travesía*) | Capital on the first word and on names only | Spanish capitalisation |
| "comillas rectas" for quotations | «comillas angulares» | Spanish typographic convention; straight quotes are kept inside code |

### Terminology

One English term has one Spanish term, and the writer never varies it. The rule for choosing is that what the reader handles at the table with a pencil gets a Spanish word, while what exists only inside the app keeps its English name, because that is the name the reader will see on the screen. Studio, Play and Admin are product names and stay as they are. Screen labels quoted inside an "In Ogami" block stay in English and in bold, exactly as the app shows them, since the app is in English.

| Glossary term | Spanish | Note |
|---|---|---|
| GameSystem, GameSystem release | `GameSystem`, versión publicada del `GameSystem` | App object; in common text say *juego* |
| NarrativeFlow | `NarrativeFlow` on first mention, then *el flujo* | The English text also says "flow" |
| FlowRun, SheetTemplate, roles (`SOLO_PLAYER`…) | Unchanged | Identifiers |
| Phase | fase | |
| Scene, Scene Type | escena, tipo de escena | |
| Session, Session Zero | sesión, sesión cero | |
| World turn | turno del mundo | |
| Tracker | marcador | Its kinds are *reloj* (clock) and *contador* (counter) |
| Fact, Fact slot | Hecho, casilla de Hecho | Capitalised, as in English, so it is not read as the participle |
| Truth | Verdad | |
| Check | prueba | *Chequeo* is a calque |
| Roll | tirada | |
| Dice, die | dados, dado; d6, d20 unchanged | |
| Difficulty | dificultad | |
| Outcome band | franja de resultado | |
| Oracle, OracleTable | oráculo, tabla de oráculo | |
| Character, sheet | personaje, ficha | |
| Campaign, Journal, Thread | campaña, diario, hilo | |
| NPC | PNJ | Spain convention |
| Party | grupo | |
| Game master | director de juego | Not *máster*, which is colloquial |
| Deck, card | mazo, carta | A notebook page is a *hoja*, so the promise card is the *hoja de la promesa* |

Terms of a particular game, such as a GameSystem's traits, Trackers and tables, are not in this table. They live in the game's voice pack, and a person chooses them there.

## The game dial

Each GameSystem chapter can carry some of its game's voice, and the dial says how much. The reason for a dial, rather than a single setting, is that the voice that makes a played example feel like the game would make a rules statement harder to read, so we want to raise it in some places and keep it out of others.

| Dial | What may be in the game's voice | Default for |
|---|---|---|
| 0 | Nothing; the house voice throughout | Studio, Play and Admin manuals |
| 1 | Played examples, and oracle table entries the writer authors, in the pack's register and lexicon | GameSystem rules and flow chapters |
| 2 | Also in-world sidebars and chapter openers, spoken by the pack's narrator in the first person | Only when the brief asks for it |

At every level, rules statements stay neutral. A rule says what to roll, what to mark and what happens next, and it says it in the house voice, so that a reader can find it, trust it and apply it in the middle of a scene. The game's voice may surround a rule but never rewords one. At dial 2 the narrator's passages are set apart from the rules as a blockquote, so the reader can always tell which text they have to obey and which they are only meant to hear.

Oracle table entries that already exist in a preset are quoted exactly as the preset has them, at any dial, because the reader will meet the same words when they roll the table. The dial only affects entries the writer invents for an example.

The voice itself comes from a voice pack in [`docs/voices/`](../voices/README.md), one per GameSystem. A writer working at dial 1 or 2 uses the pack's lexicon and register, and never invents its own. When the Spanish lexicon has no term for something, the writer uses plain Spanish and flags the gap for a person to fill, because genre slang that sounds invented by a translator is worse than no slang at all.

## Facts, terms and fences

Restyling a chapter never changes what it says. Every number, range, weight and rule in a rewrite matches the text it replaces and the preset it describes, which for the bundled games are the files in `backend/presets/`. If a writer finds that the old text and the preset disagree, they report the disagreement instead of resolving it, since only a person can say which one is wrong.

Glossary terms from [`docs/domain/glossary.md`](../domain/glossary.md) are written exactly as the glossary spells them, and in Spanish exactly as the terminology table gives them.

The manual's chapters live in `frontend/src/manual/content/`, and the structure that its tests and its two editions rely on has to survive a rewrite unchanged:

- **Edition blocks.** Text that belongs only to the Full edition sits between a line `:::in-ogami` and a line `:::`. The Paper edition drops these blocks ([ADR 0050](../adr/0050-manual-one-source-two-editions.md)). Keep every block, keep its markers on lines of their own, and never put any other `:::` line in a chapter, because the splitter rejects it.
- **The common text of a Paper chapter.** In a chapter that is in both editions, text outside the blocks never names Ogami, Studio or the app and never holds an internal link (`](/`), because it is printed without the app. The tests check both.
- **Headings.** Keep the headings that other chapters or the app link to, and keep chapter titles as `chapters.ts` lists them.

## How we check a chapter

A chapter is drafted by `doc-writer`, which reads this guide and the voice pack first and checks its own draft against the catalogue of tells before it returns. A separate `doc-editor` then reads the draft against the same guide and returns notes by line, together with any change of fact, term or fence it finds. We keep the two apart because a writer reviewing its own text sees what it meant to write, whereas an editor sees only what is on the page. There is no lint for any of this, since the tells are matters of judgement and a rule that counted colons would push drafts towards a different set of habits.
