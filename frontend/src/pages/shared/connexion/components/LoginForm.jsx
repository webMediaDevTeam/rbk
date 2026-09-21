import { ArrowRight, Eye, EyeOff, Lock, LogIn, Mail } from "lucide-react";
import AuthAlert from "./AuthAlert.jsx";
import AuthInput from "./AuthInput.jsx";
import AuthSubmitButton from "./AuthSubmitButton.jsx";
import LoginModeSwitch from "./LoginModeSwitch.jsx";
import OtpInput from "./OtpInput.jsx";
import RememberRow from "./RememberRow.jsx";

export default function LoginForm({ vm }) {
  const {
    loginMethod,
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
  } = vm;

  return (
    <>
      <div className="auth-switch-badge">
        <LogIn size={14} />
        <span>Connexion</span>
      </div>

      <LoginModeSwitch modes={loginModes} activeIndex={loginModeActiveIndex} />

      <form className="auth-form" onSubmit={submitLogin}>
        <AuthAlert message={visibleError} />

        <AuthInput
          id="auth-email"
          label="Courriel Professionnel"
          type="email"
          placeholder="agent@ZdigIA.ca"
          value={email}
          onChange={onEmailChange}
          icon={Mail}
          error={emailError}
          required
        />

        {loginMethod === "password" && (
          <AuthInput
            id="auth-password"
            label="Mot de Passe"
            type={passwordInputType}
            placeholder="••••••••••••"
            value={password}
            onChange={onPasswordChange}
            icon={Lock}
            inputClassName="auth-input auth-input--with-action"
            error={passwordError}
            required
            action={
              <button
                type="button"
                className="auth-input-wrap__action"
                onClick={toggleShowPassword}
                aria-label={passwordToggleAriaLabel}
              >
                {showPassword ? <EyeOff size={20} /> : <Eye size={20} />}
              </button>
            }
          />
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
              <OtpInput value={loginOtp} onChange={onLoginOtpChange} disabled={loginOtpDisabled} />
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

        <RememberRow checked={remember} onChange={onRememberChange} />

        <AuthSubmitButton
          disabled={submitDisabled}
          pending={isAuthBusy}
          busyLabel={busyLabel}
          idleLabel={idleLabel}
          icon={ArrowRight}
        />

        <button type="button" className="auth-forgot-link" onClick={openForgot}>
          Mot de passe oublié ?
        </button>
      </form>
    </>
  );
}
