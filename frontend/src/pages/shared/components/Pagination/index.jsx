import { useState } from 'react'
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, ArrowRight } from 'lucide-react'
import Button from '@/components/ui/button'
import Select from '@/components/ui/select'

/**
 * Pages à afficher entre les flèches, sous forme d'une liste de numéros et de
 * sentinèles `"…"` (jeton d'ellipse non cliquable).
 *
 * Rendu stable et borné : on montre **toujours** la première et la dernière
 * page, plus une **fenêtre** autour de la page courante (`±radius`), et une
 * ellipse bouchonne chaque trou entre deux numéros consécutifs affichés.
 * Exemple (courante 8, total 22, radius 1) : `1 … 7 8 9 … 22`.
 *
 * @param {number} currentPage
 * @param {number} totalPages
 * @param {number} [radius=1] largeur de la fenêtre de chaque côté du courant
 * @returns {(number|'…')[]}
 */
function buildPageItems(currentPage, totalPages, radius = 1) {
  const wanted = new Set([1, totalPages])
  for (let p = currentPage - radius; p <= currentPage + radius; p += 1) {
    if (p >= 1 && p <= totalPages) wanted.add(p)
  }

  const pages = [...wanted].filter((p) => p >= 1 && p <= totalPages).sort((a, b) => a - b)

  const items = []
  let previous = null
  for (const page of pages) {
    if (previous !== null && page - previous > 1) items.push('…')
    items.push(page)
    previous = page
  }
  return items
}

export default function Pagination({
  currentPage,
  totalPages,
  rowsPerPage,
  onPageChange,
  onRowsPerPageChange,
}) {
  // Saut direct vers une page « personnalisée » : petit champ numérique posé à
  // cöté des flèches. La valeur saisie est bornée à `[1, totalPages]` ; la
  // page courante sert d'indice (`placeholder`) et le champ se vide après
  // chaque navigation pour rester un champ d'action, pas un affichage.
  // (Hook appelé **avant** la sortie anticipée pour respecter les règles des
  // hooks : le nombre de hooks doit rester identique à chaque rendu.)
  const [jump, setJump] = useState('')

  if (totalPages <= 1) return null

  const pageItems = buildPageItems(currentPage, totalPages)

  const handleJump = (event) => {
    event.preventDefault()
    const target = parseInt(jump, 10)
    if (Number.isNaN(target)) return
    onPageChange(Math.min(Math.max(target, 1), totalPages))
    setJump('')
  }

  return (
    <div className="flex flex-col sm:flex-row items-center justify-between gap-4 pt-2">
      <div className="flex items-center gap-3 text-sm text-foreground">
        <div className="relative">
          <Select
            value={rowsPerPage}
            onChange={(e) => onRowsPerPageChange(Number(e.target.value))}
            className="w-auto pr-8 appearance-none cursor-pointer"
          >
            <option value={50}>50</option>
            <option value={100}>100</option>
            <option value={200}>200</option>
            <option value={300}>300</option>
          </Select>
        </div>
        <span className="font-bold">Lignes par page</span>
      </div>
      <div className="flex items-center gap-6 text-sm text-foreground">
        <span className="font-medium">
          Page {currentPage} sur {totalPages}
        </span>
        <div className="flex items-center gap-1.5">
          <Button variant="secondary" size="icon-sm" disabled={currentPage === 1} onClick={() => onPageChange(1)}>
            <ChevronsLeft className="h-4 w-4" />
          </Button>
          <Button variant="secondary" size="icon-sm" disabled={currentPage === 1} onClick={() => onPageChange(currentPage - 1)}>
            <ChevronLeft className="h-4 w-4" />
          </Button>
          {pageItems.map((item, index) =>
            item === '…' ? (
              <span key={`gap-${index}`} className="px-1 text-muted-foreground select-none">…</span>
            ) : (
              <Button
                key={item}
                variant={currentPage === item ? 'default' : 'secondary'}
                size="icon-sm"
                onClick={() => onPageChange(item)}
              >
                {item}
              </Button>
            ),
          )}
          <Button variant="secondary" size="icon-sm" disabled={currentPage === totalPages} onClick={() => onPageChange(currentPage + 1)}>
            <ChevronRight className="h-4 w-4" />
          </Button>
          <Button variant="secondary" size="icon-sm" disabled={currentPage === totalPages} onClick={() => onPageChange(totalPages)}>
            <ChevronsRight className="h-4 w-4" />
          </Button>
        </div>
        {/* Champ « Aller à la page » — saisie libre bornée, validable à l'entrée
            clavier (Enter) ou via le bouton flèche. */}
        <form className="flex items-center gap-1.5" onSubmit={handleJump}>
          <label className="text-muted-foreground" htmlFor="pagination-jump">
            Aller à
          </label>
          <input
            id="pagination-jump"
            type="number"
            inputMode="numeric"
            min={1}
            max={totalPages}
            value={jump}
            placeholder={currentPage}
            onChange={(e) => setJump(e.target.value)}
            className="h-8 w-16 rounded-md border border-border bg-background px-2 text-sm text-foreground"
          />
          <Button type="submit" variant="secondary" size="icon-sm" aria-label="Aller à la page saisie">
            <ArrowRight className="h-4 w-4" />
          </Button>
        </form>
      </div>
    </div>
  )
}