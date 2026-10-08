import { AlertCircle, Loader2, X } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import Input from '@/components/ui/input.jsx'
import Select from '@/components/ui/select.jsx'
import { useEnterpriseCreateModal } from './useEnterpriseCreateModal.js'

export default function EnterpriseCreateModal({ open, onClose, queryKey }) {
  const {
    form, error, isPending, set, handleSubmit,
    sourceOptions, sourcesLoading, sourcesUnavailable,
  } = useEnterpriseCreateModal({ open, onClose, queryKey })

  if (!open) return null

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-lg font-semibold text-foreground">Créer une entreprise</h2>
          <button onClick={onClose} className="p-1 rounded-md hover:bg-muted" aria-label="Fermer">
            <X className="h-4 w-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-bold mb-1">Nom de l'entreprise *</label>
              <Input
                type="text"
                required
                value={form.name}
                onChange={(e) => set('name', e.target.value)}
                placeholder="Ex: Construction Boréal inc."
              />
            </div>
            <div>
              <label className="block text-sm font-bold mb-1">Adresse e-mail</label>
              <Input
                type="email"
                value={form.email}
                onChange={(e) => set('email', e.target.value)}
                placeholder="contact@entreprise.com"
              />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-bold mb-1">Téléphone</label>
              <Input
                type="tel"
                value={form.phone}
                onChange={(e) => set('phone', e.target.value)}
                placeholder="418-555-0100"
              />
            </div>
            <div>
              <label className="block text-sm font-bold mb-1">Numéro fiscal (NIF / NEQ)</label>
              <Input
                type="text"
                value={form.tax_number}
                onChange={(e) => set('tax_number', e.target.value)}
                placeholder="Ex: 1149283746"
              />
            </div>
          </div>

          <div>
            <label className="block text-sm font-bold mb-1">Adresse physique</label>
            <Input
              type="text"
              value={form.address}
              onChange={(e) => set('address', e.target.value)}
              placeholder="123 rue Principale, Québec, QC"
            />
          </div>

          {/* Source : **choix dans la table `sources`** (`GET /sources`,
              répertoire fermé sans CRUD) — pas lié à RingCentral. */}
          <div>
            <label className="block text-sm font-bold mb-1">Source</label>
            <Select value={form.source} onChange={(e) => set('source', e.target.value)}>
              <option value="">— Aucune source —</option>
              {sourceOptions.map((s) => (
                <option key={s.id} value={s.name}>{s.name}</option>
              ))}
            </Select>
            {sourcesLoading && (
              <p className="mt-1 text-xs text-muted-foreground">Chargement des sources…</p>
            )}
            {sourcesUnavailable && (
              <p className="mt-1 text-xs text-destructive">Sources indisponibles pour le moment.</p>
            )}
          </div>

          {/* ── RingCentral (optionnel) ──────────────────────────────────
              Compte propre de l'entreprise : la sélection « Appareil /
              numéro source » des modales employé liste alors SES
              appareils. Tout vide → repli sur `.env`
              (`Enterprise::getRingCentralCredentials()`). */}
          <div className="space-y-3 rounded-lg border border-border bg-muted/30 p-3">
            <div>
              <p className="text-sm font-bold">RingCentral — compte de l'entreprise (optionnel)</p>
              <p className="text-xs text-muted-foreground">
                Champs vides = compte RingCentral par défaut (.env). Le secret et le jeton
                ne sont jamais affichés après enregistrement.
              </p>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-bold mb-1">Client ID</label>
                <Input
                  type="text"
                  autoComplete="off"
                  spellCheck={false}
                  value={form.ringcentral_client_id}
                  onChange={(e) => set('ringcentral_client_id', e.target.value)}
                  placeholder="RINGCENTRAL_CLIENT_ID"
                />
              </div>
              <div>
                <label className="block text-sm font-bold mb-1">Client Secret</label>
                <Input
                  type="password"
                  autoComplete="new-password"
                  value={form.ringcentral_client_secret}
                  onChange={(e) => set('ringcentral_client_secret', e.target.value)}
                  placeholder="••••••••••••"
                />
              </div>
              <div>
                <label className="block text-sm font-bold mb-1">Token</label>
                <Input
                  type="password"
                  autoComplete="new-password"
                  value={form.ringcentral_token}
                  onChange={(e) => set('ringcentral_token', e.target.value)}
                  placeholder="JWT RingCentral"
                />
              </div>
            </div>
          </div>

          {error && (
            <div className="flex items-start gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
              <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
              <span>{error}</span>
            </div>
          )}

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={onClose} disabled={isPending}>
              Annuler
            </Button>
            <Button type="submit" disabled={isPending}>
              {isPending && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
              Enregistrer
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}