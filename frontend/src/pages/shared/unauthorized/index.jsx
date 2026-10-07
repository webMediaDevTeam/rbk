import { useLocation, useNavigate } from 'react-router-dom'
import { ArrowLeft, ShieldAlert } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card.jsx'

const ROLE_LABELS = {
  SUPER_ADMIN: 'Super admin',
  ADMIN: 'Admin',
  COMERCIAL: 'Employé',
  GUEST: 'Invité',
}

/**
 * Page « Accès refusé » : affichée par `ProtectedRoute` quand l'utilisateur
 * est connecté mais n'a pas le rôle exigé par la route.
 */
export default function UnauthorizedPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const { role, allowedRoles, from } = location.state ?? {}

  const currentLabel = ROLE_LABELS[role] ?? role ?? '—'
  const allowedLabels = (allowedRoles ?? [])
    .map((r) => ROLE_LABELS[r] ?? r)
    .join(' · ')
  const fromPath = typeof from === 'object' && from?.pathname ? from.pathname : null

  const goBack = () => {
    if (fromPath && fromPath !== '/unauthorized') {
      navigate(fromPath, { replace: true })
      return
    }
    navigate('/dashboard', { replace: true })
  }

  return (
    <div className='flex min-h-[60vh] items-center justify-center px-4'>
      <Card className='max-w-md text-center'>
        <CardHeader>
          <ShieldAlert className='mx-auto mb-2 h-10 w-10 text-destructive' aria-hidden='true' />
          <CardTitle className='text-xl'>Accès refusé</CardTitle>
          <CardDescription>
            Votre rôle <strong>{currentLabel}</strong> n&apos;a pas accès à cette page.
            {allowedLabels && (
              <>
                {' '}
                Rôles autorisés : <strong>{allowedLabels}</strong>.
              </>
            )}
          </CardDescription>
          <CardAction />
        </CardHeader>
        <CardContent className='flex flex-col gap-2 sm:flex-row sm:justify-center'>
          <Button variant='secondary' onClick={goBack}>
            <ArrowLeft className='h-4 w-4' /> Retour
          </Button>
          <Button onClick={() => navigate('/dashboard', { replace: true })}>
            Aller au tableau de bord
          </Button>
        </CardContent>
      </Card>
    </div>
  )
}
