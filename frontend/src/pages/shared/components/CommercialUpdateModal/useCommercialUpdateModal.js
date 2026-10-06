import { useState, useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { updateUserApi, listRingCentralDevicesApi } from '@/api/shared.api.js'
import { listEntreprisesApi } from '@/api/admin.api.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'
import { deviceNumber } from '@/utils/ringcentral.js'

export function useCommercialUpdateModal(props) {
  const { open, onClose, user, queryKey } = props
  const qc = useQueryClient()
  const [error, setError] = useState(null)

  const { data: enterprisesData } = useQuery({
    queryKey: ['entreprises-select'],
    queryFn: () => listEntreprisesApi({ per_page: 100 }),
    enabled: open,
  })

  const enterprises = enterprisesData?.data?.entreprises ?? enterprisesData?.data?.utilisateurs ?? []

  // Appareils RingCentral (libellé = numéro) — select « Appareil / numéro
  // source » de la modale, prérempli avec l'appareil déjà enregistré.
  const { data: devicesData, isLoading: devicesLoading, isError: devicesUnavailable } = useQuery({
    queryKey: ['ringcentral-devices'],
    queryFn: listRingCentralDevicesApi,
    enabled: open,
    staleTime: 1000 * 60 * 5,
    retry: false,
  })

  const devices = devicesData?.data ?? []

  const profil = user?.profil
  const [form, setForm] = useState({
    email: '', first_name: '', last_name: '', phone: '',
    enterprise_id: '', additional_info: '',
    ringcentral_device_id: '', ringcentral_from_number: '',
  })

  useEffect(() => {
    if (open && user) {
      setForm({
        email: user.email ?? '',
        first_name: user.first_name ?? profil?.prenom ?? '',
        last_name: user.last_name ?? profil?.nom ?? '',
        phone: user.phone ?? profil?.telephone ?? '',
        enterprise_id: profil?.entreprise_id ? String(profil.entreprise_id) : '',
        additional_info: profil?.info_supp ?? '',
        ringcentral_device_id: profil?.ringcentral_device_id ?? '',
        ringcentral_from_number: profil?.ringcentral_from_number ?? '',
      })
      setError(null)
    }
  }, [open, user?.id])

  useEffect(() => {
    if (!open) return
    const onKey = (e) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  const mutation = useMutation({
    mutationFn: (payload) => updateUserApi(user.id, payload),
    onSuccess: () => {
      toast.success('Employé mis à jour.')
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

  /** Choix d'un appareil → on met à jour le numéro « from » affiché. */
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
    // l'API), en secours celui déjà enregistré. `null` explicite pour
    // pouvoir **retirer** la source (`sometimes|nullable` côté API).
    const selected = devices.find((d) => String(d.id) === String(form.ringcentral_device_id))
    const fromNumber = form.ringcentral_device_id
      ? (deviceNumber(selected) || form.ringcentral_from_number || null)
      : null

    const payload = {
      email: form.email,
      first_name: form.first_name,
      last_name: form.last_name,
      phone: form.phone || null,
      enterprise_id: form.enterprise_id ? Number(form.enterprise_id) : null,
      additional_info: form.additional_info || null,
      ringcentral_device_id: form.ringcentral_device_id || null,
      ringcentral_from_number: fromNumber,
    }
    mutation.mutate(payload)
  }

  return {
    form, error, isPending: mutation.isPending, enterprises, set, handleSubmit,
    devices, devicesLoading, devicesUnavailable, onDeviceChange,
  }
}