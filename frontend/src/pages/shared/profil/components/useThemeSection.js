import { useTheme } from '@/context/theme-provider.jsx'

export function useThemeSection(options = []) {
  const { theme, setTheme } = useTheme()

  const themes = options.map((option) => ({
    ...option,
    isActive: theme === option.value,
    className: `relative flex flex-col items-start gap-2 rounded-xl border p-4 text-left transition-colors ${
      theme === option.value
        ? 'border-primary bg-primary/5'
        : 'border-border hover:border-ring hover:bg-muted/50'
    }`,
    onClick: () => setTheme(option.value),
  }))

  return { themes }
}