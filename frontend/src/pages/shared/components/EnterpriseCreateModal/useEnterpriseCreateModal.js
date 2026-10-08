import { useState, useEffect, useMemo } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { createEntrepriseApi } from '@/api/admin.api.js'
import { listSourcesApi } from '@/api/shared.api.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'

export function useEnterpriseCreateModal(props) {
  const { open, onClose, queryKey } = props
  const qc = useQueryClient()
  const [form, setForm] = useState({
    name: '', email: '', tax_number: '', phone: '', address: '',
    ringcentral_client_id: '', ringcentral_client_secret: '',
    ringcentral_token: '', source: '',
  })
  const [error, setError] = useState(null)

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

  // Options du sélecteur : lignes du répertoire (+ la valeur déjà saisie,
  // au cas où elle aurait disparu de la table).
  const sourceOptions = useMemo(() => {
    if (!form.source || sources.some((s) => s.name === form.source)) return sources

    return [...sources, { id: `current:${form.source}`, name: form.source }]
  }, [sources, form.source])

  useEffect(() => {
    if (open) {
      setForm({
        name: '', email: '', tax_number: '', phone: '', address: '',
        ringcentral_client_id: '', ringcentral_client_secret: '',
        ringcentral_token: '', source: '',
      })
      setError(null)
    }
  }, [open])

  useEffect(() => {
    if (!open) return
    const onKey = (e) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  const mutation = useMutation({
    mutationFn: (payload) => createEntrepriseApi(payload),
    onSuccess: () => {
      toast.success('Entreprise créée avec succès.')
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
      email: form.email.trim() || undefined,
      tax_number: form.tax_number.trim() || undefined,
      phone: form.phone.trim() || undefined,
      address: form.address.trim() || undefined,
      // Source choisie dans `GET /sources` (libellé stocké tel quel).
      source: form.source.trim() || undefined,
      // Compte RingCentral optionnel de l'entreprise : `undefined` =
      // clé absente du JSON (champ vide = pas de compte propre, repli `.env`).
      ringcentral_client_id: form.ringcentral_client_id.trim() || undefined,
      ringcentral_client_secret: form.ringcentral_client_secret.trim() || undefined,
      ringcentral_token: form.ringcentral_token.trim() || undefined,
    }
    mutation.mutate(payload)
  }

  return {
    form, error, isPending: mutation.isPending, set, handleSubmit,
    sourceOptions, sourcesLoading, sourcesUnavailable,
  }
}