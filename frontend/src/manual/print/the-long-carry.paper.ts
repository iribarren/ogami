import type { PaperFlow } from './paperFlow'

/**
 * The Long Carry on paper. The numbers are checked against `hard-country.json` by
 * `flowCards.test.ts`; every step a card cannot run from its text has its wording here.
 */
export const theLongCarryPaper: PaperFlow = {
  flow: 'the-long-carry',
  steps: {
    'bearers.bearer-1':
      'Who is the first bearer? Name them and give them a calling. Roll 2d6+3 for each of Back, Feet and Head. Wind starts at d6+2.',
    'bearers.outfit-1':
      'What do the bearers take on the road? Eight slots of Load each: kit and arms, written on the bearer’s card.',
    'bearers.bearer-2':
      'Who is the second bearer? The same again: name, calling, three traits, Wind.',
    'bearers.outfit-2': null,
    'bearers.bearer-3': 'A third bearer is optional. A fourth is too many to feed.',
    'bearers.outfit-3': null,
    'ford.cross':
      'Who carries the litter across, and how? Back to wade with the load, Feet to find the way. Roll a d20 at or under the trait. Failing: Winter +1 and lose d6 Wind. Going badly (a 20): Burden +1 and lose d6 Wind. With no Wind left, mark a Wound.',
    'omen.belief':
      'What do you now believe the saint did? Read the Sin as you last wrote it (blank at first). Does this omen confirm it, twist it or overturn it? Write it again. The last Sin you write is the Truth.',
    'shrine-gate.asked':
      'What do you tell the keeper? The keeper asks who the saint was, what they did and why you carried them. Read out the saint, the Sin and the vow as you wrote them.',
    'shrine-gate.nerve':
      'Can you say it and hold their gaze? Roll a d20 at or under Head. Failing: Burden +1. Going badly (a 20): Burden +2.',
    'epilogue.winter-late':
      'Read the ending on the rules card. Winter 7 or less is in time, more is late. Burden 2 or less is light, more is heavy.',
    'epilogue.burden-early': null,
    'epilogue.burden-late': null,
    'epilogue.ending-time': 'Laid down in time. Who is left at the shrine, and who walks home?',
    'epilogue.ending-price':
      'Laid down at a price. What does the shrine ask in exchange, and which bearer pays it?',
    'epilogue.ending-lost':
      'The carry is not finished. The gate is shut against the snow, or opens onto something you did not carry the saint for. What do you do with the body, and with the Sin? Who is left outside?',
  },
  deck: {
    kind: 'table',
    table: 'road-deck',
    back: 'The road deck',
    finale: {
      sceneType: 'shrine-gate',
      note: 'Shuffle this card into the bottom five cards of the deck. When it turns up, the Road ends.',
    },
  },
  rules: {
    slots: {
      saint: 'whose body you carry. Written in Session Zero.',
      sin: 'what the saint did, as you now believe it. Each Omen can change it. The last Sin you write is the Truth.',
      vow: 'what you promised, and to whom. Written in Session Zero.',
    },
    sections: [
      {
        heading: 'Choosing a scene',
        lines: [
          'Session Zero: play The bearers, The saint and The vow, in that order.',
          'The Road: draw the top card of the shuffled road deck, play its Scene Type, then do the World turn. Discard the card. The Road ends when the Shrine Gate card turns up, or sooner if the closing check says so.',
          'The Shrine Gate, then the Epilogue: one scene each.',
        ],
      },
      {
        heading: 'The World turn',
        lines: [
          'After every scene on the Road, unless the closing check ended it.',
          '1. Winter closes by one. Mark a box and write how the light is different today.',
          '2. Roll Road Risks on the tables sheet (d10, roll again above 9) and do what it says.',
        ],
      },
      {
        heading: 'Closing check',
        lines: [
          'After every scene on the Road, read Winter. 5 or less: play on. 6 to 9: roll a d6, and a 5 or 6 ends the Road. 10 ends it.',
        ],
      },
      {
        heading: 'Checks and harm',
        lines: [
          'A check is a d20: at or under the trait is a success, a 1 is clean, a 20 goes badly.',
          'Harm takes Wind first. With no Wind left, mark a Wound. Three Wounds put a bearer down. Wounds do not heal.',
          'Only a rest in Camp brings Wind back: d6+2, up to 8.',
        ],
      },
      {
        heading: 'The endings',
        lines: [
          'In time: Winter 7 or less. Light: Burden 2 or less.',
          'In time and light: Laid down in time.',
          'In time and heavy, or late and light: Laid down at a price.',
          'Late and heavy: The carry is not finished.',
        ],
      },
    ],
  },
}
