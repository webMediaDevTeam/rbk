import Button from '@/components/ui/button.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'
import AvatarUpdateModal from '@/pages/shared/components/AvatarUpdateModal/index.jsx'
import { useAvatarSection } from './useAvatarSection.js'

export default function AvatarSection({ user, queryKey }) {
  const { open, openModal, closeModal } = useAvatarSection()

  return (
    <div className="space-y-6 max-w-2xl">
      <div>
        <h2 className="text-lg font-semibold text-foreground">
          Photo de profil
        </h2>
        <p className="text-sm text-muted-foreground">
          Téléversez une image qui vous représente sur la plateforme.
        </p>
      </div>

      <div className="border-b border-border" />

      <div className="flex flex-col sm:flex-row items-start gap-6">
        <UserAvatar user={user} size="lg" onEdit={openModal} />

        <div className="space-y-3">
          <Button type="button" variant="secondary" onClick={openModal}>
            Changer l’image
          </Button>
          <p className="text-xs text-muted-foreground max-w-xs">
            Formats acceptés : JPG, PNG, GIF, WEBP — 2 Mo maximum.
          </p>
        </div>
      </div>

      <AvatarUpdateModal
        open={open}
        user={user}
        queryKey={queryKey}
        onClose={closeModal}
      />
    </div>
  )
}