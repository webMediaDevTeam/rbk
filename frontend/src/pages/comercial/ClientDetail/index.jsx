import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { ChevronRight, Copy, Home, ArrowLeft, Phone, Plus } from 'lucide-react'
import { toast } from 'sonner'
import { useCommercialClient } from '@/pages/comercial/ClientList/useCommercialClientList.js'
import { useClientNotes } from './useNotes.js'
import Button from '@/components/ui/button.jsx'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs.jsx'
import ClientStatusBadge from '@/pages/comercial/ClientList/components/ClientStatusBadge.jsx'
import ClientDetailsTab from './components/ClientDetailsTab.jsx'
import NoteTimeline from './components/NoteTimeline.jsx'
import ActionModal from './components/ActionModal.jsx'

export default function ClientDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { data, isLoading } = useCommercialClient(id)
  const { data: notesData } = useClientNotes(id)
  const [actionOpen, setActionOpen] = useState(false)
  const [actionType, setActionType] = useState(null)

  const client = data?.data?.client
  const notes = notesData?.data ?? []

  const hasReservation = !!client?.my_reservation
  const hasCalled = (client?.call_outcomes?.length ?? 0) > 0
  const canBlacklist = hasReservation && hasCalled
  const reservedByName = client?.assigned_commercial
    ? `${client.assigned_commercial.first_name ?? ''} ${client.assigned_commercial.last_name ?? ''}`.trim() || client.assigned_commercial.email
    : null

  const openAction = (type = null) => {
    setActionType(type)
    setActionOpen(true)
  }

  const copyPhone = () => {
    if (!client?.phone) return
    navigator.clipboard.writeText(client.phone)
      .then(() => toast.success('Numéro copié.'))
      .catch(() => toast.error('Impossible de copier le numéro.'))
  }

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <a href="#" onClick={(e) => { e.preventDefault(); navigate('/clients') }} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
          <Home className="h-3.5 w-3.5" /> Accueil
        </a>
        <ChevronRight className="h-3.5 w-3.5" />
        <a href="#" onClick={(e) => { e.preventDefault(); navigate('/clients') }} className="hover:text-foreground transition-colors">
          Clients
        </a>
        <ChevronRight className="h-3.5 w-3.5" />
        <span className="font-medium text-foreground">Détail</span>
      </nav>

      {isLoading ? (
        <div className="h-48 flex items-center justify-center text-muted-foreground">Chargement...</div>
      ) : !client ? (
        <div className="h-48 flex items-center justify-center text-muted-foreground">Client introuvable.</div>
      ) : (
        <>
          <div className="flex items-start justify-between gap-3">
            <div className="flex items-start gap-3 min-w-0">
              <Button variant="ghost" size="icon-sm" onClick={() => navigate(-1)}>
                <ArrowLeft className="h-4 w-4" />
              </Button>
              <div className="min-w-0">
                <div className="flex items-center gap-2">
                  <h1 className="text-2xl font-bold tracking-tight text-foreground truncate">{client.name ?? '—'}</h1>
                  <ClientStatusBadge status={client.status} isBlacklisted={client.is_blacklisted} />
                </div>
                <p className="text-sm text-muted-foreground">{client.enterprise_name ?? '—'}</p>
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1 text-sm text-muted-foreground">
                  <span>{client.representative_name ?? '—'}</span>
                  {client.email && (
                    <a href={`mailto:${client.email}`} className="hover:text-foreground transition-colors">{client.email}</a>
                  )}
                  {client.phone && (
                    <span className="inline-flex items-center gap-1">
                      {client.phone}
                      <button
                        type="button"
                        onClick={copyPhone}
                        className="p-0.5 rounded hover:bg-muted hover:text-foreground transition-colors"
                        aria-label="Copier le numéro"
                      >
                        <Copy className="h-3.5 w-3.5" />
                      </button>
                    </span>
                  )}
                </div>
              </div>
            </div>
            <div className="flex items-center gap-2 shrink-0">
              {client.phone && (
                <Button asChild className="bg-teal-600 text-white hover:bg-teal-700">
                  <a href={`tel:${client.phone}`}>
                    <Phone className="h-4 w-4 mr-1" />
                    Appeler
                  </a>
                </Button>
              )}
              <Button onClick={() => openAction()}>
                <Plus className="h-4 w-4 mr-1" />
                Action
              </Button>
            </div>
          </div>

          <Tabs defaultValue="details">
            <TabsList>
              <TabsTrigger value="details">Détails</TabsTrigger>
              <TabsTrigger value="history">Historique ({notes.length + (client.call_outcomes?.length ?? 0)})</TabsTrigger>
            </TabsList>

            <TabsContent value="details">
              <div className="rounded-xl bg-card text-card-foreground shadow-sm p-6">
                <ClientDetailsTab client={client} />
              </div>
            </TabsContent>

            <TabsContent value="history">
              <div className="rounded-xl bg-card text-card-foreground shadow-sm p-6">
                <div className="flex items-center justify-between mb-4">
                  <h3 className="text-sm font-semibold text-foreground">Historique des interactions</h3>
                  <Button size="sm" onClick={() => openAction('GENERAL_NOTE')}>
                    <Plus className="h-4 w-4 mr-1" />
                    Action
                  </Button>
                </div>
                <NoteTimeline notes={notes} outcomes={client.call_outcomes ?? []} clientId={id} />
              </div>
            </TabsContent>
          </Tabs>
        </>
      )}

      <ActionModal
        open={actionOpen}
        onClose={() => { setActionOpen(false); setActionType(null) }}
        clientId={id}
        hasReservation={hasReservation}
        hasCalled={hasCalled}
        canBlacklist={canBlacklist}
        reservedByName={reservedByName}
        initialType={actionType}
      />
    </div>
  )
}
