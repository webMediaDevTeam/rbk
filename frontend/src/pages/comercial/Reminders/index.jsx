import { ChevronRight, Home, Phone, Clock } from 'lucide-react'
import { useRemindersPage } from './useRemindersPage.js'
import Button from '@/components/ui/button.jsx'

export default function RemindersPage() {
  const { isLoading, reminders, handleAccueilClick, formatRecallAt, UNIT_LABELS } = useRemindersPage()

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <a href="#" onClick={handleAccueilClick} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
          <Home className="h-3.5 w-3.5" /> Accueil
        </a>
        <ChevronRight className="h-3.5 w-3.5" />
        <span className="font-medium text-foreground">Rappels</span>
      </nav>

      <div>
        <h1 className="text-2xl font-bold tracking-tight text-foreground">Rappels</h1>
        <p className="text-sm text-muted-foreground mt-1">Rappels de boîte vocale en attente.</p>
      </div>

      {isLoading ? (
        <div className="h-48 flex items-center justify-center text-muted-foreground">Chargement...</div>
      ) : reminders.length === 0 ? (
        <div className="rounded-xl bg-card text-card-foreground shadow-sm h-48 flex items-center justify-center text-muted-foreground">
          Aucun rappel en attente.
        </div>
      ) : (
        <div className="space-y-3">
          {reminders.map((r) => (
            <div
              key={r.id}
              className="rounded-xl bg-card text-card-foreground shadow-sm p-4 flex items-center justify-between gap-4"
            >
              <div className="min-w-0 flex-1">
                <p className="font-medium text-foreground truncate">{r.client_name}</p>
                <div className="flex items-center gap-2 text-sm text-muted-foreground mt-1">
                  {r.client_phone && <span>{r.client_phone}</span>}
                  {r.client_municipality && (
                    <>
                      <span>·</span>
                      <span>{r.client_municipality}</span>
                    </>
                  )}
                </div>
              </div>

              <div className="flex items-center gap-3 shrink-0">
                <div className="text-right">
                  <div className="flex items-center gap-1 text-sm">
                    <Clock className="h-3.5 w-3.5 text-muted-foreground" />
                    <span className={r.is_due ? 'text-destructive font-medium' : 'text-muted-foreground'}>
                      {formatRecallAt(r.recall_at)}
                    </span>
                  </div>
                  <span className="text-xs text-muted-foreground">
                    Rappel dans {r.recall_after}{UNIT_LABELS[r.recall_unit] ?? ''}
                  </span>
                </div>

                <Button asChild size="sm" className="bg-teal-600 text-white hover:bg-teal-700">
                  <a href={`tel:${r.client_phone}`}>
                    <Phone className="h-4 w-4 mr-1" />
                    Appeler
                  </a>
                </Button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}