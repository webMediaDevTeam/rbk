import { useState } from 'react'
import { Image } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar.jsx'
import AvatarUpdateModal from '@/pages/shared/components/AvatarUpdateModal.jsx'
import { buildAvatarUrl } from '@/lib/avatar.js'

export default function AvatarSection({ user, queryKey }) {
  const [open, setOpen] = useState(false)
  const isEnterprise = user?.role === 'ENTREPRISE'
  const logoUrl = buildAvatarUrl(user)

  return (
    <div className="space-y-6 max-w-2xl">
      <div>
        <h2 className="text-lg font-semibold text-foreground">
          {isEnterprise ? 'Logo de l’entreprise' : 'Photo de profil'}
        </h2>
        <p className="text-sm text-muted-foreground">
          {isEnterprise
            ? 'Le logo est affiché comme image de profil de votre entreprise sur toute la plateforme.'
            : 'Téléversez une image qui vous représente sur la plateforme.'}
        </p>
      </div>

      <div className="border-b border-border" />

      <div className="flex flex-col sm:flex-row items-start gap-6">
        {isEnterprise ? (
          <div className="flex h-24 w-24 items-center justify-center overflow-hidden rounded-2xl border border-border bg-muted">
            {logoUrl ? (
              <img
                src={logoUrl}
                alt="Logo de l’entreprise"
                className="h-full w-full object-contain"
              />
            ) : (
              <Image className="h-8 w-8 text-muted-foreground" />
            )}
          </div>
        ) : (
          <UserAvatar user={user} size="lg" onEdit={() => setOpen(true)} />
        )}

        <div className="space-y-3">
          <Button type="button" variant="secondary" onClick={() => setOpen(true)}>
            {isEnterprise ? 'Changer le logo' : 'Changer l’image'}
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
        onClose={() => setOpen(false)}
      />
    </div>
  )
}