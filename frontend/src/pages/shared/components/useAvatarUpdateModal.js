import { useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { uploadUserAvatarApi } from '@/api/shared.api.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'
import { buildAvatarUrl } from '@/lib/avatar.js'

const MAX_SIZE_MB = 2
const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']

export function useAvatarUpdateModal(props) {
  const { open, user, queryKey, onClose, uploadFn } = props
  const qc = useQueryClient()
  const [preview, setPreview] = useState(null)
  const [error, setError] = useState(null)
  const [dragOver, setDragOver] = useState(false)
  const inputRef = useRef(null)
  const fileRef = useRef(null)

  const mutation = useMutation({
    mutationFn: (formData) => {
      if (uploadFn) return uploadFn(formData)
      return uploadUserAvatarApi(user.id, formData)
    },
    onSuccess: () => {
      toast.success('Photo mise à jour.')
      if (queryKey) qc.invalidateQueries({ queryKey })
      onClose()
    },
    onError: (err) => toast.error(getApiErrorMessage(err)),
  })

  useEffect(() => {
    if (open) {
      setPreview(null)
      setError(null)
      fileRef.current = null
    }
  }, [open, user?.id])

  const validateFile = (file) => {
    if (!file) return 'Aucun fichier sélectionné.'
    if (!ALLOWED_TYPES.includes(file.type)) {
      return 'Format non supporté. Utilisez JPG, PNG, GIF ou WEBP.'
    }
    if (file.size > MAX_SIZE_MB * 1024 * 1024) {
      return `Fichier trop volumineux. Taille max : ${MAX_SIZE_MB} Mo.`
    }
    return null
  }

  const onFile = (file) => {
    const err = validateFile(file)
    setError(err)
    if (err) {
      setPreview(null)
      fileRef.current = null
      return
    }
    setPreview(URL.createObjectURL(file))
    fileRef.current = file
    if (inputRef.current) inputRef.current.value = ''
  }

  const handleInput = (e) => {
    const file = e.target.files?.[0]
    if (file) onFile(file)
  }

  const handleDrop = (e) => {
    e.preventDefault()
    setDragOver(false)
    const file = e.dataTransfer.files?.[0]
    if (file) onFile(file)
  }

  const handleSave = () => {
    if (!fileRef.current) {
      setError('Sélectionnez une image à téléverser.')
      return
    }
    const formData = new FormData()
    if (props.logoOnly) {
      formData.append('logo', fileRef.current)
    } else {
      formData.append('avatar', fileRef.current)
      formData.append('logo', fileRef.current)
    }
    mutation.mutate(formData)
  }

  const handleDragOver = (e) => {
    e.preventDefault()
    setDragOver(true)
  }

  const handleDragLeave = () => setDragOver(false)

  const handleImgError = (e) => {
    e.currentTarget.style.display = 'none'
  }

  const handleDropzoneClick = () => document.getElementById(props.logoOnly ? 'logo-input' : 'avatar-input')?.click()

  const currentUrl = buildAvatarUrl(user)

  return {
    preview,
    error,
    dragOver,
    isPending: mutation.isPending,
    inputRef,
    handleInput,
    handleDrop,
    handleSave,
    handleDragOver,
    handleDragLeave,
    handleImgError,
    handleDropzoneClick,
    currentUrl,
    maxSizeMb: MAX_SIZE_MB,
  }
}