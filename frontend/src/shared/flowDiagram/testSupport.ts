import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/** A preset file as PHP publishes it, read from backend/presets/ (the node container mounts it). */
export function loadPreset(name: string): unknown {
  return JSON.parse(readFileSync(resolve(process.cwd(), `../backend/presets/${name}.json`), 'utf8'))
}

type Json = Record<string, unknown>

/** A Scene Type with no steps unless the test gives some. */
export function sceneType(key: string, steps: Json = {}): Json {
  return {
    key,
    name: key.toUpperCase(),
    purpose: 'A purpose.',
    oracles: [],
    setup: [],
    play: [],
    closing: [],
    ...steps,
  }
}

export function step(key: string, rest: Json = {}): Json {
  return { key, kind: 'prompt', title: key, mandatory: true, ...rest }
}

export function table(key: string, entries: Json[]): Json {
  return { key, name: key.toUpperCase(), entries }
}

export function phase(key: string, rest: Json = {}): Json {
  return {
    key,
    name: key.toUpperCase(),
    mode: 'once',
    selection: { rule: 'sequence', sceneTypes: [] },
    ...rest,
  }
}

/** Release content with the given Scene Types, tables and phases, in flow `f`. */
export function release(parts: { sceneTypes?: Json[]; tables?: Json[]; phases: Json[] }): unknown {
  return {
    schemaVersion: 2,
    oracles: { tables: parts.tables ?? [], likelihood: [] },
    sceneTypes: parts.sceneTypes ?? [],
    flows: [{ key: 'f', name: 'F', phases: parts.phases }],
  }
}
