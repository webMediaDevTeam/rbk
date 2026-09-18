import { useState, useEffect } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { AlertCircle, CheckCircle, Eye, EyeOff, KeyRound, Loader2, Lock, Mail, Shield } from "lucide-react";
import { toast } from "sonner";
import { useTheme } from "@/context/theme-provider";
import faviconLight from "@/assets/icons/light_logo.svg";
import faviconDark from "@/assets/icons/dark_logo.svg";
import { resendVerificationApi, verifyAccountApi } from "@/api/auth.api";
import { getApiErrorMessage, getApiFieldErrors } from "@/lib/api-errors";

export default function VerifyAccountPage() {
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

  if (isSuccess) {
    return (
      <>
        <div className="auth">
          <div className="auth-bg" aria-hidden="true">
            <span className="auth-blob auth-blob--tl" />
            <span className="auth-blob auth-blob--tr" />
            <span className="auth-blob auth-blob--bl" />
            <span className="auth-blob auth-blob--center" />
            <span className="auth-blob auth-blob--br" />
            <span className="auth-orb auth-orb--1" />
            <span className="auth-orb auth-orb--2" />
            <span className="auth-orb auth-orb--3" />
            <span className="auth-orb auth-orb--4" />
            <span className="auth-oval auth-oval--tr" />
            <span className="auth-oval auth-oval--bl" />
            <span className="auth-oval auth-oval--ml" />
            <span className="auth-oval auth-oval--mr" />
            <span className="auth-ring auth-ring--1" />
            <span className="auth-ring auth-ring--2" />
            <span className="auth-ring auth-ring--3" />
            <span className="auth-dot auth-dot--1" />
            <span className="auth-dot auth-dot--2" />
            <span className="auth-dot auth-dot--3" />
            <span className="auth-line auth-line--1" />
          </div>
          <main className="auth-main">
            <div className="auth-card">
              <div className="auth-card-logo">
                <img src={logoSrc} alt="RBK" className="auth-card-logo__img" />
              </div>
              <div className="auth-heading">
                <div className="auth-success-icon">
                  <CheckCircle size={48} className="text-green-500" />
                </div>
                <h1 className="auth-heading__title">Compte vérifié !</h1>
                <p className="auth-heading__subtitle">
                  Votre compte a été activé et votre mot de passe a été défini.
                  Redirection vers le tableau de bord...
                </p>
              </div>
              <div className="auth-submit-wrap">
                <button className="auth-submit" disabled>
                  <span className="auth-submit__spinner">
                    <Loader2 size={16} className="animate-spin" />
                    Redirection en cours…
                  </span>
                  <span className="auth-submit__arrow" aria-hidden="true">
                    <KeyRound size={16} />
                  </span>
                </button>
              </div>
            </div>
          </main>
        </div>
      </>
    );
  }

  return (
    <>
      <div className="auth">
        <div className="auth-bg" aria-hidden="true">
          <span className="auth-blob auth-blob--tl" />
          <span className="auth-blob auth-blob--tr" />
          <span className="auth-blob auth-blob--bl" />
          <span className="auth-blob auth-blob--center" />
          <span className="auth-blob auth-blob--br" />
          <span className="auth-orb auth-orb--1" />
          <span className="auth-orb auth-orb--2" />
          <span className="auth-orb auth-orb--3" />
          <span className="auth-orb auth-orb--4" />
          <span className="auth-oval auth-oval--tr" />
          <span className="auth-oval auth-oval--bl" />
          <span className="auth-oval auth-oval--ml" />
          <span className="auth-oval auth-oval--mr" />
          <span className="auth-ring auth-ring--1" />
          <span className="auth-ring auth-ring--2" />
          <span className="auth-ring auth-ring--3" />
          <span className="auth-dot auth-dot--1" />
          <span className="auth-dot auth-dot--2" />
          <span className="auth-dot auth-dot--3" />
          <span className="auth-line auth-line--1" />
        </div>

        <main className="auth-main">
          <div className="auth-card">
            {/* Logo */}
            <div className="auth-card-logo">
              <img src={logoSrc} alt="RBK" className="auth-card-logo__img" />
            </div>

            {/* Heading & subtitle */}
            <div className="auth-heading">
              <div className="auth-verify-icon">
                <Shield size={48} className="text-blue-500" />
              </div>
              <h1 className="auth-heading__title">
                Activer votre compte <strong>RBK</strong>
              </h1>
              <p className="auth-heading__subtitle">
                Définissez votre mot de passe initial pour accéder à la plateforme.
              </p>
            </div>

            {/* Error alert */}
            {error && (
              <div className="auth-alert" role="alert">
                <AlertCircle className="auth-alert__icon" size={15} />
                <span>{error}</span>
              </div>
            )}

            {canResend && (
              <div className="auth-submit-wrap">
                <button
                  type="button"
                  className="auth-submit"
                  onClick={handleResend}
                  disabled={isLoading || isResending}
                >
                  {isResending ? (
                    <span className="auth-submit__spinner">
                      <Loader2 size={16} className="animate-spin" />
                      Envoi en cours…
                    </span>
                  ) : (
                    <span>Renvoyer le lien</span>
                  )}
                  <span className="auth-submit__arrow" aria-hidden="true">
                    <Mail size={16} />
                  </span>
                </button>
              </div>
            )}

            {/* Verify form */}
            <form className="auth-form" onSubmit={handleSubmit}>
              {/* Password */}
              <div className="auth-field">
                <label className="auth-field__label" htmlFor="auth-password">
                  Nouveau Mot de Passe
                </label>
                <div className="auth-input-wrap">
                  <Lock className="auth-input-wrap__icon" size={20} />
                  <input
                    id="auth-password"
                    type={showPassword ? "text" : "password"}
                    className="auth-input auth-input--with-action"
                    placeholder="••••••••••••"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    required
                    autoComplete="new-password"
                    disabled={isLoading}
                  />
                  <button
                    type="button"
                    className="auth-input-wrap__action"
                    onClick={() => setShowPassword((v) => !v)}
                    aria-label={showPassword ? "Masquer le mot de passe" : "Afficher le mot de passe"}
                    disabled={isLoading}
                  >
                    {showPassword ? <EyeOff size={20} /> : <Eye size={20} />}
                  </button>
                </div>
                {fieldErrors.password && (
                  <p className="auth-field__error">{fieldErrors.password[0]}</p>
                )}
              </div>

              {/* Confirm Password */}
              <div className="auth-field">
                <label className="auth-field__label" htmlFor="auth-confirm-password">
                  Confirmer le Mot de Passe
                </label>
                <div className="auth-input-wrap">
                  <KeyRound className="auth-input-wrap__icon" size={20} />
                  <input
                    id="auth-confirm-password"
                    type={showPassword ? "text" : "password"}
                    className="auth-input"
                    placeholder="••••••••••••"
                    value={confirmPassword}
                    onChange={(e) => setConfirmPassword(e.target.value)}
                    required
                    autoComplete="new-password"
                    disabled={isLoading}
                  />
                </div>
                {fieldErrors.confirmPassword && (
                  <p className="auth-field__error">{fieldErrors.confirmPassword[0]}</p>
                )}
              </div>

              {/* Submit */}
              <div className="auth-submit-wrap">
                <button type="submit" className="auth-submit" disabled={isLoading}>
                  {isLoading ? (
                    <span className="auth-submit__spinner">
                      <Loader2 size={16} className="animate-spin" />
                      Activation en cours…
                    </span>
                  ) : (
                    <span>Activer mon compte</span>
                  )}
                  <span className="auth-submit__arrow" aria-hidden="true">
                    <KeyRound size={16} />
                  </span>
                </button>
              </div>

              {/* Info note */}
              <p className="auth-note">
                <Mail size={14} /> Votre compte sera activé immédiatement après la définition du mot de passe.
              </p>
            </form>

            {/* Back to login */}
            <div className="auth-footer">
              <button
                type="button"
                className="auth-sso"
                onClick={() => navigate("/connexion")}
                disabled={isLoading}
              >
                Retour à la connexion
              </button>
            </div>
          </div>
        </main>
      </div>
    </>
  );
}
