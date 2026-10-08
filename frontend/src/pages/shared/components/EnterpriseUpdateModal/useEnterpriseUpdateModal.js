import { useState, useEffect, useMemo } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { updateEntrepriseApi } from '@/api/admin.api.js'
import { listSourcesApi } from '@/api/shared.api.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'

export function useEnterpriseUpdateModal(props) {
  const { open, onClose, user: entreprise, queryKey } = props
  const qc = useQueryClient()
  const [error, setError] = useState(null)

  const [form, setForm] = useState({
    email: '', name: '', tax_number: '', phone: '', address: '',
    ringcentral_client_id: '', ringcentral_client_secret: '',
    ringcentral_token: '', source: '',
  })

  useEffect(() => {
    if (open && entreprise) {
      setForm({
        email: entreprise.email ?? '',
        name: entreprise.name ?? entreprise.profil?.nom ?? '',
        tax_number: entreprise.tax_number ?? entreprise.profil?.numero_fiscal ?? '',
        phone: entreprise.phone ?? entreprise.profil?.telephone ?? '',
        address: entreprise.address ?? entreprise.profil?.adresse ?? '',
        // `client_id` / `source` reviennent de l'API ; le secret et le jeton
        // **ne reviennent jamais** (on ne renvoie que « enregistré ») : on
        // repart d'un champ vide et « vide = conserver » à l'envoi.
        ringcentral_client_id: entreprise.ringcentral_client_id ?? '',
        ringcentral_client_secret: '',
        ringcentral_token: '',
        source: entreprise.source ?? '',
      })
      setError(null)
    }
  }, [open, entreprise?.id])

  useEffect(() => {
    if (!open) return
    const onKey = (e) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  // Sources — table `sources` lue via `GET /sources` (répertoire **fermé**,
  // aucun CRUD) : elle alimente le sélecteur « Source » du formulaire.
  const { data: sourcesData, isLoading: sourcesLoading, isError: sourcesUnavailable } = useQuery({
    queryKey: ['sources'],
    queryFn: () => listSourcesApi(),
    enabled: open,
    staleTime: 1000 * 60 * 5,
    retry: false,
  })

  const sources = useMemo(() => sourcesData?.data ?? [], [sourcesData])

  // Options du sélecteur : lignes du répertoire (+ la valeur enregistrée,
  // au cas où elle aurait disparu de la table).
  const sourceOptions = useMemo(() => {
    if (!form.source || sources.some((s) => s.name === form.source)) return sources

    return [...sources, { id: `current:${form.source}`, name: form.source }]
  }, [sources, form.source])

  const mutation = useMutation({
    mutationFn: (payload) => updateEntrepriseApi(entreprise.id, payload),
    onSuccess: () => {
      toast.success('Entreprise mise à jour avec succès.')
      if (queryKey) qc.invalidateQueries({ queryKey })
      qc.invalidateQueries({ queryKey: ['entreprises-select'] })
      onClose()
    },
    onError: (err) => {
      const msg = getApiErrorMessage(err)
      setError(msg)
      toast.error(msg)
    },
  })

  const set = (key, val) => setForm((p) => ({ ...p, [key]: val }))

  const handleSubmit = (e) => {
    e.preventDefault()
    setError(null)
    if (!form.name.trim()) {
      setError('Le nom de l\'entreprise est requis.')
      return
    }
    const payload = {
      name: form.name.trim(),
      email: form.email.trim() || null,
      tax_number: form.tax_number.trim() || null,
      phone: form.phone.trim() || null,
      address: form.address.trim() || null,
    }

    // Champs « sensibles » envoyés **uniquement s'ils diffèrent** de ce que
    // l'entreprise avait déjà : champ non touché = valeur enregistrée
    // conservée (le secret et le jeton ne reviennent jamais de l'API),
    // champ vidé après modification = valeur retirée. `source` (choix dans
    // `GET /sources`) suit la même règle : la sélection affichée est celle
    // déjà enregistrée, seul un changement est renvoyé.
    for (const key of ['ringcentral_client_id', 'ringcentral_client_secret', 'ringcentral_token', 'source']) {
      const value = (form[key] ?? '').trim()
      const initial = (entreprise?.[key] ?? '').trim()

      if (value !== initial) payload[key] = value
    }

    mutation.mutate(payload)
  }

  return {
    form, error, isPending: mutation.isPending, set, handleSubmit,
    sourceOptions, sourcesLoading, sourcesUnavailable,
  }
}