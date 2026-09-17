import ReservationModal from './components/ReservationModal.jsx'

export default function ReserveClientsPage() {
  return (
    <div className="max-w-7xl mx-auto space-y-6 p-6">
      <h1 className="text-2xl font-bold">Réserver des clients</h1>
      <p className="text-sm text-muted-foreground">Utilisez ce formulaire pour réserver un lot de clients.</p>
      <div className="mt-4">
        <ReservationModal />
      </div>
    </div>
  )
}
