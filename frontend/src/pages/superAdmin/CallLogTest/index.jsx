import { useState } from 'react'
import { api } from '@/api/client.js'

export default function CallLogTestPage() {
  const [users, setUsers] = useState([])
  const [userCalls, setUserCalls] = useState([])
  const [phoneCalls, setPhoneCalls] = useState([])

  const [extensionId, setExtensionId] = useState('~')
  const [phone, setPhone] = useState('')

  const [loadingUsers, setLoadingUsers] = useState(false)
  const [loadingUserCalls, setLoadingUserCalls] = useState(false)
  const [loadingPhoneCalls, setLoadingPhoneCalls] = useState(false)

  const [error, setError] = useState(null)

  const fetchUsers = async () => {
    setError(null)
    setLoadingUsers(true)
    try {
      const res = await api.get('/call-logs/users')
      setUsers(res.data || [])
    } catch (err) {
      setError(err.response?.data?.error || err.message)
    } finally {
      setLoadingUsers(false)
    }
  }

  const fetchUserCalls = async () => {
    setError(null)
    setLoadingUserCalls(true)
    try {
      const res = await api.get(`/call-logs/users/${encodeURIComponent(extensionId)}`)
      setUserCalls(res.data || [])
    } catch (err) {
      setError(err.response?.data?.error || err.message)
    } finally {
      setLoadingUserCalls(false)
    }
  }

  const fetchPhoneCalls = async () => {
    if (!phone) {
      setError('Veuillez renseigner un numéro de téléphone.')
      return
    }
    setError(null)
    setLoadingPhoneCalls(true)
    try {
      const res = await api.get(`/call-logs/by-phone/${encodeURIComponent(phone)}`)
      setPhoneCalls(res.data || [])
    } catch (err) {
      setError(err.response?.data?.error || err.message)
    } finally {
      setLoadingPhoneCalls(false)
    }
  }

  return (
    <div style={{ padding: 24, fontFamily: 'sans-serif', maxWidth: 1000, margin: '0 auto' }}>
      <h2>Test API RingCentral (Call Logs)</h2>
      <p style={{ color: '#666' }}>Page de test Super Admin — données brutes depuis RingCentral API</p>

      {error && (
        <div style={{ background: '#fee', border: '1px solid #fcc', color: '#c00', padding: 12, borderRadius: 4, marginBottom: 16 }}>
          <strong>Erreur :</strong> {error}
        </div>
      )}

      {/* 1. Utilisateurs / Extensions */}
      <div style={{ border: '1px solid #ddd', padding: 16, borderRadius: 6, marginBottom: 20 }}>
        <h3>1. Liste des utilisateurs / Extensions RingCentral</h3>
        <button onClick={fetchUsers} disabled={loadingUsers} style={{ padding: '8px 16px', cursor: 'pointer' }}>
          {loadingUsers ? 'Chargement...' : 'GET /api/v1/call-logs/users'}
        </button>
        {users.length > 0 && (
          <div style={{ marginTop: 12, maxHeight: 200, overflowY: 'auto' }}>
            <table border="1" cellPadding="6" style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Nom</th>
                  <th>Extension</th>
                  <th>Statut</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                {users.map((u) => (
                  <tr key={u.id}>
                    <td>{u.id}</td>
                    <td>{u.name || `${u.contact?.firstName || ''} ${u.contact?.lastName || ''}`}</td>
                    <td>{u.extensionNumber}</td>
                    <td>{u.status}</td>
                    <td>
                      <button
                        onClick={() => {
                          setExtensionId(u.id)
                        }}
                        style={{ fontSize: 12 }}
                      >
                        Tester cette extension
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* 2. Appels par extension */}
      <div style={{ border: '1px solid #ddd', padding: 16, borderRadius: 6, marginBottom: 20 }}>
        <h3>2. Historique des appels par utilisateur (extension)</h3>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 12 }}>
          <label>Extension ID :</label>
          <input
            type="text"
            value={extensionId}
            onChange={(e) => setExtensionId(e.target.value)}
            placeholder="~ ou ID de l'extension"
            style={{ padding: '6px 10px' }}
          />
          <button onClick={fetchUserCalls} disabled={loadingUserCalls} style={{ padding: '6px 12px', cursor: 'pointer' }}>
            {loadingUserCalls ? 'Chargement...' : `GET /call-logs/users/${extensionId}`}
          </button>
        </div>
        {userCalls.length > 0 && (
          <div style={{ maxHeight: 250, overflowY: 'auto' }}>
            <pre style={{ background: '#f5f5f5', padding: 10, fontSize: 12, borderRadius: 4 }}>
              {JSON.stringify(userCalls, null, 2)}
            </pre>
          </div>
        )}
      </div>

      {/* 3. Appels par numéro cible */}
      <div style={{ border: '1px solid #ddd', padding: 16, borderRadius: 6, marginBottom: 20 }}>
        <h3>3. Historique des appels vers un numéro cible ("To")</h3>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 12 }}>
          <label>Numéro (ex: 14155552671) :</label>
          <input
            type="text"
            value={phone}
            onChange={(e) => setPhone(e.target.value)}
            placeholder="E.164 sans + (ex: 15145550123)"
            style={{ padding: '6px 10px', width: 220 }}
          />
          <button onClick={fetchPhoneCalls} disabled={loadingPhoneCalls} style={{ padding: '6px 12px', cursor: 'pointer' }}>
            {loadingPhoneCalls ? 'Chargement...' : 'GET /call-logs/by-phone/{phone}'}
          </button>
        </div>
        {phoneCalls.length > 0 && (
          <div style={{ maxHeight: 250, overflowY: 'auto' }}>
            <pre style={{ background: '#f5f5f5', padding: 10, fontSize: 12, borderRadius: 4 }}>
              {JSON.stringify(phoneCalls, null, 2)}
            </pre>
          </div>
        )}
      </div>
    </div>
  )
}
