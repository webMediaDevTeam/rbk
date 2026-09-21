import { KeyRound, Mail } from "lucide-react";
import AuthAlert from "./AuthAlert.jsx";
import AuthInput from "./AuthInput.jsx";
import AuthSubmitButton from "./AuthSubmitButton.jsx";

export default function ForgotEmailStep({ email, onEmailChange, error, pending, onSubmit }) {
  return (
    <form className="auth-form" onSubmit={onSubmit}>
      <AuthAlert message={error} />

      <AuthInput
        id="auth-reset-email"
        label="Courriel Professionnel"
        type="email"
        placeholder="agent@ZdigIA.ca"
        value={email}
        onChange={onEmailChange}
        icon={Mail}
        required
      />

      <AuthSubmitButton pending={pending} busyLabel="Envoi en cours…" idleLabel="Envoyer le code OTP" icon={KeyRound} />
    </form>
  );
}
