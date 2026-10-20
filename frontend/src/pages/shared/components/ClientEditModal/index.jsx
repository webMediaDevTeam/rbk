import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertCircle, Loader2, X } from 'lucide-react'
import { toast } from 'sonner'
import MDEditor from '@uiw/react-md-editor'
import '@uiw/react-md-editor/markdown-editor.css'
import Button from '@/components/ui/button.jsx'
import Select from '@/components/ui/select'
import { cn } from '@/lib/utils.js'
import { adminUpdateClientApi } from '@/api/commercial.api.js'
import { listClientSourcesApi } from '@/api/shared.api.js'

/**
 * Modale **« Modifier le client »** (Admin / Super Admin) —
 * `PUT commercials/clients/{id}`.
 *
 * Ouvre la fiche entière en édition : identité, contact, adresse, licence et
 * **source** (répertoire `clients.source`). Elle remplace l'ancienne saisie
 * limitée au seul téléphone dans la Grande liste — le téléphone n'est plus
 * qu'un champ parmi d'autres.
 *
 * Comme la saisie de numéro, l'enregistrement positionne `is_manually_updated`
 * côté serveur : l'import scraper / n8n ne réécrira plus cette fiche. Toutes
 * les vues portant une ligne ou un compteur de ce client sont invalidées.
 */
const EMPTY = {
  name: '',
  enterprise_name: '',
  source: '',
  representative_name: '',
  intervenant_name: '',
  neq: '',
  phone: '',
  email: '',
  full_address: '',
  municipality: '',
  administrative_region: '',
  licence_number: '',
  licence_end_date: '',
  // Note libre multi-lignes (`clients.note_personnalise`) : chaque retour à
  // la ligne forme un élément de liste à l'affichage.
  note_personnalise: '',
}

// `YYYY-MM-DD` attendu par `<input type="date">` (la base renvoie parfois un
// datetime complet).
const toDateInput = (value) => (value ? String(value).slice(0, 10) : '')

const FIELD_CLASS = cn(
  'flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background',
  'placeholder:text-muted-foreground',
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
)

function Field({ label, htmlFor, className, ...props }) {
  return (
    <div className={className}>
      <label htmlFor={htmlFor} className="block text-sm font-bold mb-1">
        {label}
      </label>
      <input id={htmlFor} className={FIELD_CLASS} {...props} />
    </div>
  )
}

