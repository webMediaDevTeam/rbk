import { AlertCircle, CheckCircle2, Loader2, Lock, Mail, ShieldCheck } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import Input from '@/components/ui/input.jsx'
import {
  InputOTP,
  InputOTPGroup,
  InputOTPSlot,
} from '@/components/ui/input-otp.jsx'
import { useSecuritySection } from './useSecuritySection.js'

export default function SecuritySection({ user }) {
  const {
    step,
    otp,
    onOtpChange,
    password,
    onPasswordChange,
    confirmation,
    onConfirmationChange,
    error,
    canResendOtp,
    email,
    isBusy,
    sendOtpPending,
    verifyOtpPending,
    updatePasswordPending,
    otpSubmitDisabled,
    requestOtp,
    verifyOtp,
    updatePassword,
  } = useSecuritySection({ user })

  return (
    <div className="space-y-6 max-w-2xl">
      <div>
        <h2 className="text-lg font-semibold text-foreground">Sécurité & mot de passe</h2>
        <p className="text-sm text-muted-foreground">
          Recevez un code OTP par email avant de modifier votre mot de passe.
        </p>
      </div>

      <div className="border-b border-border" />

      <div className="rounded-xl border border-border p-5 space-y-5">
        <div className="flex items-start gap-4">
          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted">
            {step === 'password' ? (
              <CheckCircle2 className="h-5 w-5 text-emerald-600" />
            ) : (
              <Mail className="h-5 w-5 text-muted-foreground" />
            )}
          </div>
          <div className="space-y-1">
            <h3 className="text-sm font-semibold text-foreground">Modification protégée par OTP</h3>
            <p className="text-xs text-muted-foreground">
              Le code sera envoyé à {email}. Les champs de mot de passe restent verrouillés tant que le code n’est pas vérifié.
            </p>
          </div>
        </div>

        {error && (
          <div className="flex items-start gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
            <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
            <span>{error}</span>
          </div>
        )}

        {step === 'idle' && (
          <Button type="button" onClick={requestOtp} disabled={isBusy}>
            {sendOtpPending ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin" />
                Envoi…
              </>
            ) : (
              <>
                <Mail className="h-4 w-4" />
                Mettre à jour le mot de passe
              </>
            )}
          </Button>
        )}

        {step === 'otp' && (
          <form onSubmit={verifyOtp} className="space-y-4">
            <div className="space-y-2">
              <label className="block text-sm font-medium text-foreground">Code OTP</label>
              <InputOTP maxLength={6} value={otp} onChange={onOtpChange} disabled={isBusy}>
                <InputOTPGroup>
                  {[0, 1, 2, 3, 4, 5].map((index) => (
                    <InputOTPSlot key={index} index={index} />
                  ))}
                </InputOTPGroup>
              </InputOTP>
            </div>

            <div className="flex flex-wrap gap-3">
              <Button type="submit" disabled={otpSubmitDisabled}>
                {verifyOtpPending ? (
                  <>
                    <Loader2 className="h-4 w-4 animate-spin" />
                    Vérification…
                  </>
                ) : (
                  'Vérifier le code'
                )}
              </Button>

              {canResendOtp && (
                <Button type="button" variant="outline" onClick={requestOtp} disabled={isBusy}>
                  {sendOtpPending ? (
                    <>
                      <Loader2 className="h-4 w-4 animate-spin" />
                      Renvoi…
                    </>
                  ) : (
                    'Renvoyer le code'
                  )}
                </Button>
              )}
            </div>
          </form>
        )}

        {step === 'password' && (
          <form onSubmit={updatePassword} className="space-y-6">
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
                />
              </div>
            </div>

            <div className="space-y-2">
              <label className="block text-sm font-medium text-foreground">Confirmer le mot de passe</label>
              <Input
                type="password"
                value={confirmation}
                onChange={onConfirmationChange}
                placeholder="••••••••"
                disabled={isBusy}
              />
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
        )}
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