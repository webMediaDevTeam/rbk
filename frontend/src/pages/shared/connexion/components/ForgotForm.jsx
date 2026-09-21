import AuthAlert from "./AuthAlert.jsx";
import ForgotEmailStep from "./ForgotEmailStep.jsx";
import ForgotOtpStep from "./ForgotOtpStep.jsx";
import ForgotPasswordStep from "./ForgotPasswordStep.jsx";

export default function ForgotForm({ vm }) {
  const {
    localError,
    forgotStep,
    forgotError,
    resetEmail,
    onResetEmailChange,
    forgotEmailError,
    forgotPending,
    submitForgotEmail,
    forgotOtp,
    onForgotOtpChange,
    forgotOtpDisabled,
    forgotOtpSubmitDisabled,
    verifyForgotOtpPending,
    submitForgotOtp,
    canResendForgotOtp,
    resendForgotOtp,
    newPassword,
    onNewPasswordChange,
    newPasswordConfirmation,
    onNewPasswordConfirmationChange,
    resetPasswordPending,
    submitResetPassword,
    backToLogin,
  } = vm;

  return (
    <>
      <AuthAlert message={localError} />

      {forgotStep === "email" && (
        <ForgotEmailStep
          email={resetEmail}
          onEmailChange={onResetEmailChange}
          error={forgotEmailError}
          pending={forgotPending}
          onSubmit={submitForgotEmail}
        />
      )}

      {forgotStep === "otp" && (
        <ForgotOtpStep
          code={forgotOtp}
          onCodeChange={onForgotOtpChange}
          disabled={forgotOtpDisabled}
          submitDisabled={forgotOtpSubmitDisabled}
          pending={verifyForgotOtpPending}
          onSubmit={submitForgotOtp}
          showResend={canResendForgotOtp}
          onResend={resendForgotOtp}
          email={resetEmail}
        />
      )}

      {forgotStep === "password" && (
        <ForgotPasswordStep
          password={newPassword}
          onPasswordChange={onNewPasswordChange}
          confirmation={newPasswordConfirmation}
          onConfirmationChange={onNewPasswordConfirmationChange}
          pending={resetPasswordPending}
          onSubmit={submitResetPassword}
        />
      )}

      <button type="button" className="mt-5 auth-sso" onClick={backToLogin}>
        Retour à la connexion
      </button>
    </>
  );
}
