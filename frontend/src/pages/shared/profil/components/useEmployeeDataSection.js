export function useEmployeeDataSection({ user }) {
  const profil = user?.profil ?? {}
  const entreprise = profil.entreprise ?? {}

  return {
    profil,
    entreprise,
    prenom: profil.prenom ?? '',
    nom: profil.nom ?? '',
    telephone: profil.telephone ?? '',
    infoSupp: profil.info_supp ?? '',
    entrepriseName: entreprise.name || '—',
    entrepriseLogoAlt: entreprise.name ?? 'Logo entreprise',
  }
}