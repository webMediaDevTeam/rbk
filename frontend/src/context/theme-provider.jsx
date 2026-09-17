import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'

const STORAGE_KEY = 'theme'
const THEMES = ['light', 'dark', 'system']

const DARK_MQ = '(prefers-color-scheme: dark)'

const ThemeProviderContext = createContext({ theme: 'system', setTheme: () => {}, resolvedTheme: 'light' })

function getInitialTheme() {
  if (typeof window === 'undefined') return 'system'
  const stored = window.localStorage.getItem(STORAGE_KEY)
  return THEMES.includes(stored) ? stored : 'system'
}

function resolveTheme(theme) {
  if (typeof window === 'undefined') return 'light'
  return theme === 'dark' || (theme === 'system' && window.matchMedia(DARK_MQ).matches)
    ? 'dark'
    : 'light'
}

export function ThemeProvider({ children }) {
  const [theme, setThemeState] = useState(getInitialTheme)
  const [resolvedTheme, setResolvedTheme] = useState(() => resolveTheme(getInitialTheme()))

  useEffect(() => {
    const root = window.document.documentElement
    const mq = window.matchMedia(DARK_MQ)
    const apply = () => {
      const resolved = resolveTheme(theme)
      setResolvedTheme(resolved)
      root.classList.toggle('dark', resolved === 'dark')
      root.style.colorScheme = resolved
    }
    apply()
    mq.addEventListener('change', apply)
    return () => mq.removeEventListener('change', apply)
  }, [theme])

  const setTheme = useCallback((nextTheme) => {
    setThemeState(nextTheme)
    window.localStorage.setItem(STORAGE_KEY, nextTheme)
  }, [])

  const value = useMemo(() => ({ theme, setTheme, resolvedTheme }), [theme, setTheme, resolvedTheme])

  return (
    <ThemeProviderContext.Provider value={value}>
      {children}
    </ThemeProviderContext.Provider>
  )
}

export function useTheme() {
  return useContext(ThemeProviderContext)
}