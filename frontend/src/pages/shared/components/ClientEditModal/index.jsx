import { useEffect, useState } from 'react'
import { AlertCircle, Loader2, Save, X } from 'lucide-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import Button from '@/components/ui/button.jsx'
import Input from '@/components/ui/input.jsx'
import { Textarea } from '@/components/ui/textarea.jsx'
import { updateCommercialClientApi } from '@/api/commercial.api.js'

const INVALIDATIONS = [
  ['commercial-prospect'],
  ['commercial-prospects'],
  ['reservation-group'],
  ['reservation-groups'],
  ['prospect-kpis'],
  ['active-reservations-count'],
]

const formatList = (value) => (Array.isArray(value)
  ? value.map((item) => {
    if (typeof item === 'string') return item
    return item?.label ?? item?.name ?? ''
  }).filter(Boolean).join('\n')
  : '')

const formatRespondents = (value) => (Array.isArray(value)
  ? value.map((item) => {
    if (typeof item === 'string') return item
    return [item?.name, item?.role].filter(Boolean).join(' | ')
  }).filter(Boolean).join('\n')
  : '')

const dateValue = (value) => (value ? String(value).slice(0, 10) : '')

function initialValues(client) {
  return {
    name: client.name ?? '',
    enterprise_name: client.enterprise_name ?? '',
    phone: client.phone ?? '',
    email: client.email ?? '',
    representative_name: client.representative_name ?? '',
    full_address: client.full_address ?? '',
    municipality: client.municipality ?? '',
    administrative_region: client.administrative_region ?? '',
    neq: client.neq ?? '',
    licence_number: client.licence_number ?? '',
    licence_propre_numero: client.licence_propre_numero ?? '',
    licence_propre: Boolean(client.licence_propre),
    licence_status: client.licence_status ?? '',
    licence_start_date: dateValue(client.licence_start_date),
    licence_end_date: dateValue(client.licence_end_date),
    respondents: formatRespondents(client.respondents),
    respondent_count: client.respondent_count ?? '',
    sub_category_count: client.sub_category_count ?? '',
    authorized_categories: formatList(client.authorized_categories),
    surety_company: client.surety_company ?? '',
    cautionnement_compagnie: formatList(client.cautionnement_compagnie),
    surety_amount: client.surety_amount ?? '',
  }
}

function Field({ label, name, value, onChange, type = 'text', ...props }) {
  return (
    <label className="space-y-1.5 text-sm">
      <span className="font-medium text-foreground">{label}</span>
      <Input name={name} type={type} value={value} onChange={onChange} {...props} />
    </label>
  )
}

function TextField({ label, name, value, onChange, ...props }) {
  return (
    <label className="space-y-1.5 text-sm">
      <span className="font-medium text-foreground">{label}</span>
      <Textarea name={name} value={value} onChange={onChange} rows={3} {...props} />
    </label>
  )
}

function Section({ title, children }) {
  return (
    <section className="space-y-3 border-t border-border pt-4">
      <h3 className="text-sm font-semibold text-foreground">{title}</h3>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">{children}</div>
    </section>
  )
}

