import { NativeSelect } from '@/shared/ui/native-select'

import { isColorMode } from './colorModeStorage'
import { useColorMode } from './useColorMode'

/** Header control to pick Light, Dark or System; keyboard accessible as a native select. */
export function ColorModeToggle() {
  const { mode, setMode } = useColorMode()

  return (
    <NativeSelect
      aria-label="Color mode"
      className="w-auto"
      value={mode}
      onChange={(event) => {
        if (isColorMode(event.target.value)) {
          setMode(event.target.value)
        }
      }}
    >
      <option value="light">Light</option>
      <option value="dark">Dark</option>
      <option value="system">System</option>
    </NativeSelect>
  )
}
