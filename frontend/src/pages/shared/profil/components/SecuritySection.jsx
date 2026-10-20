import { AlertCircle, Info, Loader2, Lock, ShieldCheck } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import Input from '@/components/ui/input.jsx'
import { useSecuritySection } from './useSecuritySection.js'

export default function SecuritySection({ user, role }) {
  const isComercial = role === 'COMERCIAL'
  const {
    currentPassword,
    onCurrentPasswordChange,
    password,
    onPasswordChange,
    confirmation,
    onConfirmationChange,
    error,
    isBusy,
    updatePasswordPending,
    updatePassword,
  } = useSecuritySection()

  // COMERCIAL : pas d'auto-modification du mot de passe, passage par un admin.
  if (isComercial) {
    return (
      <div className="space-y-6 max-w-2xl">
        <div>
          <h2 className="text-lg font-semibold text-foreground">Sécurité & mot de passe</h2>
          <p className="text-sm text-muted-foreground">
            Gestion de la sécurité de votre compte.
          </p>
        </div>

        <div className="border-b border-border" />

        <div className="rounded-xl border border-border p-5">
          <div className="flex items-start gap-4">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10">
              <Info className="h-5 w-5 text-primary" />
            </div>
            <div className="space-y-1">
              <h3 className="text-sm font-semibold text-foreground">Modification du mot de passe</h3>
              <p className="text-sm text-muted-foreground">
                Bonjour {user?.first_name || user?.email} 👋<br />
                En tant que commercial, vous ne pouvez pas modifier vous-même votre
                mot de passe. Merci de demander à votre administrateur de le changer
                pour vous.
              </p>
            </div>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6 max-w-2xl">
      <div>
        <h2 className="text-lg font-semibold text-foreground">Sécurité & mot de passe</h2>
        <p className="text-sm text-muted-foreground">
          Modifiez votre mot de passe en confirmant votre mot de passe actuel.
        </p>
      </div>

      <div className="border-b border-border" />

      <div className="rounded-xl border border-border p-5 space-y-5">
        <div className="flex items-start gap-4">
          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted">
            <Lock className="h-5 w-5 text-muted-foreground" />
          </div>
          <div className="space-y-1">
            <h3 className="text-sm font-semibold text-foreground">Modifier votre mot de passe</h3>
            <p className="text-xs text-muted-foreground">
              Saisissez votre mot de passe actuel, puis votre nouveau mot de passe
              (8 caractères minimum) et sa confirmation.
            </p>
          </div>
        </div>

        {error && (
          <div className="flex items-start gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
            <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
            <span>{error}</span>
          </div>
        )}

        <form onSubmit={updatePassword} className="space-y-6">
          <div className="space-y-2">
            <label className="block text-sm font-medium text-foreground">Mot de passe actuel</label>
            <div className="relative">
              <Lock className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                type="password"
                value={currentPassword}
                onChange={onCurrentPasswordChange}
                placeholder="••••••••"
                className="pl-9"
                disabled={isBusy}
                autoComplete="current-password"
              />
            </div>
          </div>

          <div className="space-y-2">
            <label className="block text-sm font-medium text-foreground">Nouveau mot de passe</label>
            <div className="relative">
              <Lock className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                type="password"
                value={password}
                onChange={onPasswordChange}
                placeholder="••••••••"
                className="pl-9"
                disabled={isBusy}
                autoComplete="new-password"
              />
            </div>
          </div>

          <div className="space-y-2">
            <label className="block text-sm font-medium text-foreground">Confirmer le mot de passe</label>
            <div className="relative">
              <Lock className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                type="password"
                value={confirmation}
                onChange={onConfirmationChange}
                placeholder="••••••••"
                className="pl-9"
                disabled={isBusy}
                autoComplete="new-password"
              />
            </div>
          </div>

          <Button type="submit" disabled={isBusy}>
            {updatePasswordPending ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin" />
                Mise à jour…
              </>
            ) : (
              'Enregistrer le nouveau mot de passe'
            )}
          </Button>
        </form>
      </div>

      <div className="pt-4">
        <div className="rounded-xl border border-border p-5">
          <div className="flex items-start gap-4">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted">
              <ShieldCheck className="h-5 w-5 text-muted-foreground" />
            </div>
            <div className="space-y-1">
              <h3 className="text-sm font-semibold text-foreground">Validation en deux étapes (2FA)</h3>
              <p className="text-xs text-muted-foreground">
                Ajoutez une couche de sécurité supplémentaire à votre compte. Cette fonctionnalité sera
                bientôt disponible.
              </p>
            </div>
          </div>
          <div className="mt-4 flex items-center justify-between rounded-lg bg-muted/50 px-4 py-3">
            <span className="text-sm font-medium text-foreground">Authentification à deux facteurs</span>
            <span className="inline-flex items-center rounded-full border border-border bg-card px-2.5 py-0.5 text-xs font-semibold text-muted-foreground">
              Bientôt disponible
            </span>
          </div>
        </div>
      </div>
    </div>
  )
}
