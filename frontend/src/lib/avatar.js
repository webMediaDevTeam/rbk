export function buildAvatarUrl(entity) {
  if (!entity) return null
  if (entity.logo_url) return entity.logo_url
  if (entity.role === 'COMERCIAL') {
    return entity.profil?.image_dp_url ?? entity.avatar_url ?? null
  }
  return entity.avatar_url ?? null
}

export function getAvatarInitials(entity) {
  if (!entity) return 'U'
  const { role, email, profil, first_name, last_name, name } = entity
  if (name) {
    return name.split(' ').filter(Boolean).map((n) => n[0]).join('').slice(0, 2).toUpperCase()
  }
  if (role === 'COMERCIAL') {
    const prenom = profil?.prenom?.[0] ?? first_name?.[0] ?? ''
    const nom = profil?.nom?.[0] ?? last_name?.[0] ?? ''
    return (prenom + nom || email?.split('@')[0] || 'C').slice(0, 2).toUpperCase()
  }
  const f = first_name?.[0] ?? ''
  const l = last_name?.[0] ?? ''
  return (f + l || email?.split('@')[0] || 'A').slice(0, 2).toUpperCase()
}
