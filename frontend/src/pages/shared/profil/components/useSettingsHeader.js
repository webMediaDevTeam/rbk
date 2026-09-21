export function useSettingsHeader() {
  const handleHomeClick = (e) => {
    e.preventDefault()
  }

  return { handleHomeClick }
}