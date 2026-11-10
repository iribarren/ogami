import type { PaperFlow } from './paperFlow'

/** What a job card says when its compartment is chosen from the cards, not from a list on a screen. */
const WHERE = 'Choose a compartment card. If none is left, name a new one.'

/**
 * Cold Berth on paper. The numbers are checked against `dogwatch.json` by `flowCards.test.ts`;
 * every step a card cannot run from its text has its wording here. The Truths are sealed in
 * Session Zero and opened at the Reveal.
 */
export const coldBerthPaper: PaperFlow = {
  flow: 'cold-berth',
  steps: {
    'crew.crew-1':
      'Who is the first crew member? Give them a name and a role. Give each Station a die: d10, d8, d6 and d4, once each. Nerve starts full.',
    'crew.outfit-1':
      'What does the crew carry? Six slots of kit each, written on the crew member’s card.',
    'crew.crew-2': 'Who is the second crew member? The same again: name, role, four dice.',
    'crew.outfit-2': null,
    'crew.crew-3': 'A third is optional. Every extra card is one more voice to lose.',
    'crew.outfit-3': null,
    'truths.aboard':
      'What do you fear is aboard? Write your hunch. Then roll What is aboard (d8) on the tables sheet, write the result on a slip and seal it.',
    'truths.who':
      'Who do you think let it on? Write your hunch. Then roll Who let it on (d8), write the result on a slip and seal it.',
    'truths.why':
      'Why do you think you were woken? Write your hunch. Then roll Why we were woken (d8), write the result on a slip and seal it.',
    'investigate.where': `Which compartment do you go to? ${WHERE}`,
    'investigate.here': 'What is out of place in that compartment?',
    'investigate.improvise': null,
    'investigate.look':
      'How do you look, and what are you looking for? Roll the die of your Station: Eyes to notice and search, Hands to take something apart. Set the Difficulty by how hidden it is. Done: Clues +1. Short: Threat +1. Wrong: Threat +1 and lose 1 Nerve.',
    'repair.where': `Which compartment needs you? ${WHERE}`,
    'repair.here': 'What has failed in that compartment?',
    'repair.improvise': null,
    'repair.fix':
      'How do you fix it? Roll the die of your Station: Hands to fix and force, Helm to reach it. Done: Threat -1. Wrong: Threat +1 and lose 1 Nerve.',
    'confront.where': `Where does it happen? ${WHERE}`,
    'confront.here': 'Who or what waits in that compartment?',
    'confront.improvise': null,
    'confront.face':
      'How do you face them? Roll the die of your Station: Voice to persuade, lie or calm, Hands to force. Done: Clues +1. Short: lose 1 Nerve. Wrong: Threat +1 and lose 2 Nerve.',
    'hide.where': `Where do you go to ground? ${WHERE}`,
    'hide.here': 'What makes that compartment a place to hide?',
    'hide.improvise': null,
    'hide.quiet':
      'How quietly do you wait? Roll the die of your Station: Helm to slip away, Voice to keep someone calm, Eyes to watch. Done: regain 1 Nerve. Short: Threat +1. Wrong: Threat +1 and lose 1 Nerve.',
    'reveal.aboard-roll': 'What is aboard? Open the first sealed slip.',
    'reveal.who-roll': 'Who let it on? Open the second sealed slip.',
    'reveal.why-roll': 'Why were you woken? Open the third sealed slip.',
    'reveal.read':
      'How do these three fit together? Compare them to your hunches from Session Zero. Write the one story that makes all three true.',
    'reveal.hold':
      'Can you stand in front of it? Roll the die of your Station: Voice to speak, Helm to move, Eyes to watch. Short: Threat +1. Wrong: Threat +1 and lose 2 Nerve.',
    'epilogue.threat-end':
      'Read the ending on the rules card. Threat 5 or less is contained, more is boiling over. Clues 2 or less is little, more is plenty.',
    'epilogue.clues-calm': null,
    'epilogue.clues-hot': null,
    'epilogue.ending-clear':
      'Everyone out, and knowing. Who is on the shuttle? What do you tell the next crew that wakes?',
    'epilogue.ending-scraped':
      'Out, and none the wiser. Who still wonders? What would make you go back?',
    'epilogue.ending-price':
      'Out, but not all of you. Who stayed aboard, and who chose it? What do you carry out in their place?',
    'epilogue.ending-lost':
      'The ship keeps you. The airlock cycles, or it does not. Who is the last to see the cold berth?',
  },
  tips: {
    truths:
      'Three questions have an answer you do not know yet: what is aboard, who let it on, and why you were woken. The dice answer them now, and you seal the answers until the Reveal.',
    reveal:
      'Open the three sealed Truths. Do not smooth the result into what you hoped: make the answers fit, and then play toward the hardest one.',
  },
  deck: { kind: 'compartments', count: 9, back: 'The ship' },
  rules: {
    slots: {
      aboard: 'the first Truth. Rolled and sealed in Session Zero, opened at the Reveal.',
      who: 'the second Truth. Rolled and sealed in Session Zero, opened at the Reveal.',
      why: 'the third Truth. Rolled and sealed in Session Zero, opened at the Reveal.',
    },
    sections: [
      {
        heading: 'Choosing a scene',
        lines: [
          'Session Zero: play The crew, The ship and The Truths, in that order. Write each compartment you name on a blank compartment card.',
          'Wake: The empty berth, then The custodian.',
          'The Ship: lay the four job cards face up and pick one (Investigate, Repair, Confront or Hide). Choose a compartment card for it, or name a new one, and play it. Then do the World turn.',
          'The Reveal, then the Epilogue: one scene each.',
        ],
      },
      {
        heading: 'The World turn',
        lines: [
          'After every job on the Ship, unless the closing check ended it.',
          '1. Threat climbs by one. Mark a box and write what has changed on the ship.',
          '2. Roll Ship trouble on the tables sheet (d10, roll again above 9) and do what it says.',
          '3. The ship takes a crew member from the manifest, chosen at random. Cross them off, write where they were last seen and start a Thread to find them.',
        ],
      },
      {
        heading: 'Closing check',
        lines: [
          'After every job on the Ship, read Threat. 4 or less: play on. 5 to 7: roll a d6, and a 5 or 6 brings the Reveal. 8 ends it.',
        ],
      },
      {
        heading: 'Checks and harm',
        lines: [
          'Roll the die of the Station you use against a Difficulty you set from 2 to 8, usually 3. A 1 goes wrong, under the Difficulty falls short, at or over it is done. If someone helps, roll the die twice and keep the highest.',
          'Harm takes Nerve first. With no Nerve left, mark an Injury. Three Injuries put a crew member down.',
        ],
      },
      {
        heading: 'The Truths',
        lines: [
          'In Session Zero, roll the three Truths on the tables sheet and write each result on a slip. Seal the slips and do not open them. At the Reveal, open them and write the one story that makes all three true.',
        ],
      },
      {
        heading: 'The endings',
        lines: [
          'Threat 5 or less is contained, more is boiling over. Clues 2 or less is little, more is plenty.',
          'Contained and plenty: Everyone out, and knowing. Contained and little: Out, and none the wiser.',
          'Boiling over and plenty: Out, but not all of you. Boiling over and little: The ship keeps you.',
        ],
      },
    ],
  },
}
