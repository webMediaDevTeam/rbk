import { Building2, Mail, Phone } from 'lucide-react'
import { useCommercialInfoCard } from './useCommercialInfoCard.js'
import { ROLE_LABELS } from '@/pages/shared/profil/components/SettingsSidebar.jsx'

export default function CommercialInfoCard(props) {
  const {
    employee,
    entreprise,
    name,
    email,
    phone,
    avatarUrl,
    companyName,
    initials,
    role,
    employeeStatus,
    additionalInfo,
    inscriptionDate,
    entrepriseStatus,
    entrepriseEmail,
    entreprisePhone,
    entrepriseAddress,
    entrepriseTaxNumber,
  } = useCommercialInfoCard(props)

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="rounded-xl bg-card text-card-foreground shadow-sm p-6 space-y-6">
        {/* Employé */}
        <div className="space-y-4">
          <div className="text-sm font-semibold text-muted-foreground">Employé</div>
          <div className="flex items-center gap-3">
            {avatarUrl ? (
              <img src={avatarUrl} alt={name} className="h-12 w-12 rounded-full object-cover" />
            ) : (
              <div className="h-12 w-12 rounded-full bg-muted flex items-center justify-center text-sm font-bold">
                {initials}
              </div>
            )}
            <div>
              <div className="text-lg font-semibold">{name}</div>
              <div className="text-sm text-muted-foreground">Employé</div>
            </div>
          </div>
          <div className="space-y-2 text-sm">
            <div className="flex items-center gap-2"><Mail className="h-4 w-4 text-muted-foreground" /> {email}</div>
            <div className="flex items-center gap-2"><Phone className="h-4 w-4 text-muted-foreground" /> {phone}</div>
            <div className="flex items-center gap-2"><Building2 className="h-4 w-4 text-muted-foreground" /> Rôle : <span className="uppercase text-[11px] font-bold">{ROLE_LABELS[role] ?? role}</span></div>
            <div className="flex items-center gap-2"><span className="inline-flex items-center rounded bg-muted px-2 py-0.5 text-xs font-semibold">Statut</span> {employeeStatus}</div>
            {additionalInfo && (
              <div><span className="inline-flex items-center rounded bg-muted px-2 py-0.5 text-xs font-semibold">Notes</span><p className="mt-1 text-muted-foreground">{additionalInfo}</p></div>
            )}
            {inscriptionDate && (
              <div className="text-xs text-muted-foreground">Inscrit le {inscriptionDate}</div>
            )}
          </div>
        </div>

        <div className="border-t border-border" />

        {/* Entreprise */}
        <div className="space-y-4">
          <div className="text-sm font-semibold text-muted-foreground">Entreprise</div>
          {entreprise ? (
            <>
              <div className="flex items-center gap-3">
                {entreprise.logo_url ? (
                  <img src={entreprise.logo_url} alt={companyName} className="h-12 w-12 rounded-lg object-cover" />
                ) : (
                  <div className="h-12 w-12 rounded-lg bg-muted flex items-center justify-center">
                    <Building2 className="h-6 w-6 text-muted-foreground" />
                  </div>
                )}
                <div>
                  <div className="text-lg font-semibold">{companyName}</div>
                  <div className="text-sm text-muted-foreground">{entrepriseStatus}</div>
                </div>
              </div>
              <div className="space-y-2 text-sm">
                <div className="flex items-center gap-2"><Mail className="h-4 w-4 text-muted-foreground" /> {entrepriseEmail}</div>
                <div className="flex items-center gap-2"><Phone className="h-4 w-4 text-muted-foreground" /> {entreprisePhone}</div>
                <div className="flex items-center gap-2"><Building2 className="h-4 w-4 text-muted-foreground" /> {entrepriseAddress}</div>
                <div className="flex items-center gap-2"><span className="inline-flex items-center rounded bg-muted px-2 py-0.5 text-xs font-semibold">N° fiscal</span> {entrepriseTaxNumber}</div>
              </div>
            </>
          ) : (
            <div className="text-sm text-muted-foreground">Aucune entreprise associée.</div>
          )}
        </div>
      </div>
    </div>
  )
}