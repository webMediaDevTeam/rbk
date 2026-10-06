import { Mail, Phone } from 'lucide-react'
import Badge from '@/components/ui/badge.jsx'
import { useClientDetailsTab } from './useClientDetailsTab.js'

function DetailRow({ label, value }) {
  return (
    <div>
      <span className="text-muted-foreground text-xs">{label}</span>
      <p className="text-sm text-foreground">{value ?? '—'}</p>
    </div>
  )
}

function DetailSection({ title, children }) {
  return (
    <div className="space-y-3">
      <h3 className="text-sm font-semibold text-foreground border-b border-border pb-2">{title}</h3>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        {children}
      </div>
    </div>
  )
}

export default function ClientDetailsTab({ client }) {
  const {
    licenceStartDate,
    licenceEndDate,
    suretyAmount,
    licencePropre,
    licencePropreNumero,
    suretyCompanyList,
    suretyCompanies,
    categoryList,
    respondentList,
    respondentsCount,
    hasCategories,
    hasRespondents,
    hasRepresentative,
    hasActivity,
    hasPhone,
    representativeName,
  } = useClientDetailsTab({ client })

  return (
    <div className="space-y-6">
      {/* Contact — **en tête de fiche** : le téléphone et les répondants sont
          les deux infos qu'on cherche en premier, rendus en **badges info**
          colorés (même traitement que les catégories). Le numéro n'apparaît
          que si l'API l'a envoyé (admin, ou employé qui détient la
          réservation en cours — `hasPhone`). */}
      <div className="space-y-3">
        <h3 className="text-sm font-semibold text-foreground border-b border-border pb-2">Contact</h3>
        <div className="flex flex-wrap items-center gap-1.5">
          {hasPhone && (
            <Badge variant="info" title={client.phone}>
              <Phone className="h-3.5 w-3.5 mr-1" />
              {client.phone}
            </Badge>
          )}
          {client.email && (
            <a href={`mailto:${client.email}`} title={client.email}>
              <Badge variant="info">
                <Mail className="h-3.5 w-3.5 mr-1" />
                {client.email}
              </Badge>
            </a>
          )}
          {!hasPhone && !client.email && (
            <span className="text-sm text-muted-foreground">Aucune coordonnée.</span>
          )}
        </div>

        {hasRepresentative && (
          <div className="flex flex-wrap gap-1.5">
            <Badge variant="info" title="Représentant">Représentant : {representativeName}</Badge>
          </div>
        )}

        {hasRespondents && (
          <div className="space-y-2">
            <p className="text-xs text-muted-foreground">Répondants ({respondentsCount})</p>
            <div className="flex flex-wrap gap-1.5">
              {respondentList.map((r, i) => (
                <Badge key={i} variant="info" title={r.role ?? undefined}>
                  Répondant : {r.name}
                  {r.role ? ` — ${r.role}` : ''}
                </Badge>
              ))}
            </div>
          </div>
        )}
      </div>

      <DetailSection title="Identification">
        {/* E-mail et téléphone remontés en badges « Contact » ci-dessus. */}
        <DetailRow label="NEQ" value={client.neq} />
        <DetailRow label="Municipalité" value={client.municipality} />
        <DetailRow label="Région administrative" value={client.administrative_region} />
        <DetailRow label="Adresse complète" value={client.full_address} />
      </DetailSection>

      <DetailSection title="Licence">
        <DetailRow label="Numéro de licence" value={client.licence_number} />
        <DetailRow label="Licence (propre) n°" value={licencePropreNumero} />
        <DetailRow label="Statut" value={client.licence_status} />
        <DetailRow label="Intervenant / Entreprise" value={client.intervenant_name} />
        <DetailRow label="Licence propre" value={licencePropre} />
        <DetailRow label="Date de début / délivrance" value={licenceStartDate} />
        <DetailRow label="Date de fin / paiement annuel" value={licenceEndDate} />
      </DetailSection>

      {hasCategories && (
        <div className="space-y-3">
          <h3 className="text-sm font-semibold text-foreground border-b border-border pb-2">
            Catégories et sous-catégories autorisées
          </h3>
          <div className="flex flex-wrap gap-1.5">
            {categoryList.map((cat, i) => (
              <Badge key={i} variant="info">{cat}</Badge>
            ))}
          </div>
        </div>
      )}

      <DetailSection title="Cautionnement">
        <DetailRow label="Compagnie / Association" value={suretyCompanyList} />
        <DetailRow label="Montant de la caution ($)" value={suretyAmount} />
      </DetailSection>

      {suretyCompanies.length > 1 && (
        <div className="space-y-3">
          <h3 className="text-sm font-semibold text-foreground border-b border-border pb-2">
            Cautionnements ({suretyCompanies.length})
          </h3>
          <div className="flex flex-wrap gap-1.5">
            {suretyCompanies.map((c, i) => (
              <Badge key={i} variant="secondary">{c}</Badge>
            ))}
          </div>
        </div>
      )}

      {/* Le représentant et les répondants ont leur bloc « Contact » en tête
          de fiche (badges info) : plus de doublon en bas. */}
      {hasActivity && (
        <DetailSection title="Activité">
          <DetailRow label="Réservations" value={client.reservations_count} />
          <DetailRow label="Notes" value={client.notes_count} />
        </DetailSection>
      )}
    </div>
  )
}