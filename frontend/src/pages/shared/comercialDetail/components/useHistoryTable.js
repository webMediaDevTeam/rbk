export function useHistoryTable({ clients, onViewDetail }) {
  const rows = clients.map((c) => {
    const licenceDate = c.licence_end_date ? new Date(c.licence_end_date).toLocaleDateString('fr-FR') : '—'
    const handleRowClick = () => onViewDetail?.(c)
    const handleEyeClick = (e) => {
      e.stopPropagation()
      onViewDetail?.(c)
    }
    return {
      key: c.id,
      c,
      licenceDate,
      handleRowClick,
      handleEyeClick,
    }
  })

  return { rows }
}