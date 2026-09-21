import { KeyRound, Lock } from "lucide-react";
import AuthInput from "./AuthInput.jsx";
import AuthSubmitButton from "./AuthSubmitButton.jsx";

export default function ForgotPasswordStep({
  password,
  onPasswordChange,
  confirmation,
  onConfirmationChange,
  pending,
  onSubmit,
}) {
  return (
    <form className="auth-form" onSubmit={onSubmit}>
      <AuthInput
        id="new-password"
        label="Nouveau mot de passe"
        type="password"
        placeholder="••••••••••••"
        value={password}
        onChange={onPasswordChange}
        icon={Lock}
        required
      />

      <AuthInput
        id="new-password-confirmation"
        label="Confirmer le mot de passe"
        type="password"
        placeholder="••••••••••••"
        value={confirmation}
        onChange={onConfirmationChange}
        required
      />

      <AuthSubmitButton pending={pending} busyLabel="Mise à jour…" idleLabel="Réinitialiser le mot de passe" icon={KeyRound} />
    </form>
  );
}
