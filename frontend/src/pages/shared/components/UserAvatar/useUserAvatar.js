import { useState } from 'react'
import { buildAvatarUrl, getAvatarInitials } from '@/lib/avatar.js'

export function useUserAvatar(props) {
  const { user, size = 'md' } = props
  const [imgFailed, setImgFailed] = useState(false)
  const src = buildAvatarUrl(user)

  const sizeClass = {
    sm: 'h-8 w-8 text-xs',
    md: 'h-11 w-11 text-sm',
    lg: 'h-16 w-16 text-lg',
  }[size] ?? 'h-11 w-11 text-sm'

  const showImage = src && !imgFailed
  const initials = getAvatarInitials(user)

  return { src, sizeClass, showImage, initials, setImgFailed }
}