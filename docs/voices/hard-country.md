---
key: hard-country
gameSystem: Hard Country
status: confirmed
languages: [en, es]
---

# Hard Country voice

## Sources

- `backend/presets/hard-country.json`, the GameSystem and its flow `the-long-carry`
- `frontend/src/manual/content/rules-hard-country.md`
- `frontend/src/manual/content/flow-the-long-carry.md`
- `frontend/src/manual/content/the-promise.md`, the section on The Long Carry

## Persona

The voice belongs to an old bearer who has walked the carry before, more than once, and has buried friends beside the road. They are not named, because the reader's own bearers are the ones who matter, and the old bearer speaks as someone who has already been where the reader is going. They know the fords, the frosts and how long a day's walking really is. They do not know what the saint did, since nobody ever does, and they have stopped expecting to find out.

They speak to the reader as to a younger bearer at the start of the road: plainly, without lecturing, out of the habit of looking after people. Their faith has survived the road without coming out of it untouched. They still pray, and they still carry, although they no longer believe the road is fair or that the shrine owes anyone anything.

## Register

Weary, devout and unfair, which is the tone The Long Carry promises. The speech is plain and a little old, in the way of someone from a village rather than a court, with short words, concrete nouns and few abstractions. Sentences are mostly short, but they run on with "and" when the speaker is tired or remembering, and they often end on a practical thing (a foot, a rope, the weather) instead of a feeling. The old bearer names feelings rarely and only once.

Prayer is ordinary in this voice. Blessings, saints' days and small rites appear as part of the work, the way weather does, and they are never explained. Death is spoken of directly, because the speaker has seen too much of it to be delicate.

## Lexicon EN

| Term | Use | Avoid |
|---|---|---|
| the carry | The journey and the work of it | the quest, the mission, the adventure |
| bearer | One who carries the saint | hero, adventurer, party member |
| the saint | The body on the litter; never a name unless the Fact gives one | the corpse, the relic |
| the litter | What the saint is carried on | the stretcher, the coffin |
| the shrine | Where the carry ends | the temple, the goal |
| the road | Everything between the start and the shrine | the map, the route |
| Winter, first snow | The clock, and what it brings | the deadline, the timer |
| Burden | What the bearers took on: favours, debts, things said | karma, guilt points |
| Wind | Breath and nerve together | health, hit points, stamina |
| a Wound | Harm that does not heal | damage |
| pennies | Money | gold, coins, credits |
| the vow | What the bearers promised | the oath, the contract |
| the Sin | What the bearers believe the saint did | the secret, the twist |
| camp, a ford, a stranger, a ruin, an omen | The road's scenes, lower case in speech | encounter, event |

## Lexicon ES

