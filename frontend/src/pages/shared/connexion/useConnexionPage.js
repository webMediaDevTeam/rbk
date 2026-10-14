import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { toast } from "sonner";
import { useTheme } from "@/context/theme-provider";
import { useAuth } from "@/context/AuthContext";
import faviconLight from "@/assets/icons/light_logo.svg";
import faviconDark from "@/assets/icons/dark_logo.svg";
import { useConnexion } from "@/hooks/useConnexion";
import { getApiErrorMessage, getApiFieldErrors } from "@/lib/api-errors";

/**
 * Logique de l'écran de connexion — mot de passe uniquement.
 * (`useConnexion` persiste la session via `login()` ; ici on ne fait que
 * rediriger vers l'accueil du rôle après succès.)
 */
export function useConnexionPage() {
  const { homeForRole } = useAuth();
  const navigate = useNavigate();
  const [showPassword, setShowPassword] = useState(false);
  const { resolvedTheme } = useTheme();
  const logoSrc = resolvedTheme === "dark" ? faviconDark : faviconLight;

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [remember, setRemember] = useState(false);

  const completeLogin = ({ utilisateur }) => {
    toast.success("Connexion réussie.");
    navigate(homeForRole(utilisateur.role));
  };

  const connexion = useConnexion({ onSuccess: completeLogin });

  const submitLogin = (e) => {
    e.preventDefault();
    connexion.mutate({ email, password, remember });
  };

  const toggleShowPassword = () => setShowPassword((v) => !v);

  const onEmailChange = (e) => setEmail(e.target.value);
  const onPasswordChange = (e) => setPassword(e.target.value);
  const onRememberChange = (e) => setRemember(e.target.checked);

  // Derived data
  const loginErrors = getApiFieldErrors(connexion.error);
  const visibleError = connexion.error ? getApiErrorMessage(connexion.error) : null;
  const emailError = loginErrors.email?.[0];
  const passwordError = loginErrors.password?.[0];

  const passwordInputType = showPassword ? "text" : "password";
  const passwordToggleAriaLabel = showPassword ? "Masquer le mot de passe" : "Afficher le mot de passe";

  const isAuthBusy = connexion.isPending;
  const submitDisabled = isAuthBusy;
  const busyLabel = "Connexion en cours…";
  const idleLabel = "Se Connecter";

  return {
    logoSrc,
    // connexion form
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
  };
}
