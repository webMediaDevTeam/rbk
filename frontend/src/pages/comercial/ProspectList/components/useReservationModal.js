import { useState, useEffect, useMemo } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  reserveCommercialProspectsApi,
  getPendingReservationsCountApi,
  releasePendingReservationsApi,
} from '@/api/commercial.api.js'
import { useAuth } from '@/context/AuthContext.jsx'

export const COUNT_OPTIONS = [10, 20, 50, 80, 100]

function generateGroupName(userName) {
  const now = new Date()
  const day = now.getDate()
  const months = ['janv', 'févr', 'mars', 'avr', 'mai', 'juin', 'juil', 'août', 'sept', 'oct', 'nov', 'déc']
  const month = months[now.getMonth()]
  const year = now.getFullYear()
  const hours = String(now.getHours()).padStart(2, '0')
  const minutes = String(now.getMinutes()).padStart(2, '0')
  return `${userName} - ${day} ${month} ${year} ${hours}:${minutes}`
}

export function useReservationModal({ open, onClose }) {
  const qc = useQueryClient()
  const { user } = useAuth()
  const [count, setCount] = useState(10)
  const [groupName, setGroupName] = useState('')
  const [result, setResult] = useState(null)

  const defaultName = useMemo(() => {
    if (!open) return ''
    const displayName = user?.first_name || user?.email?.split('@')[0] || 'Commercial'
    return generateGroupName(displayName)
  }, [open, user])

  useEffect(() => {
    if (open) {
      setCount(10)
      setGroupName(defaultName)
      setResult(null)
    }
  }, [open, defaultName])

  const { data: pendingData } = useQuery({
    queryKey: ['reservations-pending'],
    queryFn: getPendingReservationsCountApi,
    enabled: open,
  })
  const pendingCount = pendingData?.data?.count ?? 0

  const releaseMutation = useMutation({
    mutationFn: releasePendingReservationsApi,
    onSuccess: (res) => {
      const released = res?.data?.released ?? 0
      qc.invalidateQueries({ queryKey: ['reservations-pending'] })
      qc.invalidateQueries({ queryKey: ['commercial-prospects'] })
      qc.invalidateQueries({ queryKey: ['reservation-groups'] })
      toast.success(`${released} prospect(s) retourné(s) à disponible.`)
    },
    onError: (err) => {
      const msg = err?.response?.data?.message || 'Une erreur est survenue.'
      toast.error(msg)
    },
  })

  const mutation = useMutation({
    mutationFn: (payload) => reserveCommercialProspectsApi(payload),
    onSuccess: (res) => {
      const data = res.data
      setResult(data)
      qc.invalidateQueries({ queryKey: ['commercial-prospects'] })
      qc.invalidateQueries({ queryKey: ['reservation-groups'] })
      toast.success(`${data.reserved} prospect(s) réservé(s) avec succès.`)
    },
    onError: (err) => {
      const msg = err?.response?.data?.message || 'Une erreur est survenue.'
      setResult({ error: msg })
      toast.error(msg)
    },
  })

  const handleSubmit = (e) => {
    e.preventDefault()
    setResult(null)
    const payload = { group_name: groupName, count, amount: 1, unit: 'JOUR' }
    mutation.mutate(payload)
  }

  return {
    open,
    onClose,
    count,
    setCount,
    groupName,
    setGroupName,
    result,
    pendingCount,
    releaseMutation,
    mutation,
    handleSubmit,
  }
}