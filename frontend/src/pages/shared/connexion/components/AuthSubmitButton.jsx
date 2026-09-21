import { Loader2 } from "lucide-react";

export default function AuthSubmitButton({
  type = "submit",
  disabled,
  pending,
  busyLabel,
  idleLabel,
  icon: Icon,
}) {
  return (
    <div className="auth-submit-wrap">
      <button type={type} className="auth-submit" disabled={disabled}>
        {pending ? (
          <span className="auth-submit__spinner">
            <Loader2 size={16} className="animate-spin" />
            {busyLabel}
          </span>
        ) : (
          <span>{idleLabel}</span>
        )}
        <span className="auth-submit__arrow" aria-hidden="true">
          <Icon size={16} />
        </span>
      </button>
    </div>
  );
}
