import { useEffect, useState } from 'react'
import { Loader2, Play } from 'lucide-react'
import { toast } from 'sonner'
import { api } from '@/api/client.js'

/**
 * Lecteur d'un enregistrement RingCentral : le `contentUri` exige
 * l'en-tête `Authorization`, qu'un `<audio>` ne sait pas envoyer → on
 * récupère le flux **avec** le token (`GET …/recordings/{id}/content`,
 * proxy backend) puis on le donne au lecteur via un `blob:` URL.
 *
 * @param {{ recordingId: string }} props
 */
export default function RecordingPlayer({ recordingId }) {
  const [url, setUrl] = useState(null)
  const [loading, setLoading] = useState(false)

  // Le blob reste en mémoire le temps de la lecture : libéré au démontage.
  useEffect(() => () => {
    if (url) URL.revokeObjectURL(url)
  }, [url])

  const handlePlay = async () => {
    if (url || loading) return

    setLoading(true)

    try {
      const blob = await api.get(
        `/call-logs/recordings/${encodeURIComponent(recordingId)}/content`,
        { responseType: 'blob' }
      )

      setUrl(URL.createObjectURL(blob))
    } catch (err) {
      const data = err?.response?.data
      toast.error(data?.error ?? 'Enregistrement indisponible.')
    } finally {
      setLoading(false)
    }
  }

  if (url) {
    return <audio controls src={url} className="h-9 w-56" />
  }

  return (
    <button
      onClick={handlePlay}
      disabled={loading}
      className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1 text-xs font-medium hover:bg-muted transition-colors disabled:opacity-60"
    >
      {loading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Play className="h-3.5 w-3.5" />}
      Écouter
    </button>
  )
}
