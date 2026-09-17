import { useState, useEffect } from 'react'
import { AlertCircle, Loader2, X } from 'lucide-react'
import { toast } from 'sonner'
import Button from '@/components/ui/button.jsx'
import Input from '@/components/ui/input.jsx'
import Select from '@/components/ui/select.jsx'
import { useCreateNote } from '../useNotes.js'
import { useStoreOutcome, useReleaseClient } from '../useOutcomes.js'
import { cn } from '@/lib/utils.js'

const STATUS_TYPES = [
  { value: 'OUI', label: 'Oui — client intéressé', needsReservation: true },
  { value: 'NON', label: 'Non — client refuse', needsReservation: false },
  { value: 'BOITE_VOCALE', label: 'Boîte vocale', needsReservation: true },
  { value: 'RELEASE', label: 'Rendre disponible', needsReservation: true },
  { value: 'BLACKLIST', label: 'Blacklist', needsReservation: true, blacklistOnly: true },
]

const NOTE_TYPES = [
  { value: 'GENERAL_NOTE', label: 'Note générale' },
  { value: 'CALL_LOG', label: 'Appel (journal)' },
  { value: 'TASK', label: 'Tâche' },
]

const RECALL_UNITS = [
  { value: 'MINUTE', label: 'Minute(s)' },
  { value: 'HEURE', label: 'Heure(s)' },
  { value: 'JOUR', label: 'Jour(s)' },
  { value: 'SEMAINE', label: 'Semaine(s)' },
  { value: 'MOIS', label: 'Mois' },
]

const STATUS_VALUES = STATUS_TYPES.map((t) => t.value)
const NOTE_VALUES = NOTE_TYPES.map((t) => t.value)

