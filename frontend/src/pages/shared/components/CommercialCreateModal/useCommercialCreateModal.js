import { useState, useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { createUserApi, listRingCentralDevicesApi } from '@/api/shared.api.js'
import { listEntreprisesApi } from '@/api/admin.api.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'
import { deviceNumber, selectableDevices } from '@/utils/ringcentral.js'
import { useUsernameAvailability, sanitizeUsername, usernameFromEmail } from '@/hooks/use-username-availability.js'

export function useCommercialCreateModal(props) {
  const { open, onClose, queryKey } = props
  const qc = useQueryClient()

  const { data: enterprisesData } = useQuery({
    queryKey: ['entreprises-select'],
    queryFn: () => listEntreprisesApi({ per_page: 100 }),
    enabled: open,
  })

  const enterprises = enterprisesData?.data?.entreprises ?? enterprisesData?.data?.utilisateurs ?? []

  const [form, setForm] = useState({
    email: '', username: '', first_name: '', last_name: '', phone: '',
    mot_de_passe: '', mot_de_passe_confirmation: '',
    enterprise_id: '', additional_info: '',
    ringcentral_extension_id: '',
    ringcentral_device_id: '', ringcentral_from_number: '',
    has_permission: false,
  })
  // Nom d'utilisateur saisi à la main : on ne le recalcule plus depuis
  // l'e-mail (l'auto-détection ne s'applique que s'il n'a pas été touché).
  const [usernameTouched, setUsernameTouched] = useState(false)
  const [error, setError] = useState(null)

  // Appareils RingCentral (libellé = numéro) — select « Appareil / numéro
  // source ». La liste vient **du compte RingCentral de l'entreprise
  // choisie** (`?enterprise_id=` → `enterprises.ringcentral_*`, repli
  // `.env`) : sans entreprise, la requête n'est pas lancée. `retry: false`
  // : une panne de l'API ne doit pas réessayer en boucle.
  const { data: devicesData, isLoading: devicesLoading, isError: devicesUnavailable } = useQuery({
    queryKey: ['ringcentral-devices', form.enterprise_id || null],
    queryFn: () => listRingCentralDevicesApi(form.enterprise_id || null),
    enabled: open && Boolean(form.enterprise_id),
    staleTime: 1000 * 60 * 5,
    retry: false,
  })

  // Numéros uniques, postes sans ligne écartés (cf. `selectableDevices`).
  const devices = selectableDevices(devicesData?.data)

  // Contrôle d'unicité en temps réel (`users.username`, debounce).
  const usernameCheck = useUsernameAvailability(form.username)

  useEffect(() => {
    if (open) {
      setForm({
        email: '', username: '', first_name: '', last_name: '', phone: '',
        mot_de_passe: '', mot_de_passe_confirmation: '',
        enterprise_id: '',
        additional_info: '',
        ringcentral_extension_id: '',
        ringcentral_device_id: '', ringcentral_from_number: '',
        has_permission: false,
      })
      setUsernameTouched(false)
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

  /**
   * Saisie de l'e-mail → on (re)propose le nom d'utilisateur déduit de la
   * partie avant « @ ». Une saisie manuelle du nom reste prioritaire.
   */
  const onEmailChange = (value) => {
    setForm((previous) => ({
      ...previous,
      email: value,
      username: usernameTouched ? previous.username : usernameFromEmail(value),
    }))
  }

  /** Nom d'utilisateur éditable : caractères autorisés seuls, contrôle en direct. */
  const onUsernameChange = (value) => {
    setUsernameTouched(true)
    set('username', sanitizeUsername(value))
  }

  /**
   * Choix de l'entreprise → les appareils affichés viennent de **son**
   * compte RingCentral (`?enterprise_id=`) : la source déjà choisie vient
   * de l'ancien compte, on la remet à zéro.
   */
  const onEnterpriseChange = (enterpriseId) => {
    setForm((p) => ({
      ...p,
      enterprise_id: enterpriseId,
      ringcentral_device_id: '',
      ringcentral_from_number: '',
      ringcentral_extension_id: '',
    }))
  }

  /**
   * Choix d'un appareil → on enregistre aussi le numéro « from » affiché.
   * Le select « Utilisateur RingCentral » a disparu : le poste découle de
   * l'appareil choisi (`devices[*].extension.id`).
   */
  const onDeviceChange = (deviceId) => {
    const device = devices.find((d) => String(d.id) === String(deviceId))
    setForm((p) => ({
      ...p,
      ringcentral_device_id: deviceId,
      ringcentral_from_number: device ? deviceNumber(device) : '',
      ringcentral_extension_id: device?.extension?.id
        ? String(device.extension.id)
        : p.ringcentral_extension_id,
    }))
  }

  const handleSubmit = (e) => {
    e.preventDefault()
    setError(null)
    if (!form.email) {
      setError('L\'adresse e-mail est requise.')
      return
    }
    if (!usernameCheck.valid) {
      setError(`Login invalide — ${usernameCheck.message ?? 'vérifiez la saisie.'}`)
      return
    }
    if (usernameCheck.taken) {
      setError('Ce login est déjà pris.')
      return
    }
    if (!form.first_name || !form.last_name) {
      setError('Le prénom et le nom sont requis.')
      return
    }
    if (!form.enterprise_id) {
      setError('Veuillez sélectionner une entreprise.')
      return
    }
    if (!form.mot_de_passe || form.mot_de_passe.length < 8) {
      setError('Le mot de passe doit contenir au moins 8 caractères.')
      return
    }
    if (form.mot_de_passe !== form.mot_de_passe_confirmation) {
      setError('Les mots de passe ne correspondent pas.')
      return
    }
    // Numéro « from » : celui de l'appareil choisi (valeur fraîche de
    // l'API), en secours celui déjà sélectionné dans le formulaire.
    const selected = devices.find((d) => String(d.id) === String(form.ringcentral_device_id))
    const fromNumber = deviceNumber(selected) || form.ringcentral_from_number || undefined

    const payload = {
      role: 'COMERCIAL',
      email: form.email,
      // Nom d'utilisateur (unique) : absent du payload si laissé vide.
      username: form.username.trim() || undefined,
      first_name: form.first_name,
      last_name: form.last_name,
      mot_de_passe: form.mot_de_passe,
      mot_de_passe_confirmation: form.mot_de_passe_confirmation,
      // Téléphone / informations supplémentaires : plus saisis dans la
      // modale, la valeur reste transmise telle quelle (vide en création).
      phone: form.phone || undefined,
      enterprise_id: form.enterprise_id,
      additional_info: form.additional_info || undefined,
      ringcentral_extension_id: form.ringcentral_extension_id || undefined,
      ringcentral_device_id: form.ringcentral_device_id || undefined,
      ringcentral_from_number: form.ringcentral_device_id ? fromNumber : undefined,
      // Switch « Privilège commercial » (docs/RULES.md §7.1, colonne
      // `users.has_permission`).
      has_permission: form.has_permission,
    }
    mutation.mutate(payload)
  }

  return {
    form, error, isPending: mutation.isPending, enterprises, set, handleSubmit,
    // Champ « Nom d'utilisateur » : auto-détection depuis l'e-mail +
    // disponibilité vérifiée en temps réel.
    usernameCheck, onEmailChange, onUsernameChange,
    // Tous les appareils sont listés : le poste est déduit du choix.
    devices, devicesLoading, devicesUnavailable, onDeviceChange,
    onEnterpriseChange,
  }
}