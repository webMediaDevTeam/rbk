```markdown
# RingCentral Call Control, Call Log & Recording Integration Guide

## 1. Overview & RingCentral API Blueprint

This specification details how to initiate outbound calls via a RingCentral `deviceId`, track active call status (including voicemail recording triggers), and retrieve the resulting call logs and recording assets.

---

### Step 1: Initiate Outbound Call (Call-Out)

Triggers an outbound call leg from a physical/softphone device registered to the account.

* **HTTP Method:** `POST`
* **Endpoint:** `/restapi/v1.0/account/~/telephony/call-out`
* **App Scope:** `CallControl`
* **Usage Plan:** Light

#### Request Header
```http
Authorization: Bearer <ACCESS_TOKEN>
Content-Type: application/json

```

#### Request Payload

```json
{
  "from": {
    "deviceId": "803469127021"
  },
  "to": {
    "phoneNumber": "+79817891689"
  }
}

```

#### Success Response (`201 Created`)

```json
{
  "session": {
    "id": "s-54a38392c79849dab4a25fe8040edd53",
    "creationTime": "2019-08-19T11:42:21Z",
    "origin": {
      "type": "Call"
    },
    "parties": [
      {
        "id": "p-54a38392c79849dab4a25fe8040edd53-1",
        "direction": "Outbound",
        "status": {
          "code": "Setup"
        },
        "from": {
          "deviceId": "803469127021",
          "extensionId": "297277020",
          "name": "John Smith",
          "phoneNumber": "+18885287464"
        },
        "to": {
          "phoneNumber": "+79817891689"
        }
      }
    ]
  }
}

```

---

### Step 2: Fetch Extension Call Log & Recordings

Retrieves call session metadata, completion status, and media recording endpoints.

* **HTTP Method:** `GET`
* **Endpoint:** `/restapi/v1.0/account/~/extension/~/call-log/{callRecordId}`
* **App Scope:** `ReadCallLog`
* **Usage Plan:** Heavy

#### Request Header

```http
Authorization: Bearer <ACCESS_TOKEN>
Accept: application/json

```

#### Query Parameters

| Parameter | Type | Description |
| --- | --- | --- |
| `view` | `string` | Level of detail: `Simple` or `Detailed`. |

#### Success Response (`200 OK`)

```json
{
  "id": "IXPCm_tIkCduk4I",
  "sessionId": "404412141008",
  "telephonySessionId": "b-9a03590172ea4d39a7cf7d5b6dba6a3b",
  "startTime": "2015-06-25T14:57:30.000Z",
  "duration": 60,
  "type": "Voice",
  "direction": "Inbound",
  "action": "Phone Call",
  "result": "Accepted",
  "from": {
    "phoneNumber": "+18882400004",
    "name": "Jane Smith"
  },
  "to": {
    "phoneNumber": "+18772160007",
    "name": "John Smith"
  },
  "recording": {
    "id": "401547458008",
    "uri": "[https://platform.ringcentral.com/restapi/v1.0/account/401190149008/recording/401547458008](https://platform.ringcentral.com/restapi/v1.0/account/401190149008/recording/401547458008)",
    "type": "OnDemand",
    "contentUri": "[https://media.ringcentral.com/restapi/v1.0/account/401190149008/recording/401547458008/content](https://media.ringcentral.com/restapi/v1.0/account/401190149008/recording/401547458008/content)"
  }
}

```

---

### Step 3: Fetch Binary Call Recording Content

Downloads the recorded call audio file (`.mp3` / `.wav`).

* **HTTP Method:** `GET`
* **Endpoint:** `/restapi/v1.0/account/~/recording/{recordingId}/content`
* **App Scope:** `ReadCallRecording`

#### Request Header

```http
Authorization: Bearer <ACCESS_TOKEN>

```

#### Success Response (`200 OK`)

* **Content-Type:** `audio/mpeg` or `audio/wav`
* **Body:** Binary audio stream.

---

## 2. Implementation Tasks & Technical Analysis

### Task 1: Analyze Call Screen & Explain `À :` (To) Field Consistency

* **Analysis of `À : (514) 600-5994`:**
* In the provided RingCentral interface, `Inconnu (514) 919-5929` represents the caller's ID / inbound number.
* The `À : (514) 600-5994` line represents the **Direct Inward Dialing (DID) number or RingCentral Main Company Line** assigned to your softphone app / device context.
* **Reason it is always the same:** The `À :` field displays the target RingCentral phone number attached to your active extension or device registration. Unless you switch logged-in accounts, change active extension routing rules, or initiate calls using different outbound caller IDs, the target destination assigned to your local softphone device remains static.



---

### Task 2: Ensure Recording Auto-Starts (Including Voicemail / Answering Machine)

To guarantee that call recording starts immediately upon connection—even if the call hits a voicemail box ("boîte vocale")—you must configure automatic call recording at the RingCentral account/extension level or explicitly trigger an immediate recording start via Webhook / Telephony Sessions API.

#### Solution A: Enable Automatic Call Recording (RingCentral Admin Console)

1. Log into the RingCentral Admin Portal.
2. Navigate to **Phone System > Auto-Receptionist > Call Recording** (or **Users > Extension > Call Handling & Forwarding**).
3. Enable **Automatic Call Recording** for **Outbound / Inbound Calls**.
4. Set option to start recording immediately upon call session setup (`Setup` / `Proceeding` phase).

#### Solution B: Programmatic Immediate Recording Call via Telephony Session API

If manual control over active calls is required, listen to the Telephony Webhook event (`/restapi/v1.0/account/~/telephony/sessions`) and post a record command as soon as party status changes to `Answered` or `Proceeding`:

```http
POST /restapi/v1.0/account/~/telephony/sessions/{telephonySessionId}/parties/{partyId}/record
Content-Type: application/json

