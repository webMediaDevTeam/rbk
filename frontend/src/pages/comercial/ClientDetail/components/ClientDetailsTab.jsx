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
      <DetailSection title="Identification">
        <DetailRow label="E-mail" value={client.email} />
        {/* Numéro masqué (absent de la réponse) sauf pour un admin ou le
            commercial qui détient la réservation en cours du client. */}
        {hasPhone && <DetailRow label="Téléphone" value={client.phone} />}
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

      {/* Le représentant est aussi un répondant : il est affiché ici, sous son
          propre libellé, et retiré de la liste « Répondants » (pas de doublon,
          pas de « Répondants (0) »). Le bloc « Répondants » ne reste que s'il
          reste des répondants autres que le représentant. */}
      {hasRepresentative && (
        <DetailSection title="Représentant">
          <p className="text-sm text-foreground">{representativeName}</p>
        </DetailSection>
      )}

      {hasRespondents && (
        <div className="space-y-3">
          <h3 className="text-sm font-semibold text-foreground border-b border-border pb-2">
            Répondants ({respondentsCount})
          </h3>
          <div className="space-y-2">
            {respondentList.map((r, i) => (
              <div key={i} className="flex items-center gap-2 text-sm">
                <span className="font-medium">{r.name}</span>
                {r.role && <span className="text-xs text-muted-foreground">({r.role})</span>}
              </div>
            ))}
          </div>
        </div>
      )}

      {hasActivity && (
        <DetailSection title="Activité">
          <DetailRow label="Réservations" value={client.reservations_count} />
          <DetailRow label="Notes" value={client.notes_count} />
        </DetailSection>
      )}
    </div>
  )
}