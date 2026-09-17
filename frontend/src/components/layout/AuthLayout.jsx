import { CircleHelp } from "lucide-react";

export default function AuthLayout({ children }) {
  return (
    <div className="auth">
      {/* Background decorative shapes */}
      <div className="auth-bg" aria-hidden="true">
        {/* Large blurred blobs */}
        <span className="auth-blob auth-blob--tl" />
        <span className="auth-blob auth-blob--tr" />
        <span className="auth-blob auth-blob--bl" />
        <span className="auth-blob auth-blob--center" />
        <span className="auth-blob auth-blob--br" />

        {/* Medium blurred orbs */}
        <span className="auth-orb auth-orb--1" />
        <span className="auth-orb auth-orb--2" />
        <span className="auth-orb auth-orb--3" />
        <span className="auth-orb auth-orb--4" />

        {/* Floating capsule outlines */}
        <span className="auth-oval auth-oval--tr" />
        <span className="auth-oval auth-oval--bl" />
        <span className="auth-oval auth-oval--ml" />
        <span className="auth-oval auth-oval--mr" />
        <span className="auth-oval auth-oval--sm1" />
        <span className="auth-oval auth-oval--sm2" />

        {/* Thin rings */}
        <span className="auth-ring auth-ring--1" />
        <span className="auth-ring auth-ring--2" />
        <span className="auth-ring auth-ring--3" />

        {/* Small dots */}
        <span className="auth-dot auth-dot--1" />
        <span className="auth-dot auth-dot--2" />
        <span className="auth-dot auth-dot--3" />
        <span className="auth-dot auth-dot--4" />
        <span className="auth-dot auth-dot--5" />
        <span className="auth-dot auth-dot--6" />

        {/* Thin lines */}
        <span className="auth-line auth-line--1" />
        <span className="auth-line auth-line--2" />
      </div>

      {/* Center stage */}
      <main className="auth-main">{children}</main>
    </div>
  );
}