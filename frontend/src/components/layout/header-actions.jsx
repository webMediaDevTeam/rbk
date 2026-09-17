import { useEffect, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Check, LogOut, Moon, Monitor, Sun, UserRound } from "lucide-react";
import { useTheme } from "@/context/theme-provider";
import { useAuth } from "@/context/AuthContext";

function useDropdown() {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    if (!open) return;
    const onPointerDown = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    };
    const onKeyDown = (e) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", onPointerDown);
    document.addEventListener("keydown", onKeyDown);
    return () => {
      document.removeEventListener("mousedown", onPointerDown);
      document.removeEventListener("keydown", onKeyDown);
    };
  }, [open]);

  return { ref, open, setOpen };
}

const THEME_OPTIONS = [
  { value: "light", label: "Light", icon: Sun },
  { value: "dark", label: "Dark", icon: Moon },
  { value: "system", label: "System", icon: Monitor },
];

export function ThemeModeDropdown() {
  const { theme, setTheme } = useTheme();
  const { ref, open, setOpen } = useDropdown();
  const current = THEME_OPTIONS.find((o) => o.value === theme) ?? THEME_OPTIONS[2];
  const CurrentIcon = current.icon;

  return (
    <div className="header-dropdown" ref={ref}>
      <button
        className="header-control header-control--round"
        onClick={() => setOpen((prev) => !prev)}
        title="Theme"
        aria-haspopup="menu"
        aria-expanded={open}
      >
        <CurrentIcon className="header-control__icon" />
      </button>
      {open && (
        <div className="header-dropdown__menu header-dropdown__menu--right" role="menu">
          <div className="header-dropdown__label">Theme</div>
          {THEME_OPTIONS.map((option) => {
            const OptionIcon = option.icon;
            const isActive = option.value === theme;
            return (
              <button
                key={option.value}
                role="menuitemradio"
                aria-checked={isActive}
                className="header-dropdown__item"
                onClick={() => {
                  setTheme(option.value);
                  setOpen(false);
                }}
              >
                <OptionIcon className="header-dropdown__item-icon" />
                <span>{option.label}</span>
                {isActive && <Check className="header-dropdown__item-check" />}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

export function ProfileDropdown() {
  const { ref, open, setOpen } = useDropdown();
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const displayName = user?.email?.split("@")[0] ?? "User";
  const initials = displayName.slice(0, 2).toUpperCase();

  return (
    <div className="header-dropdown" ref={ref}>
      <button
        className="header-control header-control--avatar"
        onClick={() => setOpen((prev) => !prev)}
        title={displayName}
        aria-haspopup="menu"
        aria-expanded={open}
      >
        <span className="header-avatar">{initials}</span>
      </button>
      {open && (
        <div className="header-dropdown__menu header-dropdown__menu--right" role="menu">
          <div className="header-dropdown__header">
            <span className="header-avatar header-avatar--lg">{initials}</span>
            <div className="header-dropdown__identity">
              <span className="header-dropdown__name">{user?.email ?? "User"}</span>
              <span className="header-dropdown__email">{user?.role ?? ""}</span>
            </div>
          </div>
          <div className="header-dropdown__divider" />
          <button
            role="menuitem"
            className="header-dropdown__item"
            onClick={() => {
              navigate("/profil");
              setOpen(false);
            }}
          >
            <UserRound className="header-dropdown__item-icon" />
            <span>Mon profil</span>
          </button>
          <div className="header-dropdown__divider" />
          <button
            role="menuitem"
            className="header-dropdown__item header-dropdown__item--danger"
            onClick={() => {
              logout();
              navigate("/connexion");
              setOpen(false);
            }}
          >
            <LogOut className="header-dropdown__item-icon" />
            <span>Se déconnecter</span>
          </button>
        </div>
      )}
    </div>
  );
}