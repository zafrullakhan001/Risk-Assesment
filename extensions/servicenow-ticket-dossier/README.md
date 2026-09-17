# RiskRegister Browser Sync extension

Chrome/Edge Manifest V3 extension that removes the F12 console-paste step from
the read-only SharePoint catalog sync and Ticket Dossier's ServiceNow export.

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

## Use with SharePoint

1. Open RiskRegister → SharePoint Catalog.
2. Click **Console sync** for a configured source.
3. The extension stores the prepared sync for at most 30 minutes, opens the
   matching SharePoint folder, and opens the read-only sync toaster.
4. Click **Start sync** in the toaster. Scanning does not begin before this
   confirmation.
5. The toaster reports progress, local catalog changes, and completion.

The SharePoint integration is strictly read-only. It requests
`Sites.Read.All`, uses only SharePoint read/query endpoints, and writes results
only to the local RiskRegister catalog.

## Use with ServiceNow

1. Open RiskRegister → Ticket Dossier → **Console pull from ServiceNow**.
2. Enter the ServiceNow instance and ticket number (`TASK…`, `DMND…`, `STRY…`, `DDR…`, or `PRJ…`).
   For projects, Demand/Story/Tasks/Changes and RIDAC items (risks, issues, decisions) are pulled;
   status reports, time cards, and cost tabs are skipped.
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
- SharePoint credentials and cookies never leave the SharePoint tab.
- SharePoint access is read-only; the extension does not create, edit, move, or
  delete SharePoint content.
- The extension accepts prepare messages only from local RiskRegister paths.
- It injects only into the exact ServiceNow or SharePoint origin prepared by
  RiskRegister.
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
- `https://*.sharepoint.com/*`

For a deployed RiskRegister hostname/path, add its URL pattern to both
`host_permissions` and the first `content_scripts.matches` list in
`manifest.json`, then reload the unpacked extension.

## Important browser behavior

Chrome/Edge displays a debugger notification while CDP is attached. Attachment
lasts only long enough to start the exporter. Do not keep ServiceNow DevTools
open during automatic start; Chrome permits only one debugger client at a time.
