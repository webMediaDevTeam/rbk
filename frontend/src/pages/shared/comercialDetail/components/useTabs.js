export function useTabs({ tabs, active, onChange }) {
  const items = tabs.map((tab) => ({
    key: tab.value,
    label: tab.label,
    className: `px-4 py-2 text-sm font-medium rounded-t-lg border-b-2 transition-colors ${
      active === tab.value
        ? 'border-primary text-foreground bg-card'
        : 'border-transparent text-muted-foreground hover:text-foreground'
    }`,
    onClick: () => onChange(tab.value),
  }))

  return { items }
}