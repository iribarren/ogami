import { StudioError } from '../../useGameSystems'
import { useCreateOracleTable } from '../../oracles/useGameSystemOracles'
import type { NewTableCapability } from './stepContext'

/**
 * "New table" for an editor that holds a draft read at `readAt`. The create carries that version;
 * only a create that was accepted at it moves the editor to the version it answers with. A stale
 * version (409) is told to `onConflict` so the editor offers its reload; the draft stays as it is.
 */
export function useNewTable(
  gameSystemKey: string,
  readAt: number,
  adopt: (version: number) => void,
  onConflict: (message: string) => void,
): NewTableCapability {
  const create = useCreateOracleTable(gameSystemKey)

  return {
    gameSystemKey,
    create: async (table) => {
      try {
        const saved = await create.mutateAsync({ expectedVersion: readAt, table })
        adopt(saved.version)
      } catch (error) {
        if (error instanceof StudioError && error.status === 409) {
          onConflict(error.message)
        }
        throw error
      }
    },
  }
}
