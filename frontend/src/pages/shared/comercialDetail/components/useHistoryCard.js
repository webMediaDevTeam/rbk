export function useHistoryCard({ client, onViewDetail }) {
  const licenceText = client.licence_end_date ? `Licence: ${new Date(client.licence_end_date).toLocaleDateString('fr-FR')}` : '—'

  const handleCardClick = () => onViewDetail?.(client)
  const handleEyeClick = (e) => {
    e.stopPropagation()
    onViewDetail?.(client)
  }

  return {
    client,
    licenceText,
    handleCardClick,
    handleEyeClick,
  }
}