import { AlertCircle, ImagePlus, Loader2, X } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import { cn } from '@/lib/utils.js'
import { useAvatarUpdateModal } from './useAvatarUpdateModal.js'

export default function AvatarUpdateModal({ open, user, queryKey, onClose, uploadFn, title, logoOnly }) {
  const {
    preview,
    error,
    dragOver,
    isPending,
    inputRef,
    handleInput,
    handleDrop,
    handleSave,
    handleDragOver,
    handleDragLeave,
    handleImgError,
    handleDropzoneClick,
    currentUrl,
    maxSizeMb,
  } = useAvatarUpdateModal({ open, user, queryKey, onClose, uploadFn, logoOnly })

  if (!open || !user) return null

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="relative w-full max-w-md rounded-2xl bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-foreground">{title ?? 'Photo de profil'}</h2>
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
                onError={handleImgError}
              />
            )}
          </div>
        </div>

        <div
          onDragOver={handleDragOver}
          onDragLeave={handleDragLeave}
          onDrop={handleDrop}
          onClick={handleDropzoneClick}
          className={cn(
            'flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed p-6 text-center transition-colors',
            dragOver ? 'border-primary bg-primary/5' : 'border-border hover:border-ring'
          )}
        >
          <ImagePlus className="h-6 w-6 text-muted-foreground" />
          <p className="text-sm font-medium text-foreground">Glissez une image ici ou cliquez pour parcourir</p>
          <p className="text-xs text-muted-foreground">JPG, PNG, GIF, WEBP — max {maxSizeMb} Mo</p>
          <input
            id={logoOnly ? 'logo-input' : 'avatar-input'}
            name={logoOnly ? 'logo' : 'avatar'}
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

        {isPending && (
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
          <Button type="button" variant="secondary" onClick={onClose} disabled={isPending}>
            Annuler
          </Button>
          <Button type="button" onClick={handleSave} disabled={isPending}>
            {isPending && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
            Enregistrer
          </Button>
        </div>
      </div>
    </div>
  )
}