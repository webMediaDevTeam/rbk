const initials = (name = '') =>
  name
    .split(' ')
    .filter(Boolean)
    .map((part) => part[0])
    .slice(0, 2)
    .join('')
    .toUpperCase()

export function useTopCommercialsCard({ commercials = [] } = {}) {
  const max = Math.max(1, ...commercials.map((c) => c.clients_oui || 0))

  const items = commercials.map((commercial) => ({
    ...commercial,
    initials: initials(commercial.name),
    pct: Math.round(((commercial.clients_oui || 0) / max) * 100),
  }))

  return { items }
}
