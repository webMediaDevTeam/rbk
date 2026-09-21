import { useState, useEffect } from 'react'
import { toast } from 'sonner'
import { useStoreOutcome } from '../useOutcomes.js'

const OUTCOMES = [
  { value: 'OUI', label: 'Oui — intéressé', noteRequired: true, hasRecall: true },
  { value: 'NON', label: 'Non — refuse', noteRequired: true, hasRecall: false },
  { value: 'BOITE_VOCALE', label: 'Boîte vocale', noteRequired: true, hasRecall: true },
  { value: 'INJOINABLE', label: 'Injoignable', noteRequired: true, hasRecall: true },
]

const RECALL_UNITS = [
  { value: 'MINUTE', label: 'Minute(s)' },
  { value: 'HEURE', label: 'Heure(s)' },
  { value: 'JOUR', label: 'Jour(s)' },
  { value: 'SEMAINE', label: 'Semaine(s)' },
]

const RECALL_SUGGESTIONS = [
  { label: '30 min', amount: 30, unit: 'MINUTE' },
  { label: '1h', amount: 1, unit: 'HEURE' },
  { label: '6h', amount: 6, unit: 'HEURE' },
  { label: '1j', amount: 1, unit: 'JOUR' },
  { label: '2j', amount: 2, unit: 'JOUR' },
  { label: '1sem', amount: 1, unit: 'SEMAINE' },
]

export function useActionModal({ open, onClose, onActionSuccess, clientId }) {
  const storeOutcome = useStoreOutcome()

  const [outcome, setOutcome] = useState('OUI')
  const [note, setNote] = useState('')
  const [recallEnabled, setRecallEnabled] = useState(false)
  const [recallAmount, setRecallAmount] = useState(1)
  const [recallUnit, setRecallUnit] = useState('JOUR')
  const [error, setError] = useState(null)

  const selectedOutcome = OUTCOMES.find((o) => o.value === outcome)
  const hasRecallOption = selectedOutcome?.hasRecall ?? false
  const showRecall = hasRecallOption && (outcome !== 'OUI' || recallEnabled)
  const noteRequired = selectedOutcome?.noteRequired ?? true

  const isRecallSuggestion = (s) => recallAmount === s.amount && recallUnit === s.unit

  useEffect(() => {
    if (open) {
      setOutcome('OUI')
      setNote('')
      setRecallEnabled(false)
      setRecallAmount(1)
      setRecallUnit('JOUR')
      setError(null)
    }
  }, [open])

  const pending = storeOutcome.isPending

  const handleSubmit = (e) => {
    e.preventDefault()
    setError(null)

    if (noteRequired && !note.trim()) {
      setError('La note est requise pour ce choix.')
      return
    }

    const payload = {
      clientId,
      outcome,
      note: note.trim() || null,
    }

    if (showRecall) {
      payload.recall_amount = recallAmount
      payload.recall_unit = recallUnit
    }

    storeOutcome.mutate(payload, {
      onSuccess: () => {
        toast.success('Action enregistrée.')
        onActionSuccess?.()
        onClose()
      },
      onError: (err) => {
        const msg = err?.response?.data?.message || 'Une erreur est survenue.'
        setError(msg)
        toast.error(msg)
      },
    })
  }

  const handleSelectOutcome = (value) => setOutcome(value)
  const handleRecallEnabledChange = (e) => setRecallEnabled(e.target.checked)
  const handleSelectRecallSuggestion = (s) => {
    setRecallAmount(s.amount)
    setRecallUnit(s.unit)
  }
  const handleRecallAmountChange = (e) => setRecallAmount(Number(e.target.value))
  const handleRecallUnitChange = (e) => setRecallUnit(e.target.value)
  const handleNoteChange = (e) => setNote(e.target.value)

  return {
    pending,
    outcome,
    note,
    error,
    recallEnabled,
    recallAmount,
    recallUnit,
    hasRecallOption,
    showRecall,
    noteRequired,
    isRecallSuggestion,
    handleSubmit,
    handleSelectOutcome,
    handleRecallEnabledChange,
    handleSelectRecallSuggestion,
    handleRecallAmountChange,
    handleRecallUnitChange,
    handleNoteChange,
    OUTCOMES,
    RECALL_UNITS,
    RECALL_SUGGESTIONS,
  }
}