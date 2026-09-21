import { Link } from 'react-router-dom'
import { ChevronRight, Home } from 'lucide-react'

export default function BreadcrumbNav({ name }) {
  return (
    <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
      <Link to="/commerciaux" className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
        <Home className="h-3.5 w-3.5" /> Accueil
      </Link>
      <ChevronRight className="h-3.5 w-3.5" />
      <Link to="/commerciaux" className="hover:text-foreground transition-colors">Commerciaux</Link>
      <ChevronRight className="h-3.5 w-3.5" />
      <span className="font-medium text-foreground">{name}</span>
    </nav>
  )
}