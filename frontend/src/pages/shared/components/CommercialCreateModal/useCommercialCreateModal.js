import { useState, useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { createUserApi, listRingCentralDevicesApi } from '@/api/shared.api.js'
import { listEntreprisesApi } from '@/api/admin.api.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'
import { deviceNumber } from '@/utils/ringcentral.js'

export function useCommercialCreateModal(props) {
  const { open, onClose, queryKey } = props
  const qc = useQueryClient()

  const { data: enterprisesData } = useQuery({
    queryKey: ['entreprises-select'],
    queryFn: () => listEntreprisesApi({ per_page: 100 }),
    enabled: open,
  })

  const enterprises = enterprisesData?.data?.entreprises ?? enterprisesData?.data?.utilisateurs ?? []

  // Appareils RingCentral (libellé = numéro) — select « Appareil / numéro
  // source » de la modale. `retry: false` : une panne de l'API ne doit pas
  // réessayer en boucle, on affiche simplement « aucun appareil ».
  const { data: devicesData, isLoading: devicesLoading, isError: devicesUnavailable } = useQuery({
    queryKey: ['ringcentral-devices'],
    queryFn: listRingCentralDevicesApi,
    enabled: open,
    staleTime: 1000 * 60 * 5,
    retry: false,
  })

  const devices = devicesData?.data ?? []

  const [form, setForm] = useState({
    email: '', first_name: '', last_name: '', phone: '',
    enterprise_id: '', additional_info: '',
    ringcentral_device_id: '', ringcentral_from_number: '',
    has_permission: false,
  })
  const [error, setError] = useState(null)

  useEffect(() => {
    if (open) {
      setForm({
        email: '', first_name: '', last_name: '', phone: '',
        enterprise_id: '',
        additional_info: '',
        ringcentral_device_id: '', ringcentral_from_number: '',
        has_permission: false,
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
    mutationFn: (payload) => createUserApi(payload),
    onSuccess: () => {
      toast.success('Employé créé.')
      qc.invalidateQueries({ queryKey })
      onClose()
    },
    onError: (err) => {
      const msg = getApiErrorMessage(err)
      setError(msg)
      toast.error(msg)
    },
  })

  const set = (key, val) => setForm((p) => ({ ...p, [key]: val }))

  /** Choix d'un appareil → on enregistre aussi le numéro « from » affiché. */
  const onDeviceChange = (deviceId) => {
    const device = devices.find((d) => String(d.id) === String(deviceId))
    setForm((p) => ({
      ...p,
      ringcentral_device_id: deviceId,
      ringcentral_from_number: device ? deviceNumber(device) : '',
    }))
  }

  const handleSubmit = (e) => {
    e.preventDefault()
    setError(null)
    if (!form.email) {
      setError('L\'adresse e-mail est requise.')
      return
    }
    if (!form.first_name || !form.last_name) {
      setError('Le prénom et le nom sont requis.')
      return
    }
    // Numéro « from » : celui de l'appareil choisi (valeur fraîche de
    // l'API), en secours celui déjà sélectionné dans le formulaire.
    const selected = devices.find((d) => String(d.id) === String(form.ringcentral_device_id))
    const fromNumber = deviceNumber(selected) || form.ringcentral_from_number || undefined

    const payload = {
      role: 'COMERCIAL',
      email: form.email,
      first_name: form.first_name,
      last_name: form.last_name,
      phone: form.phone || undefined,
      enterprise_id: form.enterprise_id ? Number(form.enterprise_id) : undefined,
      additional_info: form.additional_info || undefined,
      ringcentral_device_id: form.ringcentral_device_id || undefined,
      ringcentral_from_number: form.ringcentral_device_id ? fromNumber : undefined,
      // Privilège de libération (switch) → colonne `users.has_permission`.
      has_permission: form.has_permission,
    }
    mutation.mutate(payload)
  }

  return {
    form, error, isPending: mutation.isPending, enterprises, set, handleSubmit,
    devices, devicesLoading, devicesUnavailable, onDeviceChange,
  }
}