export default function ClientEditModal({ open, client, onClose }) {
  const queryClient = useQueryClient()
  const [values, setValues] = useState(() => (client ? initialValues(client) : {}))
  const [error, setError] = useState(null)

  useEffect(() => {
    if (open && client) {
      setValues(initialValues(client))
      setError(null)
    }
  }, [open, client])

  const mutation = useMutation({
    mutationFn: (payload) => updateCommercialClientApi(client.id, payload),
    onSuccess: () => {
      toast.success('Fiche client enregistrée.')
      INVALIDATIONS.forEach((queryKey) => queryClient.invalidateQueries({ queryKey }))
      onClose()
    },
    onError: (err) => {
      const validationError = Object.values(err?.response?.data?.errors ?? {})[0]?.[0]
      const message = validationError || err?.response?.data?.message || 'Impossible d’enregistrer la fiche.'
      setError(message)
      toast.error(message)
    },
  })

  if (!open || !client) return null

  const change = (event) => {
    const { name, value, type, checked } = event.target
    setValues((current) => ({ ...current, [name]: type === 'checkbox' ? checked : value }))
  }

  const splitLines = (value) => value.split('\n').map((line) => line.trim()).filter(Boolean)

  const submit = (event) => {
    event.preventDefault()
    setError(null)
    const respondents = splitLines(values.respondents).map((line) => {
      const [name, ...roles] = line.split('|').map((part) => part.trim())
      return roles.length ? { name, role: roles.join(' | ') } : name
    })
    const payload = {
      ...values,
      respondents,
      authorized_categories: splitLines(values.authorized_categories),
      categories: splitLines(values.authorized_categories),
      cautionnement_compagnie: splitLines(values.cautionnement_compagnie),
      licence_propre_numero: values.licence_propre_numero === '' ? null : Number(values.licence_propre_numero),
      respondent_count: values.respondent_count === '' ? null : Number(values.respondent_count),
      sub_category_count: values.sub_category_count === '' ? null : Number(values.sub_category_count),
      surety_amount: values.surety_amount === '' ? null : Number(values.surety_amount),
    }
    mutation.mutate(payload)
  }

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-3 sm:p-5" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="client-edit-title"
        className="relative flex max-h-[92vh] w-full max-w-4xl flex-col rounded-lg bg-card text-card-foreground shadow-xl"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="flex items-center justify-between border-b border-border px-5 py-4">
          <div>
            <h2 id="client-edit-title" className="text-lg font-semibold">Modifier la fiche client</h2>
            <p className="text-sm text-muted-foreground">{client.enterprise_name || client.name}</p>
          </div>
          <button type="button" onClick={onClose} aria-label="Fermer" className="rounded p-1.5 text-muted-foreground hover:bg-muted">
            <X className="h-4 w-4" />
          </button>
        </div>

        <form onSubmit={submit} className="min-h-0 space-y-5 overflow-y-auto px-5 py-4">
          <Section title="Contact">
            <Field label="Nom affiché" name="name" value={values.name ?? ''} onChange={change} />
            <Field label="Entreprise" name="enterprise_name" value={values.enterprise_name ?? ''} onChange={change} />
            <Field label="Téléphone" name="phone" type="tel" value={values.phone ?? ''} onChange={change} />
            <Field label="Courriel" name="email" type="email" value={values.email ?? ''} onChange={change} />
            <Field label="Représentant" name="representative_name" value={values.representative_name ?? ''} onChange={change} />
            <Field label="NEQ" name="neq" value={values.neq ?? ''} onChange={change} />
            <div className="sm:col-span-2">
              <TextField label="Adresse complète" name="full_address" value={values.full_address ?? ''} onChange={change} />
            </div>
            <Field label="Municipalité" name="municipality" value={values.municipality ?? ''} onChange={change} />
            <Field label="Région administrative" name="administrative_region" value={values.administrative_region ?? ''} onChange={change} />
          </Section>

          <Section title="Licence">
            <Field label="Numéro de licence" name="licence_number" value={values.licence_number ?? ''} onChange={change} />
            <Field label="Licence (propre) numéro" name="licence_propre_numero" type="number" min="0" value={values.licence_propre_numero ?? ''} onChange={change} />
            <Field label="Statut de licence" name="licence_status" value={values.licence_status ?? ''} onChange={change} />
            <label className="flex items-center gap-2 self-end pb-2 text-sm">
              <input type="checkbox" name="licence_propre" checked={Boolean(values.licence_propre)} onChange={change} />
              Licence propre
            </label>
            <Field label="Date de début" name="licence_start_date" type="date" value={values.licence_start_date ?? ''} onChange={change} />
            <Field label="Date de fin" name="licence_end_date" type="date" value={values.licence_end_date ?? ''} onChange={change} />
          </Section>

          <Section title="Répondants et catégories">
            <Field label="Nombre de répondants" name="respondent_count" type="number" min="0" value={values.respondent_count ?? ''} onChange={change} />
            <Field label="Nombre de sous-catégories" name="sub_category_count" type="number" min="0" value={values.sub_category_count ?? ''} onChange={change} />
            <div>
              <TextField label="Répondants (un par ligne, rôle après |)" name="respondents" value={values.respondents ?? ''} onChange={change} />
            </div>
            <div>
              <TextField label="Catégories autorisées (une par ligne)" name="authorized_categories" value={values.authorized_categories ?? ''} onChange={change} />
            </div>
          </Section>

          <Section title="Cautionnement">
            <Field label="Compagnie / association" name="surety_company" value={values.surety_company ?? ''} onChange={change} />
            <Field label="Montant ($)" name="surety_amount" type="number" min="0" step="0.01" value={values.surety_amount ?? ''} onChange={change} />
            <div className="sm:col-span-2">
              <TextField label="Compagnies de cautionnement (une par ligne)" name="cautionnement_compagnie" value={values.cautionnement_compagnie ?? ''} onChange={change} />
            </div>
          </Section>

          {error && (
            <div className="flex items-start gap-2 rounded-md bg-destructive/10 p-3 text-sm text-destructive">
              <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
              <span>{error}</span>
            </div>
          )}

          <div className="sticky bottom-0 flex justify-end gap-2 border-t border-border bg-card py-3">
            <Button type="button" variant="secondary" onClick={onClose} disabled={mutation.isPending}>Annuler</Button>
            <Button type="submit" disabled={mutation.isPending}>
              {mutation.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
              Enregistrer
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}