export default function ActionModal({
  open,
  onClose,
  clientId,
  hasReservation = false,
  hasCalled = false,
  canBlacklist = false,
  reservedByName = null,
  initialType,
}) {
  const createNote = useCreateNote()
  const storeOutcome = useStoreOutcome()
  const releaseClient = useReleaseClient()

  const [type, setType] = useState('GENERAL_NOTE')
  const [content, setContent] = useState('')
  const [recallAmount, setRecallAmount] = useState(1)
  const [recallUnit, setRecallUnit] = useState('JOUR')
  const [dueDate, setDueDate] = useState('')
  const [callDuration, setCallDuration] = useState('')
  const [error, setError] = useState(null)

  const isStatus = STATUS_VALUES.includes(type)
  const isNote = NOTE_VALUES.includes(type)
  const isVoicemail = type === 'BOITE_VOCALE'
  const isTask = type === 'TASK'
  const isCallLog = type === 'CALL_LOG'
  const noteRequired = isNote

  const isDisabled = (opt) => {
    if (opt.blacklistOnly) return !canBlacklist
    if (opt.needsReservation) return !hasReservation
    return false
  }

  const disabledReason = (opt) => {
    if (opt.blacklistOnly && !canBlacklist) {
      return hasReservation ? ' — appel précédent requis' : ' — réservation requise'
    }
    return ' — réservation requise'
  }

  useEffect(() => {
    if (open) {
      setType(initialType || (hasReservation ? 'OUI' : 'GENERAL_NOTE'))
      setContent('')
      setRecallAmount(1)
      setRecallUnit('JOUR')
      setDueDate('')
      setCallDuration('')
      setError(null)
    }
  }, [open, initialType, hasReservation])

  if (!open) return null

  const pending = createNote.isPending || storeOutcome.isPending || releaseClient.isPending

  const handleSubmit = (e) => {
    e.preventDefault()
    setError(null)

    if (noteRequired && !content.trim()) {
      setError('Le contenu est requis.')
      return
    }

    const onSuccess = () => {
      toast.success('Action enregistrée.')
      onClose()
    }
    const onError = (err) => {
      const msg = err?.response?.data?.message || 'Une erreur est survenue.'
      setError(msg)
      toast.error(msg)
    }

    if (type === 'RELEASE') {
      releaseClient.mutate(clientId, { onSuccess, onError })
      return
    }

    if (isStatus) {
      const payload = {
        clientId,
        outcome: type,
        note: content.trim() || null,
      }
      if (isVoicemail) {
        payload.recall_amount = recallAmount
        payload.recall_unit = recallUnit
      }
      storeOutcome.mutate(payload, { onSuccess, onError })
      return
    }

    const payload = {
      client_id: clientId,
      type,
      content: content.trim(),
    }
    if (isTask && dueDate) payload.due_date = dueDate
    if (isCallLog && callDuration) payload.call_duration_seconds = parseInt(callDuration, 10)

    createNote.mutate(payload, { onSuccess, onError })
  }

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="relative w-full max-w-lg max-h-[90vh] overflow-y-auto rounded-2xl border bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-lg font-semibold text-foreground">Action</h2>
          <button onClick={onClose} className="p-1 rounded-md hover:bg-muted" aria-label="Fermer">
            <X className="h-4 w-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-bold mb-1">Type d'action *</label>
            <Select value={type} onChange={(e) => setType(e.target.value)}>
              <optgroup label="Résultat de l'appel">
                {STATUS_TYPES.map((t) => {
                  const disabled = isDisabled(t)
                  return (
                    <option key={t.value} value={t.value} disabled={disabled}>
                      {t.label}{disabled ? disabledReason(t) : ''}
                    </option>
                  )
                })}
              </optgroup>
              <optgroup label="Notes">
                {NOTE_TYPES.map((t) => (
                  <option key={t.value} value={t.value}>{t.label}</option>
                ))}
              </optgroup>
            </Select>
            {hasReservation ? (
              <p className="text-xs text-emerald-600 dark:text-emerald-400 mt-1">
                Votre réservation est active.
              </p>
            ) : reservedByName ? (
              <p className="text-xs text-amber-600 dark:text-amber-400 mt-1">
                Réservé par {reservedByName}. Réservation requise pour changer le statut d'appel.
              </p>
            ) : (
              <p className="text-xs text-muted-foreground mt-1">
                Réservez ce client pour changer son statut d'appel.
              </p>
            )}
          </div>

          {isVoicemail && (
            <div>
              <label className="block text-sm font-bold mb-1">Rappel dans *</label>
              <div className="flex items-center gap-2">
                <Input
                  type="number"
                  min={1}
                  value={recallAmount}
                  onChange={(e) => setRecallAmount(Number(e.target.value))}
                  className="w-24"
                />
                <Select value={recallUnit} onChange={(e) => setRecallUnit(e.target.value)}>
                  {RECALL_UNITS.map((u) => (
                    <option key={u.value} value={u.value}>{u.label}</option>
                  ))}
                </Select>
              </div>
            </div>
          )}

          {isTask && (
            <div>
              <label className="block text-sm font-bold mb-1">Date d'échéance</label>
              <Input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
            </div>
          )}

          {isCallLog && (
            <div>
              <label className="block text-sm font-bold mb-1">Durée de l'appel (secondes)</label>
              <Input
                type="number"
                min={0}
                value={callDuration}
                onChange={(e) => setCallDuration(e.target.value)}
                placeholder="Ex: 180"
              />
            </div>
          )}

          <div>
            <label className="block text-sm font-bold mb-1">
              {noteRequired ? 'Contenu *' : 'Note (optionnel)'}
            </label>
            <textarea
              value={content}
              onChange={(e) => setContent(e.target.value)}
              rows={4}
              placeholder={noteRequired ? 'Décrivez votre note...' : 'Ajoutez un commentaire...'}
              className={cn(
                'flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background',
                'placeholder:text-muted-foreground',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
              )}
            />
          </div>

          {error && (
            <div className="flex items-start gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
              <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
              <span>{error}</span>
            </div>
          )}

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={onClose} disabled={pending}>
              Annuler
            </Button>
            <Button type="submit" disabled={pending}>
              {pending && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
              Confirmer
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}
