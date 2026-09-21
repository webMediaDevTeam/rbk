import { ArrowRight } from "lucide-react";
import AuthSubmitButton from "./AuthSubmitButton.jsx";
import OtpInput from "./OtpInput.jsx";

export default function ForgotOtpStep({
  code,
  onCodeChange,
  disabled,
  submitDisabled,
  pending,
  onSubmit,
  showResend,
  onResend,
  email,
}) {
  return (
    <form className="auth-form" onSubmit={onSubmit}>
      <div className="auth-field">
        <label className="auth-field__label">Code de vérification</label>
        <div className="auth-otp-wrap">
          <OtpInput value={code} onChange={onCodeChange} disabled={disabled} />
        </div>
        <p className="auth-otp-hint">
          Un code à 6 chiffres a été envoyé à <strong>{email}</strong>. Vérifiez votre boîte de réception.
        </p>
      </div>

      <AuthSubmitButton
        disabled={submitDisabled}
        pending={pending}
        busyLabel="Vérification…"
        idleLabel="Valider le code"
        icon={ArrowRight}
      />

      {showResend && (
        <button type="button" className="auth-forgot-link" onClick={onResend}>
          Renvoyer le code
        </button>
      )}
    </form>
  );
}
