import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useCommercialProspect, useAdminClientDetail } from '@/pages/comercial/ProspectList/useCommercialProspectList.js'
import { blacklistClientApi, adminBlacklistClientApi, adminUnblockClientApi } from '@/api/commercial.api.js'
import { useClientNotes } from './useNotes.js'
import { useAuth } from '@/context/AuthContext.jsx'

export function useClientDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { role } = useAuth()
  const isAdmin = role === 'ADMIN' || role === 'SUPER_ADMIN'

  const commercialQuery = useCommercialProspect(isAdmin ? null : id)
  const adminQuery = useAdminClientDetail(isAdmin ? id : null)
  const { data, isLoading } = isAdmin ? adminQuery : commercialQuery
  const { data: notesData } = useClientNotes(isAdmin ? null : id)
  const qc = useQueryClient()

  const [actionOpen, setActionOpen] = useState(false)
  const [activeTab, setActiveTab] = useState('details')
  const [blacklistOpen, setBlacklistOpen] = useState(false)
  const [blacklistNote, setBlacklistNote] = useState('')
  const [blacklistError, setBlacklistError] = useState(null)

  const client = data?.data?.client
  const notes = isAdmin ? (client?.notes ?? []) : (notesData?.data ?? [])
  const outcomes = client?.call_outcomes ?? []
  const historyCount = notes.length + outcomes.length

  const hasReservation = !!client?.my_reservation
  const reservedByName = client?.assigned_commercial
    ? `${client.assigned_commercial.first_name ?? ''} ${client.assigned_commercial.last_name ?? ''}`.trim() || client.assigned_commercial.email
    : null

  const blacklistMutation = useMutation({
    mutationFn: () => (isAdmin ? adminBlacklistClientApi(id, blacklistNote) : blacklistClientApi(id, blacklistNote)),
    onSuccess: () => {
      toast.success('Client mis en liste noire.')
      setBlacklistOpen(false)
      setBlacklistNote('')
      qc.invalidateQueries({ queryKey: ['commercial-prospect', id] })
      qc.invalidateQueries({ queryKey: ['admin-client', id] })
      qc.invalidateQueries({ queryKey: ['commercial-prospects'] })
    },
    onError: (err) => {
      const msg = err?.response?.data?.message || 'Une erreur est survenue.'
      setBlacklistError(msg)
      toast.error(msg)
    },
  })

  const unblockMutation = useMutation({
    mutationFn: () => adminUnblockClientApi(id),
    onSuccess: () => {
      toast.success('Client débloqué.')
      qc.invalidateQueries({ queryKey: ['admin-client', id] })
    },
    onError: (err) => toast.error(err?.response?.data?.message || 'Une erreur est survenue.'),
  })

  const blacklistConfirmDisabled = blacklistMutation.isPending || !blacklistNote.trim()

  const handleHomeClick = (e) => {
    e.preventDefault()
    navigate('/prospects')
  }

  const handleProspectsClick = (e) => {
    e.preventDefault()
    navigate('/prospects')
  }

  const handleBack = () => navigate(-1)

  const openAction = () => setActionOpen(true)
  const closeAction = () => setActionOpen(false)

  const handleActionSuccess = () => setActiveTab('history')

  const openBlacklist = () => setBlacklistOpen(true)
  const closeBlacklist = () => setBlacklistOpen(false)

  const handleBlacklistNoteChange = (e) => {
    setBlacklistNote(e.target.value)
    setBlacklistError(null)
  }

  const handleCopyPhone = () => {
    navigator.clipboard.writeText(client.phone)
      .then(() => toast.success('Numéro copié.'))
      .catch(() => toast.error('Impossible de copier le numéro.'))
  }

  return {
    id,
    isAdmin,
    isLoading,
    client,
    notes,
    outcomes,
    historyCount,
    hasReservation,
    reservedByName,
    actionOpen,
    openAction,
    closeAction,
    handleActionSuccess,
    activeTab,
    setActiveTab,
    blacklistOpen,
    blacklistNote,
    blacklistError,
    handleBlacklistNoteChange,
    openBlacklist,
    closeBlacklist,
    blacklistMutation,
    blacklistConfirmDisabled,
    unblockMutation,
    handleHomeClick,
    handleProspectsClick,
    handleBack,
    handleCopyPhone,
  }
}