Every term was confirmed by the user on 2026-11-25: *el santo*, *Carga* and *La gran Carga* in the calibration of The Promise, the rest in the follow-ups of `manual-voice` ([#401](https://github.com/iribarren/ogami/issues/401)).

| EN | ES | Status | Note |
|---|---|---|---|
| the carry | el acarreo | confirmed | *Carga* already names Burden and the flow's title, so the journey takes another word |
| bearer | porteador, porteadora | confirmed | |
| the saint | el santo | confirmed | Generic masculine, whatever the Fact says about who the saint was |
| the litter | las andas | confirmed | The word used for carrying saints in Spanish processions |
| the shrine | el santuario | confirmed | |
| the road | el camino | confirmed | |
| Winter, first snow | Invierno, las primeras nieves | confirmed | Tracker name capitalised |
| Burden | Carga | confirmed | Tracker name, capitalised. The same word as the flow's title, on purpose |
| Load, slots | Hatillo, huecos | confirmed | The bundle a traveller carries; *Carga* is taken and *casilla* belongs to Fact slots |
| Wind | Aliento | confirmed | Covers both breath and courage |
| a Wound | una Herida | confirmed | |
| Back, Feet, Head | Espalda, Pies, Cabeza | confirmed | Trait names |
| pennies | cuartos | confirmed | Old coin and colloquial word for money in Spain |
| the vow | el voto | confirmed | Religious sense |
| the Sin | el Pecado | confirmed | |
| Road Risks | Peligros del camino | confirmed | Table name |
| Camp, Ford, Stranger, Ruin, Omen | Campamento, Vado, Forastero, Ruina, Presagio | confirmed | Scene Types |
| The Shrine Gate | La puerta del santuario | confirmed | |
| Clean success, Goes badly | Éxito limpio, Sale mal | confirmed | Check results |
| The Long Carry | La gran Carga | confirmed | Translated by meaning: *carga* is both the saint on the bearers' shoulders and the Burden Tracker |

Tracker names take the article in Spanish prose, as the names of seasons and other common nouns do, so the clock is written *el Invierno avanza un segmento* and the counter *la Carga sube en uno*. The article is dropped only where the name stands on its own, as when a sentence introduces it (*un reloj llamado Invierno*) or in a title, a sheet label or a table heading.

## Samples EN

### Dial 1, played example

> The table gives: _A hard frost in the night. Everything you own is stiff by morning._
>
> The ropes are stiff as sticks until we have held them over the fire, and Mother Ilse's hands will not close round the poles at all. I write: "We lose the first hour of the day to the frost. Ilse walks beside the litter and prays instead of carrying."

### Dial 2, chapter opener

> I have walked this road four times, and I have come home from it three. The fourth time I carried a friend's boots back to his wife, because there was nothing else of him worth the weight. I tell you this so you know the road does not care how hard you pray. It cares how many days are left before the snow, and how much you have taken on to buy them.

### Dial 2, sidebar

> When a ford is up, do not stand and talk about it. Talk is what the cold wants from you. Pick the strongest back, put the rope on them, and go, and say the prayer on the other side, where it can do some good.

## Samples ES

### Dial 1, played example

> La tabla da: _A hard frost in the night. Everything you own is stiff by morning._
>
> Las cuerdas están tiesas como palos hasta que las acercamos al fuego, y a la madre Ilse no le cierran las manos sobre las varas. Escribo: «La helada nos quita la primera hora del día. Ilse camina junto a las andas y reza en lugar de llevarlas».

The table entry stays in English until the preset has a Spanish edition, since the reader rolls the preset's words.

### Dial 2, chapter opener

> He hecho este camino cuatro veces y solo he vuelto de tres. La cuarta traje a su mujer las botas de un amigo, porque no quedaba nada más de él que mereciera el peso. Te lo cuento para que sepas que al camino no le importa cuánto reces. Le importa cuántos días quedan antes de las primeras nieves, y cuánto has cargado a cuestas para comprarlos.

### Dial 2, sidebar

> Si el vado viene crecido, no os quedéis hablando en la orilla, que eso es justo lo que quiere el frío. Buscad la espalda más fuerte, atadle la cuerda y cruzad. La oración, rezadla al otro lado, donde sirve de algo.

## Taboos

- No humour, irony or winking at the reader. The old bearer has nothing to joke about.
- No faux-archaic speech: no "thee", "thou", "ye", "-eth", and in Spanish no *vuestra merced* or archaic verb forms.
- No heroics. Nobody in this voice wins against the weather, and nobody is praised for being brave.
- No theology. The voice prays and never explains doctrine, names a church or says what the saint did.
- No modern words (deadline, stress, trauma, okay) and no game words (roll, hit points, level) in the narrator's mouth.
- Nothing taken from another game or setting: no borrowed names, places, gods or slang.
- The old bearer never states a rule. They may remember what a rule felt like, never what it says.

## Dial notes

At dial 1 the voice belongs to the player writing in the notebook, so the played example uses the lexicon and the weary register but speaks as "I" or "we" of the reader's own bearers, never as the old bearer. Table entries quoted from the preset keep their exact words.

At dial 2 the old bearer speaks in chapter openers and in sidebars, always in a blockquote and always in the first person. A passage should stay short, around five sentences, because it sets the mood for the rules beside it rather than competing with them. It must never contradict a number in the rules: if the old bearer mentions the snow, the snow still comes when Winter's ten boxes say so.

Rules text in Hard Country chapters stays in the house voice at every dial, including the check table, the kit and the endings.
