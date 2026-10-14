import { useConnexionPage } from "./useConnexionPage.js";
import AuthCard from "./components/AuthCard.jsx";
import LoginForm from "./components/LoginForm.jsx";

/**
 * Écran de connexion — uniquement par mot de passe.
 * (Le « login par code » et le « mot de passe oublié » ont été retirés.)
 */
export default function ConnexionPage() {
  const vm = useConnexionPage();

  return (
    <AuthCard logoSrc={vm.logoSrc}>
      <LoginForm vm={vm} />
    </AuthCard>
  );
}
