# SharePoint project catalog

How to fill the **SharePoint catalog** tab: **MFA browser sync** (recommended when you can sign into SharePoint), Graph app sync, or Excel/CSV import.

Default library in this app:

- Site: `https://ahsonline.sharepoint.com/teams/AITTechnologyEngagement`
- Folder: `Shared Documents / Architectural Projects [Public]`

---

## 0. One-click Sync (Microsoft login) — set this up first

Console sync works without Entra. **One-click Sync** needs a small Entra app (no client secret).

### A. Create / open the Entra app

1. Sign in: [https://entra.microsoft.com](https://entra.microsoft.com) (or ask IT if you cannot create apps).
2. **Identity** → **Applications** → **App registrations** → **New registration**.
3. Name: `RiskRegister SharePoint Catalog`.
4. Supported account types: **Accounts in this organizational directory only** (single tenant).
5. Leave Redirect URI blank for now → **Register**.
6. On the app **Overview** page, copy:
   - **Application (client) ID** → this is Client ID
   - **Directory (tenant) ID** → this is Tenant ID  

   AdventHealth tenant is often: `6ac36678-7785-476f-be03-b68b403734c2` (confirm on Overview).

### B. Add SPA redirect (required for the Sync popup)

1. App → **Authentication** → **Add a platform** → **Single-page application**.
2. Redirect URI (must match the browser address bar exactly), e.g.:

   `http://localhost/RiskRegister/public/sharepoint.php`

   If you use a hostname or HTTPS, use that full URL instead.
3. Under **Implicit grant and hybrid flows**, you can leave boxes unchecked (MSAL uses auth code + PKCE).
4. **Save**.

### C. Add delegated Graph permission

1. App → **API permissions** → **Add a permission** → **Microsoft Graph** → **Delegated permissions**.
2. Add:
   - `Sites.Read.All` (required)
   - `User.Read` (usually already there)
3. Click **Grant admin consent for your org**  
   If that button is disabled, send IT this ask:

   > Please grant admin consent on app “RiskRegister SharePoint Catalog” for Microsoft Graph delegated permission **Sites.Read.All**.

   Without admin consent, Sync will fail with a consent / AADSTS error at AdventHealth.

### D. Paste into RiskRegister

1. Open **SharePoint catalog** while signed in as admin.
2. Scroll to **SharePoint sync & import**.
3. Paste **Tenant ID** and **Client ID** → **Save settings**.  
   Leave **Client secret** empty for one-click Sync.
4. On a folder card click **🔄 Sync**.
5. Complete MFA in the Microsoft popup → wait for refresh.

**Allow popups** for your RiskRegister site if the login window is blocked.

### Troubleshooting one-click Sync

| Symptom | Fix |
|--------|-----|
| “Save Tenant ID and Client ID” | Values missing or not GUIDs — re-copy from Entra Overview |
| Popup blocked | Allow popups, try Sync again |
| Consent / AADSTS65001 | IT must **Grant admin consent** for `Sites.Read.All` |
| redirect_uri mismatch | SPA redirect must equal `origin + path` of `sharepoint.php` (no trailing slash mismatch) |
| 403 from Graph after login | Your account cannot read that SharePoint site/folder — open the folder in the browser first |

Keep using **Console sync** until the above works.

---

## 0b. Advanced: console sync (what you use today)

No Entra app required.

1. Admin → folder card **🔐 Console sync** (or **Prepare console sync**).
2. MFA on the SharePoint tab if needed.
3. **Copy console script** → SharePoint **F12** → Console → paste → Enter.
4. Wait for ✅ Sync complete → refresh catalog.

Deep-crawls all subfolders (Visio, PDF, Office, etc.).

---

## 1. Optional: Graph app-only sync (client secret)

Use this only if IT prefers a daemon sync **without** your interactive login. It needs stronger permissions.

1. Same Entra app (or a separate one).
2. **Certificates & secrets** → **New client secret** → copy the **Value** immediately.
3. **API permissions** → Microsoft Graph → **Application permissions** → `Sites.Read.All`  
   (or `Sites.Selected` if IT grants only one site).
4. **Grant admin consent** (required).
5. In RiskRegister: Tenant ID + Client ID + Client secret → **Save** → **Test connection** → **Graph sync**.

This path does **not** replace the SPA setup above if you also want one-click Sync; you can keep both on the same app.

---

## 2. Export listing to CSV (no Graph)

The importer accepts `.csv` / `.xlsx` with flexible headers:

| Column | Required | Example |
|--------|----------|---------|
| **Name** | Yes (or Path) | `Architecture.docx` |
| **Path** | Yes (or Name) | `My Project/docs/Architecture.docx` |
| **Type** | Optional | `Folder` or `File` |
| **URL** | Optional | Full `https://...` link (built automatically if blank) |

**Path** must be relative to `Architectural Projects [Public]` (first segment = project folder name).

### Manual CSV example

```csv
Name,Path,Type,URL
Demo Project,Demo Project,Folder,https://ahsonline.sharepoint.com/teams/AITTechnologyEngagement/Shared%20Documents/Forms/AllItems.aspx?id=%2Fteams%2FAITTechnologyEngagement%2FShared%20Documents%2FArchitectural%20Projects%20%5BPublic%5D%2FDemo%20Project
Architecture.docx,Demo Project/Architecture.docx,File,https://ahsonline.sharepoint.com/teams/AITTechnologyEngagement/Shared%20Documents/Architectural%20Projects%20%5BPublic%5D/Demo%20Project/Architecture.docx
```

### Import into the app

1. Sign in as **admin**.
2. Open **SharePoint catalog**.
3. Scroll to **Import Excel / CSV**.
4. Choose the file → **Import listing**.  
   Import **replaces** the current catalog for that source.

---

## 3. PowerShell export from a mapped / synced folder

Use [`bin/Export-SharePointCatalog.ps1`](../bin/Export-SharePointCatalog.ps1) when the library is available as a normal Windows folder (OneDrive **Sync**, or a mapped drive).

### A. Make the folder available locally

**OneDrive Sync (recommended)**

1. Open the SharePoint folder in the browser:  
   [Architectural Projects [Public]](https://ahsonline.sharepoint.com/teams/AITTechnologyEngagement/Shared%20Documents/Forms/AllItems.aspx?id=%2Fteams%2FAITTechnologyEngagement%2FShared%20Documents%2FArchitectural%20Projects%20%5BPublic%5D)
2. Click **Sync** (or **Add shortcut to OneDrive**).
3. In File Explorer, find the folder under your OneDrive / organization name, e.g.  
   `C:\Users\<you>\OneDrive - AdventHealth\Architectural Projects [Public]`  
   or under a shortcut such as  
   `C:\Users\<you>\OneDrive - AdventHealth\AIT Technology Engagement - Architectural Projects [Public]`

**Mapped drive**

1. In File Explorer, map a drive to the SharePoint library (or open the synced path and note the letter).
2. Example: `S:\Architectural Projects [Public]`

### B. Run the export script

In **PowerShell** (you may need `Set-ExecutionPolicy -Scope Process Bypass` once):

```powershell
cd C:\xampp\htdocs\RiskRegister\bin

# Mapped drive example
.\Export-SharePointCatalog.ps1 -LocalPath "S:\Architectural Projects [Public]"

# OneDrive sync example (adjust the path to match your PC)
.\Export-SharePointCatalog.ps1 -LocalPath "$env:USERPROFILE\OneDrive - AdventHealth\Architectural Projects [Public]"
```

Optional parameters:

```powershell
.\Export-SharePointCatalog.ps1 `
  -LocalPath "S:\Architectural Projects [Public]" `
  -OutFile "C:\Temp\sp-catalog.csv" `
  -SiteHost "ahsonline.sharepoint.com" `
  -SitePath "/teams/AITTechnologyEngagement" `
  -LibraryFolder "Architectural Projects [Public]"
```

The script writes a UTF-8 CSV to your **Desktop** by default (`sharepoint-catalog-YYYYMMDD-HHmmss.csv`) with **Name, Path, Type, URL**, then import that file on the SharePoint catalog page.

### C. Tips

- Cloud-only OneDrive files still list in Explorer; the script records names/paths without downloading file bytes.
- If the path has brackets `[Public]`, always quote it: `"...\Architectural Projects [Public]"`.
- After export, confirm a few **URL** cells open the right folder/file in the browser while signed in to AHS.
- Keep Site host / site path / folder path on the catalog admin form matching these defaults so blank-URL rows (if any) resolve correctly.

---

## 4. Search index (reindex)

Catalog search uses SQLite B-tree indexes plus an FTS5 full-text index (`sharepoint_items_fts`).

- **Sync / import** automatically refreshes the FTS rows for that folder source.
- Admins can run a full rebuild anytime: SharePoint page → **SharePoint sync & import** → **Reindex SharePoint search**.
- Use this after large syncs or if search feels slow / incomplete vs the item count.
