# RiskRegister ServiceNow Dossier Sync extension

Chrome/Edge Manifest V3 extension that removes the F12 console-paste step from
Ticket Dossier's ServiceNow browser sync.

## Install once

### Microsoft Edge

1. Open `edge://extensions`.
2. Turn on **Developer mode**.
3. Click **Load unpacked**.
4. Select:
   `C:\xampp\htdocs\RiskRegister\extensions\servicenow-ticket-dossier`
5. Approve the debugger permission.

### Google Chrome

1. Open `chrome://extensions`.
2. Turn on **Developer mode**.
3. Click **Load unpacked**.
4. Select the extension folder above.
5. Approve the debugger permission.

After code updates, use **Reload** on the extension card.

## Use

1. Open RiskRegister → Ticket Dossier → **Console pull from ServiceNow**.
2. Enter the ServiceNow instance and TASK number.
3. Click **Prepare + open automatically**.
4. The extension stores the prepared export for at most 30 minutes and opens
   the matching ServiceNow tab.
5. The extension briefly attaches Chrome DevTools Protocol, evaluates the same
   audited exporter that manual console sync uses, and immediately detaches.
6. Click **Export packet** on the ServiceNow overlay and choose the destination
   folder.

If the extension is absent or automatic injection fails, Ticket Dossier keeps
the existing copy/paste console fallback.

## Security model

- ServiceNow credentials and cookies never leave the ServiceNow tab.
- The extension accepts prepare messages only from local RiskRegister paths.
- It injects only into the exact ServiceNow origin prepared by RiskRegister.
- Prepared scripts expire with the server token (about 30 minutes).
- The extension detaches the debugger immediately after evaluation.
- Pending state can be inspected or cleared from the extension popup.

## Deployment paths

The packaged manifest currently matches:

- `http://localhost/RiskRegister/*`
- `http://localhost/riskregister/*`
- equivalent `127.0.0.1` paths
- `https://servicenow.adventhealth.com/*`
- `https://*.service-now.com/*`

For a deployed RiskRegister hostname/path, add its URL pattern to both
`host_permissions` and the first `content_scripts.matches` list in
`manifest.json`, then reload the unpacked extension.

## Important browser behavior

Chrome/Edge displays a debugger notification while CDP is attached. Attachment
lasts only long enough to start the exporter. Do not keep ServiceNow DevTools
open during automatic start; Chrome permits only one debugger client at a time.
