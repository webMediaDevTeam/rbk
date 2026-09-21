import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { cn } from '@/lib/utils'
import { useUserAvatar } from './useUserAvatar.js'

export default function UserAvatar({ user, size = 'md', onEdit, className }) {
  const { src, sizeClass, showImage, initials, setImgFailed } = useUserAvatar({ user, size })

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
        {!showImage && <AvatarFallback>{initials}</AvatarFallback>}
      </Avatar>
    </button>
  )
}