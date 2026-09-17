import { useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertCircle, ImagePlus, Loader2, X } from 'lucide-react'
import { toast } from 'sonner'
import Button from '../../../components/ui/button.jsx'
import { cn } from '../../../lib/utils.js'
import { uploadUserAvatarApi } from '../../../api/shared.api.js'
import { getApiErrorMessage } from '../../../lib/api-errors.js'
import { buildAvatarUrl } from '../../../lib/avatar.js'

const MAX_SIZE_MB = 2
const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']

export default function AvatarUpdateModal({ open, user, queryKey, onClose }) {
  const qc = useQueryClient()
  const [preview, setPreview] = useState(null)
  const [error, setError] = useState(null)
  const [dragOver, setDragOver] = useState(false)
  const inputRef = useRef(null)
  const fileRef = useRef(null)

  const mutation = useMutation({
    mutationFn: (formData) => uploadUserAvatarApi(user.id, formData),
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

  if (!open || !user) return null

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
    formData.append('avatar', fileRef.current)
    mutation.mutate(formData)
  }

  const currentUrl = buildAvatarUrl(user)

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="relative w-full max-w-md rounded-2xl border bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-foreground">Photo de profil</h2>
          <button onClick={onClose} className="p-1 rounded-md hover:bg-muted" aria-label="Fermer">
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="mb-4 flex justify-center">
          <div className="relative h-24 w-24">
            {preview ? (
              <img src={preview} alt="Aperçu" className="h-24 w-24 rounded-full object-cover border-4 border-muted" />
            ) : (
              <img
                key={currentUrl ?? 'fallback'}
                src={currentUrl ?? 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96"><rect width="96" height="96" rx="48" fill="%23e2e8f0"/></svg>'}
                alt="Photo actuelle"
                className="h-24 w-24 rounded-full object-cover border-4 border-muted"
                onError={(e) => { e.currentTarget.style.display = 'none' }}
              />
            )}
          </div>
        </div>

        <div
          onDragOver={(e) => { e.preventDefault(); setDragOver(true) }}
          onDragLeave={() => setDragOver(false)}
          onDrop={handleDrop}
          onClick={() => document.getElementById('avatar-input')?.click()}
          className={cn(
            'flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed p-6 text-center transition-colors',
            dragOver ? 'border-primary bg-primary/5' : 'border-border hover:border-ring'
          )}
        >
          <ImagePlus className="h-6 w-6 text-muted-foreground" />
          <p className="text-sm font-medium text-foreground">Glissez une image ici ou cliquez pour parcourir</p>
          <p className="text-xs text-muted-foreground">JPG, PNG, GIF, WEBP — max {MAX_SIZE_MB} Mo</p>
          <input
            id="avatar-input"
            ref={inputRef}
            type="file"
            accept="image/*"
            className="hidden"
            onChange={handleInput}
          />
        </div>

        {error && (
          <div className="mt-3 flex items-start gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
            <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
            <span>{error}</span>
          </div>
        )}

        {mutation.isPending && (
          <div className="mt-3 space-y-2">
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" />
              Téléversement...
            </div>
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
              <div className="h-full w-1/2 animate-pulse rounded-full bg-primary" />
            </div>
          </div>
        )}

        <div className="mt-5 flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose} disabled={mutation.isPending}>
            Annuler
          </Button>
          <Button type="button" onClick={handleSave} disabled={mutation.isPending}>
            {mutation.isPending && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
            Enregistrer
          </Button>
        </div>
      </div>
    </div>
  )
}