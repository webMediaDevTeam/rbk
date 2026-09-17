const API_ORIGIN = import.meta.env.VITE_API_URL ?? 'http://localhost:8000'

export function buildAvatarUrl(user) {
  if (!user) return null
  const { role, avatar_url, profil } = user

  if (role === 'ENTREPRISE') {
    return profil?.logo_url ?? null
  }
  if (role === 'COMERCIAL') {
    return profil?.image_dp_url ?? null
  }
  return avatar_url ?? null
}

export function getAvatarInitials(user) {
  if (!user) return 'U'
  const { role, email, profil, first_name, last_name } = user
  if (role === 'ENTREPRISE') {
    const name = profil?.nom ?? email?.split('@')[0] ?? 'E'
    return name.split(' ').map((n) => n[0]).join('').slice(0, 2).toUpperCase()
  }
  if (role === 'COMERCIAL') {
    const prenom = profil?.prenom?.[0] ?? ''
    const nom = profil?.nom?.[0] ?? ''
    return (prenom + nom || 'C').toUpperCase()
  }
  const f = first_name?.[0] ?? ''
  const l = last_name?.[0] ?? ''
  return (f + l || email?.split('@')[0] || 'A').slice(0, 2).toUpperCase()
}