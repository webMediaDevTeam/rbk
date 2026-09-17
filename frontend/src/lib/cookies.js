export function getCookie(name) {
  if (typeof document === 'undefined') return undefined
  return document.cookie
    .split('; ')
    .find((row) => row.startsWith(`${name}=`))
    ?.split('=')[1]
}

export function setCookie(name, value, options = {}) {
  if (typeof document === 'undefined') return
  const maxAge = options.maxAge ? `; max-age=${options.maxAge}` : ''
  document.cookie = `${name}=${encodeURIComponent(value)}; path=/; SameSite=Lax${maxAge}`
}

export function removeCookie(name) {
  if (typeof document === 'undefined') return
  document.cookie = `${name}=; path=/; max-age=0`
}
