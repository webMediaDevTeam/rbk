import RemindersPage from '@/pages/comercial/Reminders/index.jsx'

/**
 * Page « Auto-rappels » : rappels automatiques de boîte vocale (BV, 3 jours).
 * Réutilise la liste générique de la page « Rappels » (INJOINABLE).
 */
export default function AutoRappelsPage() {
  return (
    <RemindersPage
      type="BV"
      title="Auto-rappels"
      subtitle="Rappels automatiques de boîte vocale planifiés sous 3 jours."
      emptyText="Aucun auto-rappel en attente."
    />
  )
}
