import { MoreHorizontal } from 'lucide-react'
import { useRowMenu } from './useRowMenu.js'

export default function RowMenu({ children }) {
  const { open, pos, ref, menuRef, setOpen, handleButtonClick } = useRowMenu()

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={handleButtonClick}
        className="inline-flex items-center justify-center h-8 w-8 rounded-md hover:bg-muted text-muted-foreground transition-colors"
      >
        <MoreHorizontal className="h-4 w-4" />
      </button>
      {open && (
        <>
          <button className="fixed inset-0 z-40 cursor-default" aria-hidden="true" tabIndex={-1} onClick={() => setOpen(false)} />
          <div ref={menuRef} style={{ position: 'fixed', top: pos.top, right: pos.right, zIndex: 50 }} className="w-48 rounded-xl border border-border/60 bg-card text-card-foreground shadow-xl shadow-black/5 p-1">
            {children(setOpen)}
          </div>
        </>
      )}
    </div>
  )
}