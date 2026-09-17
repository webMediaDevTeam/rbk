import { X } from "lucide-react";
import { useNavigate, useLocation } from "react-router-dom";
import { useTheme } from "../../context/theme-provider";
import faviconLight from "../../assets/brand-icons/rbq_1_1_app_icon_app_icon.svg";
import faviconDark from "../../assets/brand-icons/rbq_1_1_app_icon_inverted_white_bg.svg";

const SIDEBAR_WIDTH = 256;
const SIDEBAR_COLLAPSED_WIDTH = 64;

export default function Sidebar({ navGroups, collapsed, mobileOpen, onClose }) {
  const { resolvedTheme } = useTheme();
  const logoSrc = resolvedTheme === "dark" ? faviconDark : faviconLight;
  const navigate = useNavigate();
  const location = useLocation();

  const handleNav = (path) => {
    navigate(path);
    if (mobileOpen) onClose?.();
  };

  return (
    <aside
      className={`sidebar ${collapsed ? "sidebar--collapsed" : ""} ${mobileOpen ? "sidebar--mobile-open" : ""}`}
      style={{
        width: collapsed ? SIDEBAR_COLLAPSED_WIDTH : SIDEBAR_WIDTH,
      }}
    >
      {/* Logo + title */}
      <div className="sidebar__brand">
        <img src={logoSrc} alt="RBQ" className="sidebar__logo" />
        <div className="sidebar__brand-text">
          <span className="sidebar__title">RBQ</span>
          <span className="sidebar__subtitle">AI Voice</span>
        </div>
        {mobileOpen && (
          <button
            onClick={onClose}
            className="sidebar__close"
            aria-label="Close sidebar"
          >
            <X className="h-4 w-4" />
          </button>
        )}
      </div>

      {/* Nav groups */}
      <div className="sidebar__nav">
        {navGroups.map((group) => (
          <div className="sidebar__group" key={group.title}>
            <span className="sidebar__group-label">{group.title}</span>
            {group.items.map((item) => {
              const Icon = item.icon;
              const isActive = location.pathname === item.path;
              return (
                <button
                  key={item.path}
                  onClick={() => handleNav(item.path)}
                  className={`sidebar__item ${isActive ? "sidebar__item--active" : ""}`}
                  title={collapsed ? item.title : undefined}
                >
                  <Icon className="sidebar__icon" />
                  <span className="sidebar__label">{item.title}</span>
                </button>
              );
            })}
          </div>
        ))}
      </div>
    </aside>
  );
}