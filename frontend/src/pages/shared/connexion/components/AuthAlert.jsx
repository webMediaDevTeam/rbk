import { AlertCircle } from "lucide-react";

export default function AuthAlert({ message }) {
  if (!message) return null;

  return (
    <div className="auth-alert" role="alert">
      <AlertCircle className="auth-alert__icon" size={15} />
      <span>{message}</span>
    </div>
  );
}
