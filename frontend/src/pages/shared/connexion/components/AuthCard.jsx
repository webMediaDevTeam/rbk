export default function AuthCard({ logoSrc, subtitle, children }) {
  return (
    <div className="auth-card">
      <div className="auth-card-logo">
        <img src={logoSrc} alt="Zdig IA" className="auth-card-logo__img" />
      </div>

      <div className="auth-heading">
        <h1 className="auth-heading__title">
          Bienvenue sur <strong>Zdig IA</strong>
        </h1>
        {subtitle && <p className="auth-heading__subtitle">{subtitle}</p>}
      </div>

      {children}
    </div>
  );
}
