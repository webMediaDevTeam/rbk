export function useSortHeader(props) {
  const { currentSortBy, column } = props
  const isActive = currentSortBy === column
  return { isActive }
}