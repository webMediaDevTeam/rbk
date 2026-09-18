import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { AlertCircle, ArrowRight, Eye, EyeOff, KeyRound, Loader2, Lock, LogIn, Mail } from "lucide-react";
import { toast } from "sonner";
import { useTheme } from "@/context/theme-provider";
import { useAuth } from "@/context/AuthContext";
import faviconLight from "@/assets/icons/light_logo.svg";
import faviconDark from "@/assets/icons/dark_logo.svg";
import { useConnexion } from "@/hooks/useConnexion";
import { useForgotPassword } from "@/hooks/useForgotPassword";
import {
  resetForgotPasswordApi,
  sendLoginOtpApi,
  verifyForgotPasswordOtpApi,
  verifyLoginOtpApi,
} from "@/api/auth.api";
import { getApiErrorMessage, getApiFieldErrors } from "@/lib/api-errors";
import { InputOTP, InputOTPGroup, InputOTPSlot } from "@/components/ui/input-otp";

const MODES = [
  { id: "password", label: "Par Mot De Passe", icon: Lock },
  { id: "otp", label: "Par Code", icon: Mail },
];

const MIN_PASSWORD_LENGTH = 8;

export default function ConnexionPage() {
  const { homeForRole, login } = useAuth();
  const navigate = useNavigate();
  const [mode, setMode] = useState("connexion");
  const [loginMethod, setLoginMethod] = useState("password");
  const [forgotStep, setForgotStep] = useState("email");
  const [showPassword, setShowPassword] = useState(false);
  const { resolvedTheme } = useTheme();
  const logoSrc = resolvedTheme === "dark" ? faviconDark : faviconLight;

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [remember, setRemember] = useState(false);
  const [loginOtp, setLoginOtp] = useState("");
  const [resetEmail, setResetEmail] = useState("");
  const [forgotOtp, setForgotOtp] = useState("");
  const [passwordResetToken, setPasswordResetToken] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [newPasswordConfirmation, setNewPasswordConfirmation] = useState("");
  const [localError, setLocalError] = useState(null);
  const [canResendForgotOtp, setCanResendForgotOtp] = useState(false);
  const [canResendLoginOtp, setCanResendLoginOtp] = useState(false);
  const [loginOtpSent, setLoginOtpSent] = useState(false);

  const completeLogin = ({ utilisateur }) => {
    toast.success("Connexion réussie.");
    navigate(homeForRole(utilisateur.role));
  };

  const connexion = useConnexion({ onSuccess: completeLogin });
  const sendLoginOtp = useMutation({ mutationFn: sendLoginOtpApi });
  const verifyLoginOtp = useMutation({
    mutationFn: verifyLoginOtpApi,
    onSuccess: (data) => {
      login({ utilisateur: data.utilisateur, jeton: data.jeton, remember });
      completeLogin(data);
    },
  });

  const forgot = useForgotPassword({
    onSuccess: ({ message }) => {
      toast.success(message ?? "Code envoyé.");
      setForgotStep("otp");
      setCanResendForgotOtp(false);
    },
  });
  const verifyForgotOtp = useMutation({ mutationFn: verifyForgotPasswordOtpApi });
  const resetForgotPassword = useMutation({ mutationFn: resetForgotPasswordApi });

  const switchMode = (id) => {
    setMode(id);
    setLocalError(null);
    setForgotStep("email");
    setLoginMethod("password");
    setLoginOtp("");
    setLoginOtpSent(false);
    setForgotOtp("");
    setPasswordResetToken("");
    setCanResendForgotOtp(false);
    setCanResendLoginOtp(false);
    connexion.reset();
    forgot.reset();
    sendLoginOtp.reset();
    verifyLoginOtp.reset();
    verifyForgotOtp.reset();
    resetForgotPassword.reset();
  };

  const switchLoginMethod = (id) => {
    setLocalError(null);
    setLoginOtp("");
    setLoginOtpSent(false);
    setCanResendLoginOtp(false);
    sendLoginOtp.reset();
    verifyLoginOtp.reset();
    setLoginMethod(id);
  };

  const sendLoginCode = () => {
    setLocalError(null);
    setCanResendLoginOtp(false);
    sendLoginOtp.mutate(
      { email },
      {
        onSuccess: (data) => {
          toast.success(data?.message ?? "Code de connexion envoyé.");
          setLoginOtp("");
          setLoginOtpSent(true);
        },
        onError: (err) => setLocalError(getApiErrorMessage(err)),
      }
    );
  };

  const submitForgotEmail = (e) => {
    e.preventDefault();
    setLocalError(null);
    forgot.mutate({ email: resetEmail });
  };

  const submitForgotOtp = (e) => {
    e.preventDefault();
    setLocalError(null);
    setCanResendForgotOtp(false);
    verifyForgotOtp.mutate(
      { email: resetEmail, code: forgotOtp },
      {
        onSuccess: (data) => {
          setPasswordResetToken(data.password_reset_token);
          setForgotStep("password");
          toast.success(data?.message ?? "Code vérifié.");
        },
        onError: (err) => {
          setLocalError(getApiErrorMessage(err));
          setCanResendForgotOtp(err.response?.data?.code === "FORGOT_PASSWORD_OTP_EXPIRED");
        },
      }
    );
  };

  const submitResetPassword = (e) => {
    e.preventDefault();
    setLocalError(null);
    if (newPassword.length < MIN_PASSWORD_LENGTH) {
      setLocalError(`Le mot de passe doit contenir au moins ${MIN_PASSWORD_LENGTH} caractères.`);
      return;
    }
    if (newPassword !== newPasswordConfirmation) {
      setLocalError("La confirmation ne correspond pas au mot de passe.");
      return;
    }

    resetForgotPassword.mutate(
      {
        email: resetEmail,
        password_reset_token: passwordResetToken,
        password: newPassword,
        password_confirmation: newPasswordConfirmation,
      },
      {
        onSuccess: (data) => {
          toast.success(data?.message ?? "Mot de passe réinitialisé.");
          switchMode("connexion");
          setEmail(resetEmail);
        },
        onError: (err) => setLocalError(getApiErrorMessage(err)),
      }
    );
  };

  const loginErrors = getApiFieldErrors(connexion.error);
  const forgotErrors = getApiFieldErrors(forgot.error);
  const loginOtpErrors = getApiFieldErrors(sendLoginOtp.error || verifyLoginOtp.error);
  const visibleError = localError
    || (connexion.error ? getApiErrorMessage(connexion.error) : null)
    || (sendLoginOtp.error ? getApiErrorMessage(sendLoginOtp.error) : null)
    || (verifyLoginOtp.error ? getApiErrorMessage(verifyLoginOtp.error) : null);

  return (
    <>
      <div className="auth-card">
        <div className="auth-card-logo">
          <img src={logoSrc} alt="Zdig IA" className="auth-card-logo__img" />
        </div>

        <div className="auth-heading">
          <h1 className="auth-heading__title">
            Bienvenue sur <strong>Zdig IA</strong>
          </h1>
                      {mode !== "connexion"&&
            (<p className="auth-heading__subtitle">Réinitialisez votre mot de passe avec un code envoyé par email.</p>)
              }
        </div>

        {mode === "connexion" && (
          <>
            <div className="auth-switch-badge">
              <LogIn size={14} />
              <span>Connexion</span>
            </div>
            <div
              className="auth-role-switch"
              role="tablist"
              aria-label="Choix du mode de connexion"
              data-active={loginMethod === "password" ? 0 : 1}
            >
              {MODES.map((m) => (
                <button
                  key={m.id}
                  type="button"
                  role="tab"
                  aria-selected={loginMethod === m.id}
                  onClick={() => switchLoginMethod(m.id)}
                  className={`auth-role-switch__btn ${loginMethod === m.id ? "auth-role-switch__btn--active" : ""}`}
                >
                  <m.icon size={14} />
                  {m.label}
                </button>
              ))}
            </div>
          </>
        )}

        {mode === "connexion" && (
          <form
            className="auth-form"
            onSubmit={(e) => {
              e.preventDefault();
              if (loginMethod === "otp") {
                if (!loginOtpSent) {
                  sendLoginCode();
                  return;
                }
                verifyLoginOtp.mutate(
                  { email, code: loginOtp },
                  {
                    onError: (err) => {
                      setCanResendLoginOtp(err.response?.data?.code === "LOGIN_OTP_EXPIRED");
                    },
                  }
                );
                return;
              }
              connexion.mutate({ email, password, remember });
            }}
          >
            {visibleError && (
              <div className="auth-alert" role="alert">
                <AlertCircle className="auth-alert__icon" size={15} />
                <span>{visibleError}</span>
              </div>
            )}

            <div className="auth-field">
              <label className="auth-field__label" htmlFor="auth-email">
                Courriel Professionnel
              </label>
              <div className="auth-input-wrap">
                <Mail className="auth-input-wrap__icon" size={20} />
                <input
                  id="auth-email"
                  type="email"
                  className="auth-input"
                  placeholder="agent@ZdigIA.ca"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  required
                />
              </div>
              {(loginErrors.email || loginOtpErrors.email) && (
                <p className="auth-field__error">{loginErrors.email?.[0] ?? loginOtpErrors.email?.[0]}</p>
              )}
            </div>

            {loginMethod === "password" && (
              <div className="auth-field">
                <label className="auth-field__label" htmlFor="auth-password">
                  Mot de Passe
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
                  />
                  <button
                    type="button"
                    className="auth-input-wrap__action"
                    onClick={() => setShowPassword((v) => !v)}
                    aria-label={showPassword ? "Masquer le mot de passe" : "Afficher le mot de passe"}
                  >
                    {showPassword ? <EyeOff size={20} /> : <Eye size={20} />}
                  </button>
                </div>
                {loginErrors.password && <p className="auth-field__error">{loginErrors.password[0]}</p>}
              </div>
            )}

            {loginMethod === "otp" && !loginOtpSent && (
              <div className="auth-field">
                <p className="auth-otp-hint">
                  Entrez votre courriel professionnel puis cliquez sur
                  <strong> « Envoyer le code » </strong>
                  pour recevoir votre code de vérification.
                </p>
              </div>
            )}

            {loginMethod === "otp" && loginOtpSent && (
              <div className="auth-field">
                <label className="auth-field__label">Code de vérification</label>
                <div className="auth-otp-wrap">
                  <InputOTP
                    maxLength={6}
                    value={loginOtp}
                    onChange={setLoginOtp}
                    disabled={verifyLoginOtp.isPending || sendLoginOtp.isPending}
                  >
                    <InputOTPGroup>
                      {[0, 1, 2, 3, 4, 5].map((index) => <InputOTPSlot key={index} index={index} />)}
                    </InputOTPGroup>
                  </InputOTP>
                </div>
                <p className="auth-otp-hint">
                  Un code à 6 chiffres a été envoyé à <strong>{email}</strong>.
                </p>
                <button
                  type="button"
                  className="auth-forgot"
                  onClick={sendLoginCode}
                  disabled={sendLoginOtp.isPending || !email}
                >
                  {sendLoginOtp.isPending
                    ? "Envoi du code…"
                    : canResendLoginOtp
                      ? "Renvoyer le code"
                      : "Recevoir un nouveau code"}
                </button>
              </div>
            )}

            <div className="auth-row">
              <label className="auth-check">
                <input
                  type="checkbox"
                  className="auth-check__input"
                  checked={remember}
                  onChange={(e) => setRemember(e.target.checked)}
                />
                <span>Se souvenir de moi</span>
              </label>
            </div>

<div className="auth-submit-wrap">
                <button
                  type="submit"
                  className="auth-submit"
                  disabled={
                    connexion.isPending
                    || verifyLoginOtp.isPending
                    || sendLoginOtp.isPending
                    || (loginMethod === "otp" && loginOtpSent && loginOtp.length !== 6)
                  }
                >
                  {connexion.isPending || verifyLoginOtp.isPending || sendLoginOtp.isPending ? (
                    <span className="auth-submit__spinner">
                      <Loader2 size={16} className="animate-spin" />
                      {sendLoginOtp.isPending && loginMethod === "otp" && !loginOtpSent
                        ? "Envoi du code…"
                        : "Connexion en cours…"}
                    </span>
                  ) : (
                    <span>
                      {loginMethod === "otp"
                        ? loginOtpSent
                          ? "Valider le code"
                          : "Envoyer le code"
                        : "Se Connecter"}
                    </span>
                  )}
                  <span className="auth-submit__arrow" aria-hidden="true">
                    <ArrowRight size={16} />
                  </span>
                </button>
              </div>

            <button type="button" className="auth-forgot-link" onClick={() => switchMode("forgot")}>
              Mot de passe oublié ?
            </button>
          </form>
        )}

        {mode === "forgot" && (
          <>
            {localError && (
              <div className="auth-alert" role="alert">
                <AlertCircle className="auth-alert__icon" size={15} />
                <span>{localError}</span>
              </div>
            )}

            {forgotStep === "email" && (
              <form className="auth-form" onSubmit={submitForgotEmail}>
                {forgot.error && (
                  <div className="auth-alert" role="alert">
                    <AlertCircle className="auth-alert__icon" size={15} />
                    <span>{getApiErrorMessage(forgot.error)}</span>
                  </div>
                )}

                <div className="auth-field">
                  <label className="auth-field__label" htmlFor="auth-reset-email">
                    Courriel Professionnel
                  </label>
                  <div className="auth-input-wrap">
                    <Mail className="auth-input-wrap__icon" size={20} />
                    <input
                      id="auth-reset-email"
                      type="email"
                      className="auth-input"
                      placeholder="agent@ZdigIA.ca"
                      value={resetEmail}
                      onChange={(e) => setResetEmail(e.target.value)}
                      required
                    />
                  </div>
                  {forgotErrors.email && <p className="auth-field__error">{forgotErrors.email[0]}</p>}
                </div>

                <div className="auth-submit-wrap">
                  <button type="submit" className="auth-submit" disabled={forgot.isPending}>
                    {forgot.isPending ? (
                      <span className="auth-submit__spinner">
                        <Loader2 size={16} className="animate-spin" />
                        Envoi en cours…
                      </span>
                    ) : (
                      <span>Envoyer le code OTP</span>
                    )}
                    <span className="auth-submit__arrow" aria-hidden="true">
                      <KeyRound size={16} />
                    </span>
                  </button>
                </div>
              </form>
            )}

            {forgotStep === "otp" && (
              <form className="auth-form" onSubmit={submitForgotOtp}>
                <div className="auth-field">
                  <label className="auth-field__label">Code de vérification</label>
                  <div className="auth-otp-wrap">
                    <InputOTP maxLength={6} value={forgotOtp} onChange={setForgotOtp} disabled={verifyForgotOtp.isPending}>
                      <InputOTPGroup>
                        {[0, 1, 2, 3, 4, 5].map((index) => <InputOTPSlot key={index} index={index} />)}
                      </InputOTPGroup>
                    </InputOTP>
                  </div>
                  <p className="auth-otp-hint">
                    Un code à 6 chiffres a été envoyé à <strong>{resetEmail}</strong>. Vérifiez votre boîte de réception.
                  </p>
                </div>

                <div className="auth-submit-wrap">
                  <button type="submit" className="auth-submit" disabled={verifyForgotOtp.isPending || forgotOtp.length !== 6}>
                    {verifyForgotOtp.isPending ? (
                      <span className="auth-submit__spinner">
                        <Loader2 size={16} className="animate-spin" />
                        Vérification…
                      </span>
                    ) : (
                      <span>Valider le code</span>
                    )}
                    <span className="auth-submit__arrow" aria-hidden="true">
                      <ArrowRight size={16} />
                    </span>
                  </button>
                </div>

                {canResendForgotOtp && (
                  <button type="button" className="auth-forgot-link" onClick={() => forgot.mutate({ email: resetEmail })}>
                    Renvoyer le code
                  </button>
                )}
              </form>
            )}

            {forgotStep === "password" && (
              <form className="auth-form" onSubmit={submitResetPassword}>
                <div className="auth-field">
                  <label className="auth-field__label" htmlFor="new-password">
                    Nouveau mot de passe
                  </label>
                  <div className="auth-input-wrap">
                    <Lock className="auth-input-wrap__icon" size={20} />
                    <input
                      id="new-password"
                      type="password"
                      className="auth-input"
                      placeholder="••••••••••••"
                      value={newPassword}
                      onChange={(e) => setNewPassword(e.target.value)}
                      required
                    />
                  </div>
                </div>

                <div className="auth-field">
                  <label className="auth-field__label" htmlFor="new-password-confirmation">
                    Confirmer le mot de passe
                  </label>
                  <input
                    id="new-password-confirmation"
                    type="password"
                    className="auth-input"
                    placeholder="••••••••••••"
                    value={newPasswordConfirmation}
                    onChange={(e) => setNewPasswordConfirmation(e.target.value)}
                    required
                  />
                </div>

                <div className="auth-submit-wrap">
                  <button type="submit" className="auth-submit" disabled={resetForgotPassword.isPending}>
                    {resetForgotPassword.isPending ? (
                      <span className="auth-submit__spinner">
                        <Loader2 size={16} className="animate-spin" />
                        Mise à jour…
                      </span>
                    ) : (
                      <span>Réinitialiser le mot de passe</span>
                    )}
                    <span className="auth-submit__arrow" aria-hidden="true">
                      <KeyRound size={16} />
                    </span>
                  </button>
                </div>
              </form>
            )}

            <button type="button" className="auth-sso" onClick={() => switchMode("connexion")}>
              Retour à la connexion
            </button>
          </>
        )}
      </div>
    </>
  );
}
