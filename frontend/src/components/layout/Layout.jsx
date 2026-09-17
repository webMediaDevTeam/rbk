import { useState, useEffect } from "react";
import Sidebar from "./Sidebar";
import { ThemeModeDropdown, ProfileDropdown } from "./header-actions";
import { PanelLeftClose, PanelLeft } from "lucide-react";
import { useLocation } from "react-router-dom";

const MOBILE_BREAKPOINT = 860;

export default function Layout({ children, navGroups }) {
  const [collapsed, setCollapsed] = useState(() => window.innerWidth < MOBILE_BREAKPOINT);
  const [mobileOpen, setMobileOpen] = useState(false);
  const location = useLocation();

  useEffect(() => {
    const mq = window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT}px)`)
    const handler = (e) => {
      if (e.matches) {
        setCollapsed(true)
        setMobileOpen(false)
      }
    }
    mq.addEventListener('change', handler)
    if (mq.matches) setCollapsed(true)
    return () => mq.removeEventListener('change', handler)
  }, [])

  useEffect(() => {
    if (mobileOpen) setMobileOpen(false)
  }, [location.pathname])

  const currentTitle = navGroups
    ?.flatMap((g) => g.items)
    ?.find((item) => location.pathname === item.path)?.title ?? "Dashboard";

  return (
    <div className="layout">
      {/* Mobile overlay */}
      {mobileOpen && (
        <div
          className="layout-overlay"
          onClick={() => setMobileOpen(false)}
        />
      )}

      <Sidebar
        navGroups={navGroups}
        collapsed={collapsed && !mobileOpen}
        mobileOpen={mobileOpen}
        onClose={() => setMobileOpen(false)}
      />
      <div className="layout__content">
        <header className="layout__header">
          <button
            onClick={() => {
              if (window.innerWidth <= MOBILE_BREAKPOINT) {
                setMobileOpen((prev) => !prev)
              } else {
                setCollapsed((prev) => !prev)
              }
            }}
            className="layout__header-toggle"
            title={collapsed ? "Expand sidebar" : "Collapse sidebar"}
          >
            {collapsed ? <PanelLeft className="header-control__icon" /> : <PanelLeftClose className="header-control__icon" />}
          </button>
          <span className="layout__header-title">
            {currentTitle}
          </span>
          <div className="layout__header-actions">
            <ThemeModeDropdown />
            <ProfileDropdown />
          </div>
        </header>
        <main className="layout__main">
          {children}
        </main>
      </div>
    </div>
  );
}