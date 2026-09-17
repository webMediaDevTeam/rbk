import { useState } from 'react'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { buildAvatarUrl, getAvatarInitials } from '@/lib/avatar.js'
import { cn } from '@/lib/utils'

export default function UserAvatar({ user, size = 'md', onEdit, className }) {
  const [imgFailed, setImgFailed] = useState(false)
  const src = buildAvatarUrl(user)

  const sizeClass = {
    sm: 'h-8 w-8 text-xs',
    md: 'h-11 w-11 text-sm',
    lg: 'h-16 w-16 text-lg',
  }[size] ?? 'h-11 w-11 text-sm'

  const showImage = src && !imgFailed

  return (
    <button
      type="button"
      onClick={onEdit}
      aria-label="Mettre à jour la photo"
      className={cn('relative shrink-0 rounded-full transition-transform hover:scale-105 focus-visible:outline-none', className)}
    >
      <Avatar className={sizeClass}>
        {showImage && (
          <img
            src={src}
            alt="Avatar"
            onError={() => setImgFailed(true)}
            className="h-full w-full object-cover"
          />
        )}
        {!showImage && <AvatarFallback>{getAvatarInitials(user)}</AvatarFallback>}
      </Avatar>
    </button>
  )
}