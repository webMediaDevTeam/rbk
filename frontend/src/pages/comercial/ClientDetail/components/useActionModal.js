import { useState, useEffect } from 'react'
import { toast } from 'sonner'
import { useStoreOutcome } from '../useOutcomes.js'

const OUTCOMES = [
  { value: 'YES', label: 'Oui', recall: 'none' },
  { value: 'NO', label: 'Non', recall: 'none' },
  { value: 'BV', label: 'Boîte vocale', recall: 'auto' },
  { value: 'CALL_BACK', label: 'à rappeler', recall: 'custom' },
]

/** Valeur locale au format `datetime-local` (sans fuseau). */
const toLocalDateTimeValue = (date) => {
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/** Rappel CALL_BACK : datetime libre, valeur par défaut = now + 3 jours. */
const defaultRecallAt = () => toLocalDateTimeValue(new Date(Date.now() + 3 * 86400000))

export function useActionModal({ open, onClose, onActionSuccess, clientId }) {
  const storeOutcome = useStoreOutcome()

  const [outcome, setOutcome] = useState('YES')
  const [note, setNote] = useState('')
  const [recallAt, setRecallAt] = useState(defaultRecallAt())
  const [error, setError] = useState(null)

  const selectedOutcome = OUTCOMES.find((o) => o.value === outcome)
  // BV : rappel automatique à 3 jours (encart informatif).
  const showsAutoRecallInfo = selectedOutcome?.recall === 'auto'
  // CALL_BACK : l'employé choisit la date/heure du rappel (datetime, pas de selects).
  const showsRecallInput = selectedOutcome?.recall === 'custom'

  useEffect(() => {
    if (open) {
      setOutcome('YES')
      setNote('')
      setRecallAt(defaultRecallAt())
      setError(null)
    }
  }, [open])

  const pending = storeOutcome.isPending

  const handleSubmit = (e) => {
    e.preventDefault()
    setError(null)

    const payload = { clientId, outcome, note: note.trim() || null }

    if (showsRecallInput) {
      const when = new Date(recallAt)
      if (!recallAt || Number.isNaN(when.getTime()) || when.getTime() <= Date.now()) {
        setError('Choisissez une date de rappel dans le futur.')
        return
      }
      payload.recall_at = when.toISOString()
    }

    storeOutcome.mutate(payload, {
      onSuccess: () => {
        toast.success('Action enregistrée.')
        onActionSuccess?.()
        onClose()
      },
      onError: (err) => {
        const msg =
          err?.response?.data?.message ||
          Object.values(err?.response?.data?.errors ?? {})[0]?.[0] ||
          'Une erreur est survenue.'
        setError(msg)
        toast.error(msg)
      },
    })
  }

  const handleSelectOutcome = (value) => setOutcome(value)
  const handleNoteChange = (e) => setNote(e.target.value)
  const handleRecallChange = (e) => setRecallAt(e.target.value)

  return {
    pending,
    outcome,
    note,
    recallAt,
    error,
    showsAutoRecallInfo,
    showsRecallInput,
    minRecallAt: toLocalDateTimeValue(new Date()),
    handleSubmit,
    handleSelectOutcome,
    handleNoteChange,
    handleRecallChange,
    OUTCOMES,
  }
}
