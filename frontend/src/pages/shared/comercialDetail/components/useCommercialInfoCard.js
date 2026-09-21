export function useCommercialInfoCard({ commercial, employee, entreprise, name, email, phone, avatarUrl, companyName }) {
  const initials = name.slice(0, 2).toUpperCase()
  const role = employee?.role ?? 'COMERCIAL'
  const employeeStatus = employee?.status ?? '—'
  const additionalInfo = employee?.additional_info ?? null
  const inscriptionDate = employee?.cree_le ? new Date(employee.cree_le).toLocaleDateString() : null
  const entrepriseStatus = entreprise?.status ?? '—'
  const entrepriseEmail = entreprise?.email ?? '—'
  const entreprisePhone = entreprise?.phone ?? '—'
  const entrepriseAddress = entreprise?.address ?? '—'
  const entrepriseTaxNumber = entreprise?.tax_number ?? '—'

  return {
    commercial,
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
  }
}