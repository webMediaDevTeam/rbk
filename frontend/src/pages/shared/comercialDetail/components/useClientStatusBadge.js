const STYLES = {
  AVAILABLE: 'secondary',
  RESERVED: 'info',
  VOICEMAIL: 'warning',
  INJOINABLE: 'warning',
  BLACKLISTED: 'destructive',
  OUI: 'success',
  NON: 'secondary',
  BOITE_VOCALE: 'warning',
}

const LABELS = {
  AVAILABLE: 'Disponible',
  RESERVED: 'Réservé',
  VOICEMAIL: 'Boîte vocale',
  INJOINABLE: 'Injoignable',
  BLACKLISTED: 'Blacklisté',
  OUI: 'OUI',
  NON: 'NON',
  BOITE_VOCALE: 'Boîte vocale',
}

export function useClientStatusBadge({ status }) {
  return {
    variant: STYLES[status] ?? 'secondary',
    label: LABELS[status] ?? status ?? '—',
  }
}