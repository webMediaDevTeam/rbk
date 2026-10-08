import { Loader2 } from 'lucide-react'
import Input from '@/components/ui/input.jsx'

/**
 * Champ « Login » (nom d'utilisateur) des modales employé (créer / modifier).
 *
 * - saisi à la main (édition autorisée), caractères `A-Za-z0-9._-` seuls ;
 * - valeur pré-remplie par le hook (auto-détection depuis l'e-mail) ;
 * - `check` = `useUsernameAvailability()` : affiche en direct, **pendant la
 *   saisie**, le spinner « Vérification… », « déjà pris » (bloquant) ou
 *   « disponible ».
 *
 * @param {{ value: string, onChange: (value: string) => void,
 *           check: { tone: 'error'|'success'|'muted', message: string|null,
 *                    checking?: boolean } }} props
 */
export default function UsernameField({ value, onChange, check }) {
  const tone = check.tone === 'error'
    ? 'text-destructive'
    : check.tone === 'success'
      ? 'text-green-600'
      : 'text-muted-foreground'

  return (
    <div>
      <label htmlFor="username" className="block text-sm font-bold mb-1">Login</label>
      <Input
        id="username"
        type="text"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder="prenom.nom"
        autoComplete="off"
        spellCheck={false}
        maxLength={100}
        aria-invalid={check.tone === 'error' || undefined}
      />
      {check.message && (
        <p role={check.tone === 'error' ? 'alert' : 'status'} className={`mt-1 flex items-center gap-1 text-xs ${tone}`}>
          {check.checking && <Loader2 className="h-3 w-3 shrink-0 animate-spin" aria-hidden="true" />}
          <span>{check.message}</span>
        </p>
      )}
    </div>
  )
}
