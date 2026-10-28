# Important Mail & Telegram Digest

plMail can track high-priority messages that require prompt reading or action, notify immediately
over Telegram during active hours, and provide a single-line JSON digest for the morning routine.

## Categories Tracked

1. **Government (`government`):** Messages from public administration bodies (federal, state,
   municipal), tax authorities, judiciary, and legislative branches (e.g. `gov.br`, `jus.br`,
   Receita Federal, Detran, INSS, courts).
2. **Bills & Invoices (`bill`):** Invoices, utility bills, banking statements, and payment slips
   requiring action (e.g. Nubank, Itaú, Enel, Sabesp, telecom providers, condomínio).
3. **Family (`family`):** Messages from close relatives, matched against Google Contacts tagged
   with `Family`, `Família`, or `Familia`.

## Notification Window & Night Hold

- **Active Window (06:00 to 20:00, `America/Sao_Paulo`):** Candidate messages trigger an immediate
  HTML-formatted Telegram alert to the configured home channel.
- **Overnight (20:00 to 06:00):** Messages are classified and persisted, but no immediate alert is
  sent. They are marked as `after_hours` and receive top priority in the morning digest.

## Morning Digest (`app:important:digest`)

Run via console:
```bash
php bin/console app:important:digest
```
Outputs a single line of JSON to stdout:
```json
{
  "generated_at": "2026-10-28T06:00:00-03:00",
  "family_tag_found": true,
  "items": [
    {
      "kind": "bill",
      "from": "fatura@nubank.com.br",
      "subject": "Fatura Fechada",
      "received_at": "2026-10-27T22:30:00-03:00",
      "age_hours": 7,
      "after_hours": true,
      "notified": false,
      "amount": "R$ 1.234,56",
      "due": "10/11"
    }
  ]
}
```

A message remains pending until it is both **seen** (`seenAt !== null`) and **archived** (out of
the Inbox). Trashed or spammed messages are excluded.

## Configuration & Environment Variables

| Variable | Default | Purpose |
| :--- | :--- | :--- |
| `IMPORTANT_MAIL_TZ` | `America/Sao_Paulo` | Timezone used for the notification window and digest calculations. |
| `IMPORTANT_MAIL_SINCE` | `""` | ISO-8601 cutoff timestamp. Messages received before this are ignored (prevents alerting on historical imports). |
| `IMPORTANT_MAIL_BASE_URL` | `http://host.docker.internal:20129` | OpenAI-compatible endpoint for model classification fallback. |
| `IMPORTANT_MAIL_MODEL` | `""` | Model name. If empty, classification runs exclusively on rules. |
| `IMPORTANT_MAIL_API_KEY` | `none` | API key for the model endpoint. |
| `TELEGRAM_ENV_FILE` | `/run/hermes.env` | File containing `TELEGRAM_BOT_TOKEN` and `TELEGRAM_HOME_CHANNEL`. |

### Privacy & Model Safety

Email content must **never** touch a free model tier. `ModelClassifier` enforces a strict check: if
the model name is empty or contains `free`, `auto`, or `combo` (case-insensitive), the model
classifier is automatically disabled.

## Provider Connection Setup (Gmail & Outlook)

To connect Gmail and Outlook accounts in the plMail web interface (**Settings → Accounts**):

### Google Cloud (Gmail)
1. In Google Cloud Console, create or select a project.
2. Enable the **Gmail API** and **Google People API** (for contact tags).
3. Configure OAuth consent screen.
   > **Note:** If the app is kept in **"Testing"** publishing status, user refresh tokens expire
   > after 7 days. Move the app to **"In production"** (or internal if Google Workspace) to keep
   > durable authorization tokens.
4. Create OAuth 2.0 Client ID (Web Application) with redirect URI:
   `https://<your-domain>/auth/google/callback`.
5. Enter Client ID and Secret in plMail.

### Microsoft Entra / Azure (Outlook / 365)
1. In Microsoft Entra admin center, register a new application.
2. Under Authentication, add platform "Web" with redirect URI:
   `https://<your-domain>/auth/microsoft/callback`.
3. Create a client secret under Certificates & Secrets.
4. Under API Permissions, grant `Mail.ReadWrite`, `Mail.Send`, `offline_access`.
5. Enter Client ID, Client Secret, and Tenant in plMail.

### Known Caveats
- **Family Tag Matching:** Family contacts are resolved by matching email addresses in Google
  Contacts marked with `Family` or `Família`. Contacts that have only a name or relationship label
  without an associated email address cannot be matched against incoming messages.
