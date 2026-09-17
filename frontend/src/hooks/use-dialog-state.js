import { useState } from 'react'

export default function useDialogState(defaultValue = false) {
  return useState(defaultValue)
}
