import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
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

const MIN_PASSWORD_LENGTH = 8;

export function useConnexionPage({ modes } = {}) {
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

  const openForgot = () => switchMode("forgot");
  const backToLogin = () => switchMode("connexion");

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

  const submitLogin = (e) => {
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

  const resendForgotOtp = () => {
    forgot.mutate({ email: resetEmail });
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

  const toggleShowPassword = () => setShowPassword((v) => !v);

  const onEmailChange = (e) => setEmail(e.target.value);
  const onPasswordChange = (e) => setPassword(e.target.value);
  const onRememberChange = (e) => setRemember(e.target.checked);
  const onResetEmailChange = (e) => setResetEmail(e.target.value);
  const onNewPasswordChange = (e) => setNewPassword(e.target.value);
  const onNewPasswordConfirmationChange = (e) => setNewPasswordConfirmation(e.target.value);

  // Derived data
  const loginErrors = getApiFieldErrors(connexion.error);
  const forgotErrors = getApiFieldErrors(forgot.error);
  const loginOtpErrors = getApiFieldErrors(sendLoginOtp.error || verifyLoginOtp.error);
  const visibleError = localError
    || (connexion.error ? getApiErrorMessage(connexion.error) : null)
    || (sendLoginOtp.error ? getApiErrorMessage(sendLoginOtp.error) : null)
    || (verifyLoginOtp.error ? getApiErrorMessage(verifyLoginOtp.error) : null);
  const forgotError = forgot.error ? getApiErrorMessage(forgot.error) : null;
  const emailError = loginErrors.email?.[0] ?? loginOtpErrors.email?.[0];
  const passwordError = loginErrors.password?.[0];
  const forgotEmailError = forgotErrors.email?.[0];

  const loginModeActiveIndex = loginMethod === "password" ? 0 : 1;
  const loginModes = (modes ?? []).map((m) => ({
    ...m,
    isActive: loginMethod === m.id,
    onClick: () => switchLoginMethod(m.id),
    className: `auth-role-switch__btn${loginMethod === m.id ? " auth-role-switch__btn--active" : ""}`,
  }));

  const passwordInputType = showPassword ? "text" : "password";
  const passwordToggleAriaLabel = showPassword ? "Masquer le mot de passe" : "Afficher le mot de passe";

  const loginOtpDisabled = verifyLoginOtp.isPending || sendLoginOtp.isPending;
  const resendLoginCodeDisabled = sendLoginOtp.isPending || !email;
  const otpResendLabel = sendLoginOtp.isPending
    ? "Envoi du code…"
    : canResendLoginOtp
      ? "Renvoyer le code"
      : "Recevoir un nouveau code";

  const isAuthBusy = connexion.isPending || verifyLoginOtp.isPending || sendLoginOtp.isPending;
  const submitDisabled = isAuthBusy || (loginMethod === "otp" && loginOtpSent && loginOtp.length !== 6);
  const busyLabel = sendLoginOtp.isPending && loginMethod === "otp" && !loginOtpSent
    ? "Envoi du code…"
    : "Connexion en cours…";
  const idleLabel = loginMethod === "otp"
    ? loginOtpSent
      ? "Valider le code"
      : "Envoyer le code"
    : "Se Connecter";

  const forgotOtpDisabled = verifyForgotOtp.isPending;
  const forgotOtpSubmitDisabled = verifyForgotOtp.isPending || forgotOtp.length !== 6;

  return {
    // page state
    mode,
    loginMethod,
    forgotStep,
    loginOtpSent,
    localError,
    canResendForgotOtp,
    logoSrc,
    // login modes
    loginModes,
    loginModeActiveIndex,
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
    loginOtp,
    onLoginOtpChange: setLoginOtp,
    loginOtpDisabled,
    sendLoginCode,
    resendLoginCodeDisabled,
    otpResendLabel,
    isAuthBusy,
    submitDisabled,
    busyLabel,
    idleLabel,
    submitLogin,
    openForgot,
    backToLogin,
    // forgot password
    forgotError,
    resetEmail,
    onResetEmailChange,
    forgotEmailError,
    forgotPending: forgot.isPending,
    submitForgotEmail,
    forgotOtp,
    onForgotOtpChange: setForgotOtp,
    forgotOtpDisabled,
    forgotOtpSubmitDisabled,
    verifyForgotOtpPending: verifyForgotOtp.isPending,
    submitForgotOtp,
    resendForgotOtp,
    newPassword,
    onNewPasswordChange,
    newPasswordConfirmation,
    onNewPasswordConfirmationChange,
    resetPasswordPending: resetForgotPassword.isPending,
    submitResetPassword,
  };
}