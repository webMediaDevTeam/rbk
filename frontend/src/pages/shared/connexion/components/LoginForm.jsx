import { ArrowRight, Eye, EyeOff, Lock, LogIn, Mail } from "lucide-react";
import AuthAlert from "./AuthAlert.jsx";
import AuthInput from "./AuthInput.jsx";
import AuthSubmitButton from "./AuthSubmitButton.jsx";
import RememberRow from "./RememberRow.jsx";

export default function LoginForm({ vm }) {
  const {
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
    isAuthBusy,
    submitDisabled,
    busyLabel,
    idleLabel,
    submitLogin,
  } = vm;

  return (
    <>
      <div className="auth-switch-badge">
        <LogIn size={14} />
        <span>Connexion</span>
      </div>

      <form className="auth-form" onSubmit={submitLogin}>
        <AuthAlert message={visibleError} />

        <AuthInput
          id="auth-email"
          label="Courriel ou Login"
          type="text"
          autoComplete="username"
          placeholder="agent@ZdigIA.ca ou login"
          value={email}
          onChange={onEmailChange}
          icon={Mail}
          error={emailError}
          required
        />

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

        <RememberRow checked={remember} onChange={onRememberChange} />

        <AuthSubmitButton
          disabled={submitDisabled}
          pending={isAuthBusy}
          busyLabel={busyLabel}
          idleLabel={idleLabel}
          icon={ArrowRight}
        />
      </form>
    </>
  );
}
