import { useState, useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { updateUserApi, listRingCentralDevicesApi } from '@/api/shared.api.js'
import { listEntreprisesApi } from '@/api/admin.api.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'
import { ringcentralDeviceOptions, selectedRingcentralDeviceOption } from '@/utils/ringcentral.js'
import { useUsernameAvailability, sanitizeUsername, usernameFromEmail } from '@/hooks/use-username-availability.js'

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

  const profil = user?.profil
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

  // Appareils RingCentral (libellé = numéro) — select « Appareil / numéro
  // source », prérempli avec l'appareil déjà enregistré. La liste vient
  // **du compte RingCentral de l'entreprise** (`?enterprise_id=` →
  // `enterprises.ringcentral_*`, repli `.env`) : sans entreprise, aucune
  // requête. `retry: false` : pas de boucle en cas de panne.
  const { data: devicesData, isLoading: devicesLoading, isError: devicesUnavailable } = useQuery({
    queryKey: ['ringcentral-devices', form.enterprise_id || null],
    queryFn: () => listRingCentralDevicesApi(form.enterprise_id || null),
    enabled: open && Boolean(form.enterprise_id),
    staleTime: 1000 * 60 * 5,
    retry: false,
  })

  // Tous les appareils et chacun de leurs numéros sont proposés séparément.
  const devices = ringcentralDeviceOptions(devicesData?.data)
  const selectedDeviceValue = selectedRingcentralDeviceOption(
    devices,
    form.ringcentral_device_id,
    form.ringcentral_from_number,
  )

  // Contrôle d'unicité en temps réel (`users.username`), l'employé édité
  // étant exclu de la comparaison.
  const usernameCheck = useUsernameAvailability(form.username, { exceptId: user?.id ?? null })

  useEffect(() => {
    if (open && user) {
      setForm({
        email: user.email ?? '',
        // Comptes d'avant la colonne `users.username` : on propose la
        // partie avant « @ » de l'e-mail.
        username: user.username ?? usernameFromEmail(user.email),
        first_name: user.first_name ?? profil?.prenom ?? '',
        last_name: user.last_name ?? profil?.nom ?? '',
        phone: user.phone ?? profil?.telephone ?? '',
        mot_de_passe: '',
        mot_de_passe_confirmation: '',
        enterprise_id: profil?.entreprise_id ? String(profil.entreprise_id) : '',
        additional_info: profil?.info_supp ?? '',
        ringcentral_extension_id: profil?.ringcentral_extension_id ?? '',
        ringcentral_device_id: profil?.ringcentral_device_id ?? '',
        ringcentral_from_number: profil?.ringcentral_from_number ?? '',
        // Privilège courant (`formatUser` → `has_permission`).
        has_permission: Boolean(user.has_permission),
      })
      setUsernameTouched(false)
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
   * Choix d'un appareil → on met à jour le numéro « from » affiché.
   * Le select « Utilisateur RingCentral » a disparu : le poste découle de
   * l'appareil choisi (`devices[*].extension.id`).
   */
  const onDeviceChange = (deviceId) => {
    const option = devices.find((device) => device.value === deviceId)
    const device = option?.device
    setForm((p) => ({
      ...p,
      ringcentral_device_id: option?.deviceId ?? '',
      ringcentral_from_number: option?.phoneNumber ?? '',
      ringcentral_extension_id: device?.extension?.id
        ? String(device.extension.id)
        : '',
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
    if (form.mot_de_passe && form.mot_de_passe.length < 8) {
      setError('Le mot de passe doit contenir au moins 8 caractères.')
      return
    }
    if (form.mot_de_passe !== form.mot_de_passe_confirmation) {
      setError('Les mots de passe ne correspondent pas.')
      return
    }
    // Numéro « from » : celui de l'appareil choisi (valeur fraîche de
    // l'API), en secours celui déjà enregistré. `null` explicite pour
    // pouvoir **retirer** la source (`sometimes|nullable` côté API).
    const fromNumber = form.ringcentral_device_id
      ? (form.ringcentral_from_number || null)
      : null

    const payload = {
      email: form.email,
      // Nom d'utilisateur (unique) : `null` → effacement du champ.
      username: form.username.trim() || null,
      first_name: form.first_name,
      last_name: form.last_name,
      ...(form.mot_de_passe ? {
        mot_de_passe: form.mot_de_passe,
        mot_de_passe_confirmation: form.mot_de_passe_confirmation,
      } : {}),
      // Téléphone / informations supplémentaires : plus saisis dans la
      // modale, la valeur existante est renvoyée inchangée (aucune perte).
      phone: form.phone || null,
      enterprise_id: form.enterprise_id,
      additional_info: form.additional_info || null,
      ringcentral_extension_id: form.ringcentral_extension_id || null,
      ringcentral_device_id: form.ringcentral_device_id || null,
      ringcentral_from_number: fromNumber,
      // Switch « Privilège commercial » (docs/RULES.md §7.1, colonne
      // `users.has_permission`).
      has_permission: form.has_permission,
    }
    mutation.mutate(payload)
  }

  return {
    form, error, isPending: mutation.isPending, enterprises, set, handleSubmit,
    // Champ « Nom d'utilisateur » : auto-détection depuis l'e-mail +
    // disponibilité vérifiée en temps réel (hors employé édité).
    usernameCheck, onEmailChange, onUsernameChange,
    // Tous les appareils sont listés : le poste est déduit du choix.
    devices, selectedDeviceValue, devicesLoading, devicesUnavailable, onDeviceChange,
    onEnterpriseChange,
  }
}