import { useState, useEffect } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { toast } from "sonner";
import { useTheme } from "@/context/theme-provider";
import faviconLight from "@/assets/icons/light_logo.svg";
import faviconDark from "@/assets/icons/dark_logo.svg";
import { resendVerificationApi, verifyAccountApi } from "@/api/auth.api";
import { getApiErrorMessage, getApiFieldErrors } from "@/lib/api-errors";

export function useVerifyAccount() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { resolvedTheme } = useTheme();
  const logoSrc = resolvedTheme === "dark" ? faviconDark : faviconLight;

  const [token, setToken] = useState(searchParams.get("token") || "");
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [isResending, setIsResending] = useState(false);
  const [isSuccess, setIsSuccess] = useState(false);
  const [error, setError] = useState(null);
  const [canResend, setCanResend] = useState(false);
  const [fieldErrors, setFieldErrors] = useState({});

  useEffect(() => {
    const urlToken = searchParams.get("token");
    if (!urlToken) {
      setError("Jeton de vérification manquant dans l'URL.");
    } else {
      setToken(urlToken);
    }
  }, [searchParams]);

  const handlePasswordChange = (e) => setPassword(e.target.value);
  const handleConfirmPasswordChange = (e) => setConfirmPassword(e.target.value);
  const handleToggleShowPassword = () => setShowPassword((v) => !v);
  const handleBackToLogin = () => navigate("/connexion");

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError(null);
    setCanResend(false);
    setFieldErrors({});

    if (password !== confirmPassword) {
      setFieldErrors({ confirmPassword: ["Les mots de passe ne correspondent pas."] });
      return;
    }

    if (password.length < 8) {
      setFieldErrors({ password: ["Le mot de passe doit contenir au moins 8 caractères."] });
      return;
    }

    setIsLoading(true);

    try {
      const response = await verifyAccountApi({
        token,
        password,
        password_confirmation: confirmPassword,
      });
      toast.success(response?.message ?? response?.data?.message ?? "Compte vérifié avec succès !");
      setIsSuccess(true);
      setTimeout(() => {
        navigate("/dashboard");
      }, 1500);
    } catch (err) {
      setError(getApiErrorMessage(err));
      setCanResend([
        "VERIFICATION_TOKEN_EXPIRED",
        "VERIFICATION_TOKEN_INVALID_OR_EXPIRED",
      ].includes(err.response?.data?.code));
      setFieldErrors(getApiFieldErrors(err));
    } finally {
      setIsLoading(false);
    }
  };

  const handleResend = async () => {
    setError(null);
    setFieldErrors({});
    setIsResending(true);

    try {
      const response = await resendVerificationApi({ token });
      toast.success(response?.message ?? response?.data?.message ?? "Un nouveau lien de vérification a été envoyé.");
      setCanResend(false);
    } catch (err) {
      setError(getApiErrorMessage(err));
    } finally {
      setIsResending(false);
    }
  };

  return {
    logoSrc,
    password,
    confirmPassword,
    showPassword,
    isLoading,
    isResending,
    isSuccess,
    error,
    canResend,
    fieldErrors,
    handlePasswordChange,
    handleConfirmPasswordChange,
    handleToggleShowPassword,
    handleBackToLogin,
    handleSubmit,
    handleResend,
  };
}