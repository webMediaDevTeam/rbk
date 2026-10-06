import { useState } from 'react'
import { toast } from 'sonner'
import {
  AlertTriangle,
  Building2,
  History,
  Loader2,
  Mic,
  Phone,
  PhoneOff,
  RefreshCw,
  Users,
} from 'lucide-react'
import { api } from '@/api/client.js'
import { cn } from '@/lib/utils'
import Button from '@/components/ui/button.jsx'
import Badge from '@/components/ui/badge.jsx'
import Input from '@/components/ui/input.jsx'
import Select from '@/components/ui/select.jsx'
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card.jsx'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs.jsx'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table.jsx'

/**
 * Page de test Super Admin — API RingCentral.
 *
 * Partie « console » = **appel sortant + raccroché** (POST /call-logs/call,
 * statut, enregistrement, DELETE /call-logs/calls/{sessionId}) ; partie
 * « données » = extensions / historique d'appels / compte.
 *
 * Tout est **pass-through** vers RingCentral : aucune écriture en base
 * (docs/TODOS.md « Phase 6bis »).
 */

/** `sessionId` terminé (statut brut RingCentral) ? */
const isEnded = (status) =>
  /end|disconnect|reject|fail|complete/i.test(String(status ?? ''))

export default function CallLogTestPage() {
  // ── Données (extensions / historique / compte) ─────────────────────────
  const [users, setUsers] = useState([])
  const [userCalls, setUserCalls] = useState([])
  const [phoneCalls, setPhoneCalls] = useState([])
  const [extensionId, setExtensionId] = useState('~')
  const [phone, setPhone] = useState('')
  const [loadingUsers, setLoadingUsers] = useState(false)
  const [loadingUserCalls, setLoadingUserCalls] = useState(false)
  const [loadingPhoneCalls, setLoadingPhoneCalls] = useState(false)
  const [dataTab, setDataTab] = useState('extensions')

  // ── Contrôle d'appel (pass-through, sans écriture en base) ────────────
  const [account, setAccount] = useState(null)
  const [loadingAccount, setLoadingAccount] = useState(false)

  const [devices, setDevices] = useState([])
  const [loadingDevices, setLoadingDevices] = useState(false)
  const [deviceId, setDeviceId] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [userId, setUserId] = useState('')

  const [call, setCall] = useState(null)
  const [loadingCall, setLoadingCall] = useState(false)

  const [sessionId, setSessionId] = useState('')
  const [partyId, setPartyId] = useState('')
  const [sessionState, setSessionState] = useState('idle') // idle | active | ended
  const [status, setStatus] = useState(null)
  const [loadingStatus, setLoadingStatus] = useState(false)
  const [recording, setRecording] = useState(null)
  const [loadingRecord, setLoadingRecord] = useState(false)
  const [recordings, setRecordings] = useState(null)
  const [loadingRecordings, setLoadingRecordings] = useState(false)
  const [loadingHangup, setLoadingHangup] = useState(false)

  const [error, setError] = useState(null)
  /** Réponse brute de RingCentral (`upstream`) en cas de 502. */
  const [errorUpstream, setErrorUpstream] = useState(null)

  /** Message d'erreur API : 502 (`error`), 422 (`message` + `errors`). */
  const apiError = (err) => {
    const data = err.response?.data
    if (data?.error) return data.error
    if (data?.errors) return `${data.message} (${Object.values(data.errors).flat().join(' · ')})`
    return data?.message || err.message
  }

  /** Erreur API : message + réponse brute RingCentral (statut/corps). */
  const fail = (err) => {
    setError(apiError(err))
    setErrorUpstream(err.response?.data?.upstream ?? null)
  }

  /** Erreur de saisie (aucune réponse upstream à afficher). */
  const failOn = (message) => {
    setError(message)
    setErrorUpstream(null)
  }

  const clearError = () => {
    setError(null)
    setErrorUpstream(null)
  }

  // ── 1. Extensions ──────────────────────────────────────────────────────
  const fetchUsers = async () => {
    clearError()
    setLoadingUsers(true)
    try {
      const res = await api.get('/call-logs/users')
      setUsers(res.data || [])
    } catch (err) {
      fail(err)
    } finally {
      setLoadingUsers(false)
    }
  }

  // ── 2. Historique par extension ────────────────────────────────────────
  const fetchUserCalls = async (id = extensionId) => {
    clearError()
    setLoadingUserCalls(true)
    try {
      const res = await api.get(`/call-logs/users/${encodeURIComponent(id)}`)
      setUserCalls(res.data || [])
    } catch (err) {
      fail(err)
    } finally {
      setLoadingUserCalls(false)
    }
  }

  /** Depuis le tableau des extensions : préremplit + ouvre l'onglet. */
  const testExtension = (id) => {
    setExtensionId(id)
    setDataTab('byExtension')
    fetchUserCalls(id)
  }

  // ── 3. Historique vers un numéro ───────────────────────────────────────
  const fetchPhoneCalls = async (number = phone) => {
    if (!number) {
      failOn('Veuillez renseigner un numéro de téléphone.')
      return
    }
    clearError()
    setLoadingPhoneCalls(true)
    try {
      const res = await api.get(`/call-logs/by-phone/${encodeURIComponent(number)}`)
      setPhoneCalls(res.data || [])
      setDataTab('byPhone')
    } catch (err) {
      fail(err)
    } finally {
      setLoadingPhoneCalls(false)
    }
  }

  // ── 4. Compte / entreprise (account_id) ────────────────────────────────
  const fetchAccount = async () => {
    clearError()
    setLoadingAccount(true)
    try {
      const res = await api.get('/call-logs/account')
      setAccount(res.data || null)
    } catch (err) {
      fail(err)
    } finally {
      setLoadingAccount(false)
    }
  }

  // ── 5. Appareils + appel sortant ───────────────────────────────────────
  /**
   * Numéros d'un appareil : ses propres `phoneLines` (deskphone) puis ceux
   * de son extension (`phoneNumbers`, renvoyés par `/call-logs/devices`) —
   * les softphones n'ont aucune ligne.
   */
  const devicePhones = (d) =>
    [...new Set([
      ...(d?.phoneLines ?? []).map((l) => l?.phoneNumber).filter(Boolean),
      ...(d?.phoneNumbers ?? []).filter(Boolean),
    ])]

  /** Libellé de l'option : **le numéro**, le nom de l'appareil en secours. */
  const deviceLabel = (d) => devicePhones(d).join(' · ') || d?.name || d?.id

  const fetchDevices = async () => {
    clearError()
    setLoadingDevices(true)
    try {
      const res = await api.get('/call-logs/devices')
      setDevices(res.data || [])
      console.log('Appareils RingCentral :', res.data)
    } catch (err) {
      fail(err)
    } finally {
      setLoadingDevices(false)
    }
  }

  const makeCall = async () => {
    if (!to.trim()) {
      failOn('Veuillez renseigner le numéro à appeler.')
      return
    }
    clearError()
    setLoadingCall(true)
    setCall(null)
    setStatus(null)
    try {
      const payload = { to: to.trim() }
      if (deviceId) payload.device_id = deviceId
      if (from.trim()) payload.from = from.trim()
      if (userId.trim()) payload.user_id = userId.trim()

      const res = await api.post('/call-logs/call', payload)
      setCall(res.data)
      if (res.data?.session_id) setSessionId(res.data.session_id)
      if (res.data?.party_id) setPartyId(res.data.party_id)
      setSessionState('active')
      toast.success(`Appel lancé vers ${to.trim()}`)
    } catch (err) {
      fail(err)
    } finally {
      setLoadingCall(false)
    }
  }

  // ── 6. Suivi / enregistrement / raccroché ──────────────────────────────
  const fetchStatus = async () => {
    if (!sessionId.trim()) {
      failOn('Renseignez un sessionId (retourné automatiquement par l’appel).')
      return
    }
    clearError()
    setLoadingStatus(true)
    try {
      const res = await api.get(`/call-logs/calls/${encodeURIComponent(sessionId.trim())}`)
      setStatus(res.data)
      // Raccourci : la première partie devient la cible d'enregistrement.
      if (!partyId.trim() && res.data?.parties?.length) setPartyId(res.data.parties[0].id)
      if (isEnded(res.data?.status)) setSessionState('ended')
    } catch (err) {
      fail(err)
    } finally {
      setLoadingStatus(false)
    }
  }

  const startRecording = async () => {
    if (!sessionId.trim() || !partyId.trim()) {
      failOn('sessionId et partyId sont requis pour démarrer un enregistrement.')
      return
    }
    clearError()
    setLoadingRecord(true)
    try {
      const res = await api.post(
        `/call-logs/calls/${encodeURIComponent(sessionId.trim())}/parties/${encodeURIComponent(partyId.trim())}/record`
      )
      setRecording(res.data)
      toast.success('Enregistrement démarré.')
    } catch (err) {
      fail(err)
    } finally {
      setLoadingRecord(false)
    }
  }

  const fetchRecordings = async () => {
    if (!sessionId.trim() || !partyId.trim()) {
      failOn('sessionId et partyId sont requis pour lister les enregistrements.')
      return
    }
    clearError()
    setLoadingRecordings(true)
    try {
      const res = await api.get(
        `/call-logs/calls/${encodeURIComponent(sessionId.trim())}/parties/${encodeURIComponent(partyId.trim())}/recordings`
      )
      setRecordings(res.data)
    } catch (err) {
      fail(err)
    } finally {
      setLoadingRecordings(false)
    }
  }

  const hangUp = async () => {
    if (!sessionId.trim()) {
      failOn('Renseignez un sessionId pour raccrocher.')
      return
    }
    clearError()
    setLoadingHangup(true)
    try {
      await api.delete(`/call-logs/calls/${encodeURIComponent(sessionId.trim())}`)
      setStatus(null)
      setSessionState('ended')
      toast.success('Session terminée (raccroché).')
    } catch (err) {
      fail(err)
    } finally {
      setLoadingHangup(false)
    }
  }

  // ── Rendu ──────────────────────────────────────────────────────────────
  const sessionBadge =
    sessionState === 'active'
      ? { variant: 'success', label: 'Appel en cours' }
      : sessionState === 'ended'
        ? { variant: 'secondary', label: 'Session terminée' }
        : { variant: 'outline', label: 'Aucun appel' }

  const jsonBlock = (data, empty) =>
    data ? (
      <pre className="mt-3 max-h-72 overflow-auto rounded-lg bg-muted p-3 text-xs leading-relaxed">
        {JSON.stringify(data, null, 2)}
      </pre>
    ) : (
      <p className="mt-3 text-sm text-muted-foreground">{empty}</p>
    )

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Console d'appel RingCentral</h1>
          <p className="text-sm text-muted-foreground mt-1">
            Page de test Super Admin — appel sortant, statut, enregistrement et raccroché (aucune écriture en base).
          </p>
        </div>
        <Badge variant={sessionBadge.variant}>{sessionBadge.label}</Badge>
      </div>

      {error && (
        <div className="flex items-start gap-2 rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
          <div className="min-w-0 flex-1">
            <p className="font-semibold">Erreur</p>
            <p className="break-words">{error}</p>

            {/* Réponse brute renvoyée par le backend (502 `upstream`) */}
            {errorUpstream && (
              <div className="mt-2">
                <p className="text-xs font-semibold uppercase tracking-wide opacity-80">
                  Réponse RingCentral — {errorUpstream.status} {errorUpstream.reason}
                  {errorUpstream.request_id ? ` · RCRequestId ${errorUpstream.request_id}` : ''}
                </p>
                <pre className="mt-1 max-h-56 overflow-auto rounded-lg bg-background/70 p-2 text-left text-xs leading-relaxed text-foreground">
                  {JSON.stringify(errorUpstream.body ?? errorUpstream, null, 2)}
                </pre>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── Console : composer un appel │ session en cours ─────────────── */}
      <div className="grid gap-6 lg:grid-cols-2">
        <Card className="gap-4 py-5">
          <CardHeader className="px-5 pb-0">
            <CardTitle className="text-base flex items-center gap-2">
              <Phone className="h-4 w-4" /> Composer un appel
            </CardTitle>
            <CardDescription>POST /api/v1/call-logs/call — call-out RingCentral.</CardDescription>
          </CardHeader>

          <CardContent className="px-5 space-y-4">
            <div className="space-y-1.5">
              <label htmlFor="rc-to" className="text-sm font-medium">
                Numéro à appeler *
              </label>
              <Input
                id="rc-to"
                type="tel"
                inputMode="tel"
                value={to}
                onChange={(e) => setTo(e.target.value)}
                placeholder="5145594545 ou +15145594545 (E.164)"
                className="h-11 text-base font-semibold tracking-wide"
              />
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
              <div className="space-y-1.5">
                <div className="flex items-center justify-between gap-2">
                  <label htmlFor="rc-device" className="text-sm font-medium">
                    Appareil source
                  </label>
                  <button
                    type="button"
                    onClick={fetchDevices}
                    disabled={loadingDevices}
                    className="inline-flex items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground disabled:opacity-60"
                  >
                    <RefreshCw className={cn('h-3 w-3', loadingDevices && 'animate-spin')} />
                    {loadingDevices ? 'Chargement…' : 'Charger'}
                  </button>
                </div>
                <Select id="rc-device" value={deviceId} onChange={(e) => setDeviceId(e.target.value)} disabled={loadingDevices}>
                  <option value="">{devices.length ? '— Choisir un numéro —' : '— appareils non chargés —'}</option>
                  {devices.map((d) => (
                    <option key={d.id} value={d.id}>
                      {deviceLabel(d)}
                    </option>
                  ))}
                </Select>
              </div>

              <div className="space-y-1.5">
                <label htmlFor="rc-from" className="text-sm font-medium">
                  Numéro source (from)
                </label>
                <Input
                  id="rc-from"
                  type="tel"
                  inputMode="tel"
                  value={from}
                  onChange={(e) => setFrom(e.target.value)}
                  placeholder="facultatif si appareil choisi"
                />
              </div>
            </div>

            <details className="rounded-lg border border-border/60 bg-muted/30 px-3 py-2">
              <summary className="cursor-pointer text-xs font-medium text-muted-foreground">
                Options avancées — <span className="font-mono">user_id</span> (résout l'extension puis son appareil)
              </summary>
              <Input
                className="mt-2"
                value={userId}
                onChange={(e) => setUserId(e.target.value)}
                placeholder="UUID de l'employé"
              />
            </details>

            <div className="flex flex-wrap items-center gap-3 pt-1">
              <Button
                onClick={makeCall}
                disabled={loadingCall || sessionState === 'active'}
                className="h-11 bg-emerald-600 text-white hover:bg-emerald-700"
                title={sessionState === 'active' ? 'Raccrochez la session en cours avant de rappeler.' : undefined}
              >
                {loadingCall ? (
                  <>
                    <Loader2 className="h-4 w-4 animate-spin" /> Appel en cours…
                  </>
                ) : (
                  <>
                    <Phone className="h-4 w-4" /> Appeler
                  </>
                )}
              </Button>
              <p className="text-xs text-muted-foreground">
                Source requise : un appareil ou un numéro « from ».
              </p>
            </div>
          </CardContent>
        </Card>

        <Card className="gap-4 py-5">
          <CardHeader className="px-5 pb-0">
            <CardTitle className="text-base">Session en cours</CardTitle>
            <CardDescription>
              {sessionId ? `GET /call-logs/calls/${sessionId}` : 'Statut · enregistrement · raccroché'}
            </CardDescription>
            <CardAction>
              <Badge variant={sessionBadge.variant}>{sessionBadge.label}</Badge>
            </CardAction>
          </CardHeader>

          <CardContent className="px-5 space-y-4">
            {!sessionId ? (
              <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-border py-10 text-center">
                <PhoneOff className="h-6 w-6 text-muted-foreground" />
                <p className="text-sm font-medium">Aucun appel en cours</p>
                <p className="max-w-[22rem] text-xs text-muted-foreground">
                  Composez un numéro à gauche puis cliquez sur « Appeler » : le <span className="font-mono">sessionId</span>{' '}
                  s'affichera ici.
                </p>
              </div>
            ) : (
              <>
                <div className="space-y-2 rounded-lg border border-border/60 bg-muted/30 p-3">
                  <InfoRow label="sessionId" value={sessionId} mono />
                  <InfoRow label="statut" value={status?.status ?? (sessionState === 'active' ? '—' : 'terminé')} />
                  <InfoRow label="partyId" value={partyId || '—'} mono />
                  <InfoRow label="destination" value={call?.to ?? (to || '—')} />
                </div>

                {status?.parties?.length > 0 ? (
                  <div className="space-y-1.5">
                    <label htmlFor="rc-party" className="text-sm font-medium">
                      Partie à contrôler
                    </label>
                    <Select id="rc-party" value={partyId} onChange={(e) => setPartyId(e.target.value)}>
                      <option value="">— Choisir une partie —</option>
                      {status.parties.map((p) => (
                        <option key={p.id} value={p.id}>
                          {p.from || p.to || p.id} · {p.status || 'sans statut'}
                        </option>
                      ))}
                    </Select>
                  </div>
                ) : (
                  <div className="space-y-1.5">
                    <label htmlFor="rc-party" className="text-sm font-medium">
                      partyId
                    </label>
                    <Input
                      id="rc-party"
                      value={partyId}
                      onChange={(e) => setPartyId(e.target.value)}
                      placeholder="rempli par « Statut »"
                      className="font-mono text-xs"
                    />
                  </div>
                )}

                <div className="grid gap-2 sm:grid-cols-3">
                  <Button variant="outline" onClick={fetchStatus} disabled={loadingStatus}>
                    {loadingStatus ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
                    Statut
                  </Button>
                  <Button variant="secondary" onClick={startRecording} disabled={loadingRecord || !partyId}>
                    {loadingRecord ? <Loader2 className="h-4 w-4 animate-spin" /> : <Mic className="h-4 w-4" />}
                    Enregistrer
                  </Button>
                  <Button variant="outline" onClick={fetchRecordings} disabled={loadingRecordings || !partyId}>
                    {loadingRecordings ? <Loader2 className="h-4 w-4 animate-spin" /> : <History className="h-4 w-4" />}
                    Fichiers
                  </Button>
                </div>

                <Button
                  variant="destructive"
                  className="h-11 w-full"
                  onClick={hangUp}
                  disabled={loadingHangup || sessionState === 'ended'}
                >
                  {loadingHangup ? (
                    <>
                      <Loader2 className="h-4 w-4 animate-spin" /> Raccrochement…
                    </>
                  ) : (
                    <>
                      <PhoneOff className="h-4 w-4" />
                      {sessionState === 'ended' ? 'Session terminée' : 'Raccrocher'}
                    </>
                  )}
                </Button>
              </>
            )}
          </CardContent>
        </Card>
      </div>

      {/* ── Réponses brutes ─────────────────────────────────────────────── */}
      <Card className="gap-4 py-5">
        <CardHeader className="px-5 pb-0">
          <CardTitle className="text-base">Réponses brutes</CardTitle>
          <CardDescription>JSON retourné par RingCentral (pass-through, non stocké).</CardDescription>
        </CardHeader>
        <CardContent className="px-5">
          <Tabs defaultValue="call">
            <TabsList>
              <TabsTrigger value="call">Appel</TabsTrigger>
              <TabsTrigger value="status">Statut</TabsTrigger>
              <TabsTrigger value="recording">Enregistrement</TabsTrigger>
              <TabsTrigger value="recordings">Fichiers</TabsTrigger>
            </TabsList>
            <TabsContent value="call">{jsonBlock(call, 'Aucun appel effectué pour le moment.')}</TabsContent>
            <TabsContent value="status">{jsonBlock(status, 'Cliquez sur « Statut » dans la session.')}</TabsContent>
            <TabsContent value="recording">{jsonBlock(recording, 'Aucun enregistrement démarré.')}</TabsContent>
            <TabsContent value="recordings">
              {jsonBlock(recordings, 'Aucune liste de fichiers récupérée.')}
            </TabsContent>
          </Tabs>
        </CardContent>
      </Card>

      {/* ── Données RingCentral ──────────────────────────────────────────── */}
      <Card className="gap-4 py-5">
        <CardHeader className="px-5 pb-0">
          <CardTitle className="text-base">Données RingCentral</CardTitle>
          <CardDescription>Extensions, historique d'appels et compte / entreprise.</CardDescription>
        </CardHeader>
        <CardContent className="px-5">
          <Tabs value={dataTab} onValueChange={setDataTab}>
            <TabsList>
              <TabsTrigger value="extensions" className="gap-1.5">
                <Users className="h-3.5 w-3.5" /> Extensions
              </TabsTrigger>
              <TabsTrigger value="byExtension" className="gap-1.5">
                <History className="h-3.5 w-3.5" /> Par extension
              </TabsTrigger>
              <TabsTrigger value="byPhone" className="gap-1.5">
                <Phone className="h-3.5 w-3.5" /> Par numéro
              </TabsTrigger>
              <TabsTrigger value="account" className="gap-1.5">
                <Building2 className="h-3.5 w-3.5" /> Compte
              </TabsTrigger>
            </TabsList>

            {/* 1. Extensions */}
            <TabsContent value="extensions" className="space-y-3">
              <div className="flex items-center gap-3">
                <Button variant="outline" size="sm" onClick={fetchUsers} disabled={loadingUsers}>
                  {loadingUsers ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
                  Charger les extensions
                </Button>
                <span className="text-xs text-muted-foreground">{users.length} extension(s)</span>
              </div>
              {users.length > 0 && (
                <div className="max-h-72 overflow-auto rounded-lg border border-border">
                  <Table>
                    <TableHeader>
                      <TableRow className="bg-background hover:bg-background">
                        <TableHead className="w-[26%]">Nom</TableHead>
                        <TableHead className="w-[16%]">Extension</TableHead>
                        <TableHead className="w-[16%]">Statut</TableHead>
                        <TableHead>ID</TableHead>
                        <TableHead className="w-[16%]" />
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {users.map((u) => (
                        <TableRow key={u.id}>
                          <TableCell className="truncate font-medium">
                            {u.name || `${u.contact?.firstName || ''} ${u.contact?.lastName || ''}`.trim() || '—'}
                          </TableCell>
                          <TableCell className="tabular-nums">{u.extensionNumber || '—'}</TableCell>
                          <TableCell>
                            <Badge variant={u.status === 'Enabled' ? 'success' : 'secondary'}>{u.status || '—'}</Badge>
                          </TableCell>
                          <TableCell className="truncate font-mono text-xs text-muted-foreground">{u.id}</TableCell>
                          <TableCell className="text-right">
                            <Button variant="ghost" size="sm" onClick={() => testExtension(u.id)}>
                              Tester
                            </Button>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              )}
            </TabsContent>

            {/* 2. Historique par extension */}
            <TabsContent value="byExtension" className="space-y-3">
              <div className="flex flex-wrap items-center gap-3">
                <Input
                  value={extensionId}
                  onChange={(e) => setExtensionId(e.target.value)}
                  placeholder="~ ou ID de l'extension"
                  className="max-w-xs font-mono text-xs"
                />
                <Button variant="outline" size="sm" onClick={() => fetchUserCalls()} disabled={loadingUserCalls}>
                  {loadingUserCalls ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
                  Charger l'historique
                </Button>
              </div>
              {jsonBlock(userCalls, 'Aucun appel chargé pour cette extension.')}
            </TabsContent>

            {/* 3. Historique vers un numéro */}
            <TabsContent value="byPhone" className="space-y-3">
              <div className="flex flex-wrap items-center gap-3">
                <Input
                  type="tel"
                  inputMode="tel"
                  value={phone}
                  onChange={(e) => setPhone(e.target.value)}
                  placeholder="E.164 sans + (ex : 15145550123)"
                  className="max-w-xs"
                />
                <Button variant="outline" size="sm" onClick={() => fetchPhoneCalls()} disabled={loadingPhoneCalls}>
                  {loadingPhoneCalls ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
                  Charger l'historique
                </Button>
              </div>
              {jsonBlock(phoneCalls, 'Aucun appel chargé pour ce numéro.')}
            </TabsContent>

            {/* 4. Compte */}
            <TabsContent value="account" className="space-y-3">
              <Button variant="outline" size="sm" onClick={fetchAccount} disabled={loadingAccount}>
                {loadingAccount ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
                Charger le compte
              </Button>
              {account && (
                <div className="grid gap-2 rounded-lg border border-border/60 bg-muted/30 p-3 sm:grid-cols-3">
                  <InfoRow label="account_id" value={account.id || '—'} mono />
                  <InfoRow label="Société" value={account.company?.name || '—'} />
                  <InfoRow label="Statut" value={account.status || '—'} />
                </div>
              )}
              {jsonBlock(account, 'Compte non chargé.')}
            </TabsContent>
          </Tabs>
        </CardContent>
      </Card>
    </div>
  )
}

/** Ligne « libellé … valeur » des panneaux de session / compte. */
function InfoRow({ label, value, mono = false }) {
  return (
    <div className="flex items-center justify-between gap-3 text-sm">
      <span className="shrink-0 text-muted-foreground">{label}</span>
      <span className={cn('truncate', mono ? 'font-mono text-xs' : 'font-medium')}>{value}</span>
    </div>
  )
}
