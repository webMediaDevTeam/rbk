import { useIsDesktop } from '@/hooks/use-mobile.js'

export function useHistoryList({ clients, pagination, currentPage, rowsPerPage, onPageChange, onRowsPerPageChange, onViewDetail }) {
  const isDesktop = useIsDesktop()
  const totalPages = Math.max(1, pagination?.last_page ?? 1)

  return {
    clients,
    isDesktop,
    totalPages,
    currentPage,
    rowsPerPage,
    onPageChange,
    onRowsPerPageChange,
    onViewDetail,
  }
}