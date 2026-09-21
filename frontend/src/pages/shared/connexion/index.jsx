import { AlertCircle, ArrowRight, Eye, EyeOff, KeyRound, Loader2, Lock, LogIn, Mail } from "lucide-react";
import { InputOTP, InputOTPGroup, InputOTPSlot } from "@/components/ui/input-otp";
import { useConnexionPage } from "./useConnexionPage.js";

const MODES = [
  { id: "password", label: "Par Mot De Passe", icon: Lock },
  { id: "otp", label: "Par Code", icon: Mail },
];

export default function ConnexionPage() {
  const {
    logoSrc,
    mode,
    loginMethod,
    forgotStep,
    loginOtpSent,
    loginModes,
    loginModeActiveIndex,

    email,
    onEmailChange,
    emailError,
    password,
    onPasswordChange,
    passwordError,
    showPassword,
    passwordInputType,
    passwordToggleAriaLabel,
    toggleShowPassword,
    remember,
    onRememberChange,

    visibleError,
    loginOtp,
    onLoginOtpChange,
    loginOtpDisabled,
    sendLoginCode,
    resendLoginCodeDisabled,
    otpResendLabel,
    isAuthBusy,
    submitDisabled,
    busyLabel,
    idleLabel,
    submitLogin,
    openForgot,
    backToLogin,

    localError,
    forgotError,
    resetEmail,
    onResetEmailChange,
    forgotEmailError,
    forgotPending,
    submitForgotEmail,
    forgotOtp,
    onForgotOtpChange,
    forgotOtpDisabled,
    forgotOtpSubmitDisabled,
    verifyForgotOtpPending,
    submitForgotOtp,
    canResendForgotOtp,
    resendForgotOtp,
    newPassword,
    onNewPasswordChange,
    newPasswordConfirmation,
    onNewPasswordConfirmationChange,
    resetPasswordPending,
    submitResetPassword,
  } = useConnexionPage({ modes: MODES });

  return (
    <>
      <div className="auth-card">
        <div className="auth-card-logo">
          <img src={logoSrc} alt="Zdig IA" className="auth-card-logo__img" />
        </div>

        <div className="auth-heading">
          <h1 className="auth-heading__title">
            Bienvenue sur <strong>Zdig IA</strong>
          </h1>
                      {mode !== "connexion"&&
            (<p className="auth-heading__subtitle">Réinitialisez votre mot de passe avec un code envoyé par email.</p>)
              }
        </div>

        {mode === "connexion" && (
          <>
            <div className="auth-switch-badge">
              <LogIn size={14} />
              <span>Connexion</span>
            </div>
            <div
              className="auth-role-switch"
              role="tablist"
              aria-label="Choix du mode de connexion"
              data-active={loginModeActiveIndex}
            >
              {loginModes.map((m) => (
                <button
                  key={m.id}
                  type="button"
                  role="tab"
                  aria-selected={m.isActive}
                  onClick={m.onClick}
                  className={m.className}
                >
                  <m.icon size={14} />
                  {m.label}
                </button>
              ))}
            </div>
          </>
        )}

        {mode === "connexion" && (
          <form className="auth-form" onSubmit={submitLogin}>
            {visibleError && (
              <div className="auth-alert" role="alert">
                <AlertCircle className="auth-alert__icon" size={15} />
                <span>{visibleError}</span>
              </div>
            )}

            <div className="auth-field">
              <label className="auth-field__label" htmlFor="auth-email">
                Courriel Professionnel
              </label>
              <div className="auth-input-wrap">
                <Mail className="auth-input-wrap__icon" size={20} />
                <input
                  id="auth-email"
                  type="email"
                  className="auth-input"
                  placeholder="agent@ZdigIA.ca"
                  value={email}
                  onChange={onEmailChange}
                  required
                />
              </div>
              {emailError && (
                <p className="auth-field__error">{emailError}</p>
              )}
            </div>

            {loginMethod === "password" && (
              <div className="auth-field">
                <label className="auth-field__label" htmlFor="auth-password">
                  Mot de Passe
                </label>
                <div className="auth-input-wrap">
                  <Lock className="auth-input-wrap__icon" size={20} />
                  <input
                    id="auth-password"
                    type={passwordInputType}
                    className="auth-input auth-input--with-action"
                    placeholder="••••••••••••"
                    value={password}
                    onChange={onPasswordChange}
                    required
                  />
                  <button
                    type="button"
                    className="auth-input-wrap__action"
                    onClick={toggleShowPassword}
                    aria-label={passwordToggleAriaLabel}
                  >
                    {showPassword ? <EyeOff size={20} /> : <Eye size={20} />}
                  </button>
                </div>
                {passwordError && <p className="auth-field__error">{passwordError}</p>}
              </div>
            )}

            {loginMethod === "otp" && !loginOtpSent && (
              <div className="auth-field">
                <p className="auth-otp-hint">
                  Entrez votre courriel professionnel puis cliquez sur
                  <strong> « Envoyer le code » </strong>
                  pour recevoir votre code de vérification.
                </p>
              </div>
            )}

            {loginMethod === "otp" && loginOtpSent && (
              <div className="auth-field">
                <label className="auth-field__label">Code de vérification</label>
                <div className="auth-otp-wrap">
                  <InputOTP
                    maxLength={6}
                    value={loginOtp}
                    onChange={onLoginOtpChange}
                    disabled={loginOtpDisabled}
                  >
                    <InputOTPGroup>
                      {[0, 1, 2, 3, 4, 5].map((index) => <InputOTPSlot key={index} index={index} />)}
                    </InputOTPGroup>
                  </InputOTP>
                </div>
                <p className="auth-otp-hint">
                  Un code à 6 chiffres a été envoyé à <strong>{email}</strong>.
                </p>
                <button
                  type="button"
                  className="auth-forgot"
                  onClick={sendLoginCode}
                  disabled={resendLoginCodeDisabled}
                >
                  {otpResendLabel}
                </button>
              </div>
            )}

            <div className="auth-row">
              <label className="auth-check">
                <input
                  type="checkbox"
                  className="auth-check__input"
                  checked={remember}
                  onChange={onRememberChange}
                />
                <span>Se souvenir de moi</span>
              </label>
            </div>

<div className="auth-submit-wrap">
                <button
                  type="submit"
                  className="auth-submit"
                  disabled={submitDisabled}
                >
                  {isAuthBusy ? (
                    <span className="auth-submit__spinner">
                      <Loader2 size={16} className="animate-spin" />
                      {busyLabel}
                    </span>
                  ) : (
                    <span>
                      {idleLabel}
                    </span>
                  )}
                  <span className="auth-submit__arrow" aria-hidden="true">
                    <ArrowRight size={16} />
                  </span>
                </button>
              </div>

            <button type="button" className="auth-forgot-link" onClick={openForgot}>
              Mot de passe oublié ?
            </button>
          </form>
        )}

        {mode === "forgot" && (
          <>
            {localError && (
              <div className="auth-alert" role="alert">
                <AlertCircle className="auth-alert__icon" size={15} />
                <span>{localError}</span>
              </div>
            )}

            {forgotStep === "email" && (
              <form className="auth-form" onSubmit={submitForgotEmail}>
                {forgotError && (
                  <div className="auth-alert" role="alert">
                    <AlertCircle className="auth-alert__icon" size={15} />
                    <span>{forgotError}</span>
                  </div>
                )}

                <div className="auth-field">
                  <label className="auth-field__label" htmlFor="auth-reset-email">
                    Courriel Professionnel
                  </label>
                  <div className="auth-input-wrap">
                    <Mail className="auth-input-wrap__icon" size={20} />
                    <input
                      id="auth-reset-email"
                      type="email"
                      className="auth-input"
                      placeholder="agent@ZdigIA.ca"
                      value={resetEmail}
                      onChange={onResetEmailChange}
                      required
                    />
                  </div>
                  {forgotEmailError && <p className="auth-field__error">{forgotEmailError}</p>}
                </div>

                <div className="auth-submit-wrap">
                  <button type="submit" className="auth-submit" disabled={forgotPending}>
                    {forgotPending ? (
                      <span className="auth-submit__spinner">
                        <Loader2 size={16} className="animate-spin" />
                        Envoi en cours…
                      </span>
                    ) : (
                      <span>Envoyer le code OTP</span>
                    )}
                    <span className="auth-submit__arrow" aria-hidden="true">
                      <KeyRound size={16} />
                    </span>
                  </button>
                </div>
              </form>
            )}

            {forgotStep === "otp" && (
              <form className="auth-form" onSubmit={submitForgotOtp}>
                <div className="auth-field">
                  <label className="auth-field__label">Code de vérification</label>
                  <div className="auth-otp-wrap">
                    <InputOTP maxLength={6} value={forgotOtp} onChange={onForgotOtpChange} disabled={forgotOtpDisabled}>
                      <InputOTPGroup>
                        {[0, 1, 2, 3, 4, 5].map((index) => <InputOTPSlot key={index} index={index} />)}
                      </InputOTPGroup>
                    </InputOTP>
                  </div>
                  <p className="auth-otp-hint">
                    Un code à 6 chiffres a été envoyé à <strong>{resetEmail}</strong>. Vérifiez votre boîte de réception.
                  </p>
                </div>

                <div className="auth-submit-wrap">
                  <button type="submit" className="auth-submit" disabled={forgotOtpSubmitDisabled}>
                    {verifyForgotOtpPending ? (
                      <span className="auth-submit__spinner">
                        <Loader2 size={16} className="animate-spin" />
                        Vérification…
                      </span>
                    ) : (
                      <span>Valider le code</span>
                    )}
                    <span className="auth-submit__arrow" aria-hidden="true">
                      <ArrowRight size={16} />
                    </span>
                  </button>
                </div>

                {canResendForgotOtp && (
                  <button type="button" className="auth-forgot-link" onClick={resendForgotOtp}>
                    Renvoyer le code
                  </button>
                )}
              </form>
            )}

            {forgotStep === "password" && (
              <form className="auth-form" onSubmit={submitResetPassword}>
                <div className="auth-field">
                  <label className="auth-field__label" htmlFor="new-password">
                    Nouveau mot de passe
                  </label>
                  <div className="auth-input-wrap">
                    <Lock className="auth-input-wrap__icon" size={20} />
                    <input
                      id="new-password"
                      type="password"
                      className="auth-input"
                      placeholder="••••••••••••"
                      value={newPassword}
                      onChange={onNewPasswordChange}
                      required
                    />
                  </div>
                </div>

                <div className="auth-field">
                  <label className="auth-field__label" htmlFor="new-password-confirmation">
                    Confirmer le mot de passe
                  </label>
                  <input
                    id="new-password-confirmation"
                    type="password"
                    className="auth-input"
                    placeholder="••••••••••••"
                    value={newPasswordConfirmation}
                    onChange={onNewPasswordConfirmationChange}
                    required
                  />
                </div>

                <div className="auth-submit-wrap">
                  <button type="submit" className="auth-submit" disabled={resetPasswordPending}>
                    {resetPasswordPending ? (
                      <span className="auth-submit__spinner">
                        <Loader2 size={16} className="animate-spin" />
                        Mise à jour…
                      </span>
                    ) : (
                      <span>Réinitialiser le mot de passe</span>
                    )}
                    <span className="auth-submit__arrow" aria-hidden="true">
                      <KeyRound size={16} />
                    </span>
                  </button>
                </div>
              </form>
            )}

            <button type="button" className="auth-sso" onClick={backToLogin}>
              Retour à la connexion
            </button>
          </>
        )}
      </div>
    </>
  );
}