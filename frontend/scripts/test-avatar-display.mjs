// Vérification de l'**affichage** des images téléversées (photo d'employé,
// logo d'entreprise) : la résolution de l'URL et le repli sur les initiales.
// Node pur, sans dépendance : `src/lib/avatar.js` est du JS sans JSX.
//
//   node scripts/test-avatar-display.mjs
//
// Ces deux fonctions sont le seul point commun de tous les avatars de
// l'application (`UserAvatar`, `AvatarUpdateModal` qui affiche l'image
// actuelle avant envoi). Les règles testées ici sont celles qui cassent en
// silence quand une projection change de forme :
//   1. un logo d'entreprise prime — la ligne entreprise n'a pas de photo ;
//   2. pour un COMERCIAL, la photo de la fiche (`profil.image_dp_url`) passe
//      avant l'avatar du compte, avec repli sur l'avatar si la fiche n'a pas
//      de photo ;
//   3. un rôle sans fiche (`ADMIN` / `OPERATOR`) n'utilise que `avatar_url` ;
//   4. aucune image → `null` (et non une URL vide qui afficherait un cliché
//      cassé), ce qui laisse `UserAvatar` rendre les initiales.
//
// Les fixtures sont des chemins **relatifs** (`/storage/…`), la forme réellement
// rendue par l'API depuis que les images doivent être demandées sur l'origine du
// navigateur et non sur le port de l'API.

import assert from 'node:assert/strict'
import { buildAvatarUrl, getAvatarInitials } from '../src/lib/avatar.js'

let failures = 0

function check(label, fn) {
  try {
    fn()
    console.log(`  ✓ ${label}`)
  } catch (error) {
    failures += 1
    console.error(`  ✗ ${label}\n    ${error.message}`)
  }
}

console.log('1. Quelle image afficher')
check('logo entreprise — prime sur tout le reste', () => {
  assert.equal(
    buildAvatarUrl({ logo_url: '/storage/logos/alpha.png', avatar_url: '/storage/avatars/compte.png' }),
    '/storage/logos/alpha.png'
  )
})
check('commercial — la photo de la fiche passe avant l\'avatar du compte', () => {
  assert.equal(
    buildAvatarUrl({
      role: 'COMERCIAL',
      avatar_url: '/storage/avatars/compte.png',
      profil: { image_dp_url: '/storage/avatars/fiche.png' },
    }),
    '/storage/avatars/fiche.png'
  )
})
check('commercial — repli sur l\'avatar quand la fiche n\'a pas de photo', () => {
  assert.equal(
    buildAvatarUrl({ role: 'COMERCIAL', avatar_url: '/storage/avatars/compte.png', profil: { image_dp_url: null } }),
    '/storage/avatars/compte.png'
  )
})
check('commercial — ni fiche ni avatar → aucune URL', () => {
  assert.equal(buildAvatarUrl({ role: 'COMERCIAL', profil: {} }), null)
  assert.equal(buildAvatarUrl({ role: 'COMERCIAL' }), null)
})
check('rôle sans fiche — la photo de fiche n\'est jamais utilisée', () => {
  assert.equal(
    buildAvatarUrl({ role: 'ADMIN', avatar_url: '/storage/avatars/admin.png', profil: { image_dp_url: '/storage/avatars/photo-fiche.png' } }),
    '/storage/avatars/admin.png'
  )
  assert.equal(buildAvatarUrl({ role: 'OPERATOR', profil: { image_dp_url: '/storage/avatars/photo-fiche.png' } }), null)
})
check('entité absente → null (pas d\'exception sur une ligne en cours de chargement)', () => {
  assert.equal(buildAvatarUrl(undefined), null)
  assert.equal(buildAvatarUrl(null), null)
})

console.log('2. Initiales de repli')
check('entreprise — nom complet', () => {
  assert.equal(getAvatarInitials({ name: 'Construction Alpha' }), 'CA')
})
check('entreprise — nom d\'un seul mot ne lève rien', () => {
  assert.equal(getAvatarInitials({ name: 'Alpha' }), 'A')
})
check('commercial — prénom / nom de la fiche', () => {
  assert.equal(getAvatarInitials({ role: 'COMERCIAL', profil: { prenom: 'Jane', nom: 'Doe' }, email: 'jane@ex.test' }), 'JD')
})
check('commercial — à défaut de fiche, les champs du compte', () => {
  assert.equal(getAvatarInitials({ role: 'COMERCIAL', first_name: 'Jean', last_name: 'Tremblay', email: 'jt@ex.test' }), 'JT')
})
check('commercial — repli sur l\'e-mail quand aucun nom n\'est renseigné', () => {
  assert.equal(getAvatarInitials({ role: 'COMERCIAL', email: 'chanteur@ex.test', profil: {} }), 'CH')
})
check('gestionnaire — prénom / nom du compte', () => {
  assert.equal(getAvatarInitials({ role: 'ADMIN', first_name: 'Paul', last_name: 'Op', email: 'po@ex.test' }), 'PO')
})
check('entité absente → « U »', () => {
  assert.equal(getAvatarInitials(null), 'U')
})

if (failures > 0) {
  console.error(`\n${failures} échec(s).`)
  process.exit(1)
}
console.log('\nTous les scénarios passent.')
