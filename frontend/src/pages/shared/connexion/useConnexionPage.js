import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { toast } from "sonner";
import { useTheme } from "@/context/theme-provider";
import { useAuth } from "@/context/AuthContext";
import faviconLight from "@/assets/icons/light_logo.svg";
import faviconDark from "@/assets/icons/dark_logo.svg";
import { useConnexion } from "@/hooks/useConnexion";
import { geofenceStatusApi } from "@/api/auth.api.js";
import { getPosition } from "@/lib/geolocation";
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

  // Le contrôle GPS est-il activé **côté serveur** ? L'interrupteur de la page
  // « GPS » est seul maître : éteint, personne n'est jamais bloqué et le
  // navigateur n'a aucune raison d'être interrogé (la demande d'autorisation
  // qui en résulte serait inutile). En cas d'échec du appel, on reste à
  // « désactivé » : rien ne doit empêcher de se connecter.
  const { data: geofence } = useQuery({
    queryKey: ["geofence-status"],
    queryFn: geofenceStatusApi,
    retry: false,
    staleTime: 60_000,
  });
  const geofenceEnabled = Boolean(geofence?.enabled);

  const submitLogin = async (e) => {
    e.preventDefault();
    // Position facultative : null si le contrôle est éteint, refusée,
    // indisponible ou en timeout. Le serveur n'utilise ces coordonnées que pour
    // les rôles contraints sans dérogation (COMERCIAL / OPERATOR) ; l'admin se
    // connecte pareil.
    const coords = geofenceEnabled ? await getPosition() : null;
    connexion.mutate({
      email,
      password,
      remember,
      lat: coords?.lat,
      lng: coords?.lng,
      accuracy: coords?.accuracy,
    });
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
