import { Lock, Mail } from "lucide-react";
import { useConnexionPage } from "./useConnexionPage.js";
import AuthCard from "./components/AuthCard.jsx";
import LoginForm from "./components/LoginForm.jsx";
import ForgotForm from "./components/ForgotForm.jsx";

const MODES = [
  { id: "password", label: "Par Mot De Passe", icon: Lock },
  { id: "otp", label: "Par Code", icon: Mail },
];

export default function ConnexionPage() {
  const vm = useConnexionPage({ modes: MODES });
  const isLogin = vm.mode === "connexion";

  return (
    <AuthCard
      logoSrc={vm.logoSrc}
      subtitle={isLogin ? null : "Réinitialisez votre mot de passe avec un code envoyé par email."}
    >
      {isLogin ? <LoginForm vm={vm} /> : <ForgotForm vm={vm} />}
    </AuthCard>
  );
}