export default function ClientEditModal({ open, client, onClose }) {
  const qc = useQueryClient()
  const [form, setForm] = useState(EMPTY)
  const [error, setError] = useState(null)

  const clientId = client?.id ?? null

  const { data: sourcesData } = useQuery({
    queryKey: ['client-sources'],
    queryFn: () => listClientSourcesApi(),
    enabled: open,
    staleTime: 1000 * 60 * 60,
    retry: false,
  })
  const sources = Array.isArray(sourcesData?.data) ? sourcesData.data : []

  // Réinitialisation à chaque ouverture / changement de client.
  useEffect(() => {
    if (open && client) {
      setError(null)
      setForm({
        name: client.name ?? '',
        enterprise_name: client.enterprise_name ?? '',
        source: client.source ?? '',
        representative_name: client.representative_name ?? '',
        intervenant_name: client.intervenant_name ?? '',
        neq: client.neq ?? '',
        phone: client.phone ?? '',
        email: client.email ?? '',
        full_address: client.full_address ?? '',
        municipality: client.municipality ?? '',
        administrative_region: client.administrative_region ?? '',
        licence_number: client.licence_number ?? '',
        licence_end_date: toDateInput(client.licence_end_date),
        note_personnalise: client.note_personnalise ?? '',
      })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, clientId])

  const mutation = useMutation({
    mutationFn: () => {
      const payload = {}
      Object.entries(form).forEach(([key, value]) => {
        payload[key] = typeof value === 'string' ? value.trim() : value
      })
      return adminUpdateClientApi(clientId, payload)
    },
    onSuccess: (res) => {
      toast.success(res?.data?.message || 'Fiche client mise à jour.')
      qc.invalidateQueries({ queryKey: ['admin-clients-history'] })
      qc.invalidateQueries({ queryKey: ['prospect-kpis'] })
      qc.invalidateQueries({ queryKey: ['commercial-prospects'] })
      qc.invalidateQueries({ queryKey: ['admin-client'] })
      qc.invalidateQueries({ queryKey: ['commercial-prospect'] })
      qc.invalidateQueries({ queryKey: ['dashboard-stats'] })
      onClose()
    },
    onError: (err) => {
      const msg = err?.response?.data?.message || 'Une erreur est survenue.'
      setError(msg)
      toast.error(msg)
    },
  })

  if (!open || !client) return null

  const set = (key) => (e) => setForm((prev) => ({ ...prev, [key]: e.target.value }))

  // La source courante peut être absente du répertoire : on l'ajoute pour ne
  // jamais perdre la valeur enregistrée.
  const sourceOptions = form.source && !sources.includes(form.source)
    ? [form.source, ...sources]
    : sources

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div
        className="relative flex max-h-[92vh] w-full max-w-6xl flex-col rounded-2xl bg-card p-6 shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-lg font-semibold text-foreground">Modifier le client</h2>
          <button onClick={onClose} className="p-1 rounded-md hover:bg-muted" aria-label="Fermer">
            <X className="h-4 w-4" />
          </button>
        </div>

        <form id="client-edit-form" onSubmit={(e) => { e.preventDefault(); setError(null); mutation.mutate() }} className="flex-1 overflow-y-auto pr-1">
          {/* Modèle « ancienne version » pour les champs (sections en 2
              colonnes), mais la modale est une **grille de 3 colonnes** :
              les champs occupent les 2 premières, la note personnalisée
              occupe la 3e sur **toute la hauteur** — vrai éditeur de texte
              (`@uiw/react-md-editor`), valeur en texte brut : les retours à
              la ligne restent le séparateur des éléments de liste. */}
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div className="space-y-5 lg:col-span-2">
              {/* Identité */}
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Nom" htmlFor="client-name" value={form.name} onChange={set('name')} placeholder="Nom du prospect" autoFocus />
                <Field label="Entreprise" htmlFor="client-enterprise" value={form.enterprise_name} onChange={set('enterprise_name')} placeholder="Raison sociale" />
                <div>
                  <label htmlFor="client-source" className="block text-sm font-bold mb-1">Source</label>
                  <Select id="client-source" value={form.source} onChange={set('source')} className="cursor-pointer">
                    <option value="">— Aucune —</option>
                    {sourceOptions.map((s) => (<option key={s} value={s}>{s}</option>))}
                  </Select>
                </div>
                <Field label="Représentant" htmlFor="client-representative" value={form.representative_name} onChange={set('representative_name')} placeholder="Personne de contact" />
                <Field label="Intervenant" htmlFor="client-intervenant" value={form.intervenant_name} onChange={set('intervenant_name')} placeholder="Intervenant" />
                <Field label="NEQ" htmlFor="client-neq" value={form.neq} onChange={set('neq')} placeholder="Numéro d'entreprise (QB)" />
              </div>

              {/* Contact */}
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Téléphone" htmlFor="client-phone" type="tel" inputMode="tel" value={form.phone} onChange={set('phone')} placeholder="514 555-0100" />
                <Field label="Courriel" htmlFor="client-email" type="email" value={form.email} onChange={set('email')} placeholder="info@exemple.com" />
                <Field label="Adresse" htmlFor="client-address" className="sm:col-span-2" value={form.full_address} onChange={set('full_address')} placeholder="Adresse complète" />
                <Field label="Municipalité" htmlFor="client-municipality" value={form.municipality} onChange={set('municipality')} placeholder="Québec" />
                <Field label="Région administrative" htmlFor="client-region" value={form.administrative_region} onChange={set('administrative_region')} placeholder="—" />
              </div>

              {/* Licence */}
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Numéro de licence" htmlFor="client-licence" value={form.licence_number} onChange={set('licence_number')} placeholder="—" />
                <Field label="Fin de licence" htmlFor="client-licence-end" type="date" value={form.licence_end_date} onChange={set('licence_end_date')} />
              </div>
            </div>

            {/* Note personnalisée — 3e colonne, pleine hauteur. */}
            <div className="client-note-editor flex flex-col" data-color-mode="light">
              <label htmlFor="client-note-personnalise" className="block text-sm font-bold mb-1">
                Note personnalisée
              </label>
              <MDEditor
                textareaProps={{ id: 'client-note-personnalise', placeholder: 'Une information par ligne…' }}
                value={form.note_personnalise}
                onChange={(value) => setForm((prev) => ({ ...prev, note_personnalise: value ?? '' }))}
                preview="edit"
                height={640}
              />
              <p className="mt-1 text-xs text-muted-foreground">
                Chaque retour à la ligne forme un élément de la liste.
              </p>
            </div>
          </div>

          {error && (
            <div className="mt-4 flex items-start gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
              <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
              <span>{error}</span>
            </div>
          )}
        </form>

        <div className="mt-5 flex justify-end gap-2 border-t border-border pt-4">
          <Button type="button" variant="secondary" onClick={onClose} disabled={mutation.isPending}>
            Annuler
          </Button>
          <Button type="submit" form="client-edit-form" disabled={mutation.isPending}>
            {mutation.isPending && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
            Enregistrer
          </Button>
        </div>
      </div>
    </div>
  )
}
