import { AlertCircle, Loader2, X } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import Input from '@/components/ui/input.jsx'
import Select from '@/components/ui/select.jsx'
import Switch from '@/components/ui/switch.jsx'
import UsernameField from '@/pages/shared/components/UsernameField.jsx'
import { useCommercialUpdateModal } from './useCommercialUpdateModal.js'

export default function CommercialUpdateModal({ open, onClose, user, queryKey }) {
  const {
    form, error, isPending, enterprises, set, handleSubmit,
    usernameCheck, onEmailChange, onUsernameChange,
    devices, selectedDeviceValue, devicesLoading, devicesUnavailable, onDeviceChange, onEnterpriseChange,
  } = useCommercialUpdateModal({ open, onClose, user, queryKey })

  if (!open || !user) return null

  return (
    <div className="fixed inset-0 z-100 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-lg font-semibold text-foreground">Modifier l'employé</h2>
          <button onClick={onClose} className="p-1 rounded-md hover:bg-muted" aria-label="Fermer">
            <X className="h-4 w-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-bold mb-1">Adresse e-mail *</label>
            <Input type="email" required value={form.email} onChange={(e) => onEmailChange(e.target.value)} />
          </div>

          {/* Login (nom d'utilisateur) : déduit de l'e-mail, éditable,
              unicité vérifiée en direct pendant la saisie
              (« déjà pris » = envoi bloqué). */}
          <UsernameField value={form.username} onChange={onUsernameChange} check={usernameCheck} />

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-bold mb-1">Prénom *</label>
              <Input type="text" required value={form.first_name} onChange={(e) => set('first_name', e.target.value)} />
            </div>
            <div>
              <label className="block text-sm font-bold mb-1">Nom *</label>
              <Input type="text" required value={form.last_name} onChange={(e) => set('last_name', e.target.value)} />
            </div>
          </div>

          <div>
            <label className="block text-sm font-bold mb-1">Entreprise *</label>
            <Select required value={form.enterprise_id} onChange={(e) => onEnterpriseChange(e.target.value)}>
              <option value="">Sélectionner une entreprise</option>
              {enterprises.map((ent) => (
                <option key={ent.id} value={ent.id}>{ent.name ?? ent.profil?.nom ?? ent.email}</option>
              ))}
            </Select>
          </div>

          <div>
            <label className="block text-sm font-bold mb-1">Appareil / numéro source (RingCentral)</label>
            <Select
              value={selectedDeviceValue}
              onChange={(e) => onDeviceChange(e.target.value)}
              disabled={devicesLoading}
            >
              <option value="">
                {devicesLoading
                  ? 'Chargement des appareils…'
                  : !form.enterprise_id
                    ? '— Choisir une entreprise d\'abord —'
                    : devices.length
                      ? '— Sélectionner un appareil / numéro —'
                      : '— aucun appareil disponible —'}
              </option>
              {devices.map((d) => (
                <option key={d.value} value={d.value}>{d.label}</option>
              ))}
            </Select>
            <p className="mt-1 text-xs text-muted-foreground">
              Chaque numéro et appareil est un choix distinct; le numéro choisi sera enregistré comme source.
              Le poste RingCentral est déduit de l'appareil choisi.
              Les appareils viennent du compte RingCentral de l'entreprise sélectionnée.
              {devicesUnavailable && ' Appareils indisponibles pour le moment.'}
            </p>
          </div>

          {/* Privilège commercial (ex-« Privilège de libération ») —
              `users.has_permission` : ouvre la liste noire et la libération
              de liste au COMERCIAL (403 sans, middleware CheckPermission,
              docs/RULES.md §7.1). Édition : état initial =
              `user.has_permission`. */}
          <div className="flex items-center justify-between gap-4 rounded-lg border border-border bg-muted/30 p-3">
            <div>
              <p className="text-sm font-bold">Privilège commercial</p>
              <p className="text-xs text-muted-foreground">
                Autorise ce commercial à mettre en liste noire un client et à libérer ses réservations.
              </p>
            </div>
            <Switch
              checked={form.has_permission}
              onCheckedChange={(checked) => set('has_permission', checked)}
              aria-label="Privilège commercial"
            />
          </div>

          {/* Mot de passe + confirmation : les deux derniers champs du
              formulaire, affichés en clair. Laisser le mot de passe vide →
              inchangé. Le « login » lui-même est le champ Login ci-dessus. */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-bold mb-1">Nouveau mot de passe (optionnel)</label>
              <Input type="text" minLength={8} autoComplete="new-password" value={form.mot_de_passe} onChange={(e) => set('mot_de_passe', e.target.value)} />
            </div>
            <div>
              <label className="block text-sm font-bold mb-1">Confirmer le mot de passe</label>
              <Input type="text" minLength={8} autoComplete="new-password" value={form.mot_de_passe_confirmation} onChange={(e) => set('mot_de_passe_confirmation', e.target.value)} />
            </div>
          </div>

          {error && (
            <div className="flex items-start gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
              <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
              <span>{error}</span>
            </div>
          )}

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={onClose} disabled={isPending}>Annuler</Button>
            <Button type="submit" disabled={isPending || usernameCheck.taken || !usernameCheck.valid || usernameCheck.checking}>
              {isPending && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
              Enregistrer
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}
