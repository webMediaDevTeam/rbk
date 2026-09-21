export default function LoginModeSwitch({ modes, activeIndex }) {
  return (
    <div
      className="auth-role-switch"
      role="tablist"
      aria-label="Choix du mode de connexion"
      data-active={activeIndex}
    >
      {modes.map((m) => (
        <button
          key={m.id}
          type="button"
          role="tab"
          aria-selected={m.isActive}
          onClick={m.onClick}
          className={m.className}
        >
          <m.icon size={14} />
          {m.label}
        </button>
      ))}
    </div>
  );
}
