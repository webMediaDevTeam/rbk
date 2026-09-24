import { useState, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { reserveCommercialProspectsApi } from '@/api/commercial.api.js'

// Nombre de prospects : 200 / 250 / 300 uniquement (pas de nombre libre).
export const COUNT_OPTIONS = [200, 250, 300]

export function useReservationModal({ open, onClose }) {
  const qc = useQueryClient()
  const [count, setCount] = useState(COUNT_OPTIONS[0])
  const [result, setResult] = useState(null)

  useEffect(() => {
    if (open) {
      setCount(COUNT_OPTIONS[0])
      setResult(null)
    }
  }, [open])

  const mutation = useMutation({
    mutationFn: (payload) => reserveCommercialProspectsApi(payload),
    onSuccess: (res) => {
      const data = res.data
      setResult(data)
      qc.invalidateQueries({ queryKey: ['commercial-prospects'] })
      qc.invalidateQueries({ queryKey: ['reservation-groups'] })
      qc.invalidateQueries({ queryKey: ['active-reservations-count'] })
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
    mutation.mutate({ count })
  }

  return {
    open,
    onClose,
    count,
    setCount,
    result,
    mutation,
    handleSubmit,
  }
}