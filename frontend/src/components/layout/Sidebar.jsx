import { X } from "lucide-react";
import { useNavigate, useLocation } from "react-router-dom";
import { useTheme } from "@/context/theme-provider";
import { useRemindersCount, useActiveReservationsCount } from "@/pages/comercial/ClientDetail/useOutcomes.js";
import faviconLight from "@/assets/icons/light_logo.svg";
import faviconDark from "@/assets/icons/dark_logo.svg";

const SIDEBAR_WIDTH = 256;
const SIDEBAR_COLLAPSED_WIDTH = 64;

export default function Sidebar({ navGroups, collapsed, mobileOpen, onClose }) {
  const { resolvedTheme } = useTheme();
  const logoSrc = resolvedTheme === "dark" ? faviconDark : faviconLight;
  const navigate = useNavigate();
  const location = useLocation();

  const hasReminders = navGroups?.some((g) => g.items.some((i) => i.path === "/reminders"));
  const { data: remindersData } = useRemindersCount(hasReminders, "INJOINABLE");
  const remindersCount = remindersData?.data?.count ?? 0;

  const hasAutoRappels = navGroups?.some((g) => g.items.some((i) => i.path === "/auto-rappels"));
  const { data: autoRappelsData } = useRemindersCount(hasAutoRappels, "BV");
  const autoRappelsCount = autoRappelsData?.data?.count ?? 0;

  const hasMesListes = navGroups?.some((g) => g.items.some((i) => i.path === "/mes-listes"));
  const { data: activeResaData } = useActiveReservationsCount(hasMesListes);
  const activeReservationsCount = activeResaData?.data?.count ?? 0;

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
          <span className="sidebar__title">Zdig IA</span>
          
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
                  className={`sidebar__item ${isActive ? "sidebar__item--active" : ""} ${(item.path === "/reminders" && remindersCount > 0) || (item.path === "/auto-rappels" && autoRappelsCount > 0) || (item.path === "/mes-listes" && activeReservationsCount > 0) ? "sidebar__item--has-notifications" : ""}`}
                  title={collapsed ? item.title : undefined}
                >
                  <Icon className="sidebar__icon" />
                  <span className="sidebar__label">{item.title}</span>
                  {item.path === "/reminders" && remindersCount > 0 && (
                    <span
                      className="sidebar__notification"
                      aria-label={`${remindersCount} rappel(s) échu(s)`}
                      title={`${remindersCount} rappel(s) échu(s)`}
                      role="status"
                      aria-live="polite"
                    >
                      {remindersCount > 99 ? "99+" : remindersCount}
                    </span>
                  )}
                  {item.path === "/auto-rappels" && autoRappelsCount > 0 && (
                    <span
                      className="sidebar__notification"
                      aria-label={`${autoRappelsCount} auto-rappel(s) échu(s)`}
                      title={`${autoRappelsCount} auto-rappel(s) échu(s)`}
                      role="status"
                      aria-live="polite"
                    >
                      {autoRappelsCount > 99 ? "99+" : autoRappelsCount}
                    </span>
                  )}
                  {item.path === "/mes-listes" && activeReservationsCount > 0 && (
                    <span
                      className="sidebar__notification"
                      aria-label={`${activeReservationsCount} prospect(s) réservé(s)`}
                      title={`${activeReservationsCount} prospect(s) réservé(s)`}
                      role="status"
                      aria-live="polite"
                    >
                      {activeReservationsCount > 99 ? "99+" : activeReservationsCount}
                    </span>
                  )}
                </button>
              );
            })}
          </div>
        ))}
      </div>
    </aside>
  );
}