{
  "id": "recording-request"
}

```

#### Status in RBK — Solution A is active, and how the recording is fetched

**Recording.** *Automatic Call Recording* (outbound) is enabled in the admin
console, so RingCentral itself records every call. The manual start sent by
`POST /api/v1/call-logs/my-call` (and retried ~90 s by
`frontend/src/hooks/use-direct-call.js`) is only a fallback: while the party is
still `Setup` (ringing) RingCentral answers
`409 TAS-102 Incorrect State [WrongState]`, mapped by
`RingCentralController::recordingFailure()` to a **retryable HTTP 409**.

**Retrieval — the call log cannot be reached by telephony session id.**

| Attempt | Result |
|---|---|
| `GET /account/~/call-log?sessionId=s-a785e453…&withRecording=true` | `400 Parameter [sessionId] is not allowed for usage along with parameter [withRecording]` |
| `GET /account/~/call-log?sessionId=s-a785e453…` | `400 Parameter [s-a785e453…] value is invalid.` — the call log `sessionId` is **numeric** (`675097585025`), never the telephony session id (`s-…`) stored in `call_logs.ringcentral_session_id` |

What works is **number + time window, at account scope** (app-made calls are
placed on the authenticated extension, not on the employee's own extension):

```http
GET /restapi/v1.0/account/~/call-log?phoneNumber=15148494526&direction=Outbound
    &dateFrom=2026-10-08T08:26:58.000Z&view=Detailed&withRecording=true&perPage=10
```

* `RingCentralService::findCallLogByTarget()` — E.164 normalisation of any
  input format (`514-849-4526`, `+1 (514) 849-4526`…), then the record whose
  `startTime` is the closest to the journal row (±5 min).
* `RingCentralController::pullRemoteCall()` — runs whenever the call-details
  modal opens on a row without recordings: the row is given its
  `ringcentral_call_id`, enriched (duration, result, raw) through
  `RingCentralSyncService::upsertCall()` — the telephony session id `s-…` is
  written back afterwards, it is the only one usable by
  `/telephony/sessions/{id}` — and the audio lands in `call_recordings`, served
  by `GET /api/v1/call-logs/client-calls/{callLog}/recordings/{recording}/content`.
* `RingCentralSyncService::matchHotCall()` — the same number + time matching
  during `POST /call-logs/employees/{id}/logs/sync`, so a synchronisation
  completes a row opened hot by the application instead of duplicating it
  (client and system note stay on the same line).

Verified live on 2026-10-08: recording `3237454278025` (`audio/mpeg`, 43 KB)
pulled for call log `N9e_V3Rr_TRSDUA`.

---

### Task 3: Backend Implementation (Node.js Call-Out, Webhook Listener & Recording Retrieval)

```javascript
const SDK = require('@ringcentral/sdk');

const rcsdk = new SDK({
  server: SDK.server.sandbox, // Use SDK.server.production for production
  clientId: process.env.RC_CLIENT_ID,
  clientSecret: process.env.RC_CLIENT_SECRET
});

const platform = rcsdk.platform();

async function init() {
  await platform.login({
    jwt: process.env.RC_JWT
  });
}

/**
 * 1. Make Outbound Call by Device ID
 */
async function makeCallOut(deviceId, targetPhoneNumber) {
  try {
    const response = await platform.post('/restapi/v1.0/account/~/telephony/call-out', {
      from: { deviceId: deviceId },
      to: { phoneNumber: targetPhoneNumber }
    });
    
    const data = await response.json();
    const sessionId = data.session.id;
    console.log(`Call Initiated. Session ID: ${sessionId}`);
    return data.session;
  } catch (error) {
    console.error('Call-out failed:', error.message);
    throw error;
  }
}

/**
 * 2. Retrieve Call Log & Recording Data by Call Record ID
 */
async function getCallLogAndRecording(callRecordId) {
  try {
    // Retrieve specific call record details
    const response = await platform.get(`/restapi/v1.0/account/~/extension/~/call-log/${callRecordId}`, {
      view: 'Detailed'
    });
    const record = await response.json();

    console.log(`Call Result: ${record.result}`);
    console.log(`Call Duration: ${record.duration} seconds`);

    // Check if recording exists
    if (record.recording && record.recording.contentUri) {
      console.log(`Recording ID: ${record.recording.id}`);
      console.log(`Recording Content URI: ${record.recording.contentUri}`);
      
      // Fetch binary audio content
      const recordingResponse = await platform.get(record.recording.contentUri);
      const audioBuffer = await recordingResponse.buffer();
      
      return {
        log: record,
        audioBuffer: audioBuffer
      };
    } else {
      console.log('No recording found for this call session.');
      return { log: record, audioBuffer: null };
    }
  } catch (error) {
    console.error('Error fetching call log/recording:', error.message);
    throw error;
  }
}

// Example Execution Workflow
(async () => {
  await init();
  
  // Trigger call
  const session = await makeCallOut("803469127021", "+79817891689");
  
  // Note: Call log and recordings are finalized after the call session completes.
})();

```

```

```