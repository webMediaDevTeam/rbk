import { Link } from 'react-router-dom'
import { Eye } from 'lucide-react'

export default function PageHeader({ name }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-foreground">{name}</h1>
        <p className="text-sm text-muted-foreground mt-1">Informations de l'employé, ses statistiques et son entreprise.</p>
      </div>
      <Link to="/commerciaux" className="inline-flex items-center gap-2 px-2.5 lg:px-4 h-9 rounded-lg text-sm font-medium bg-muted text-foreground hover:bg-muted/80 transition-colors">
        <Eye className="h-4 w-4" /> Retour
      </Link>
    </div>
  )
}