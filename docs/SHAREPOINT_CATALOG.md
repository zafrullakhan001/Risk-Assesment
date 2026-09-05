# SharePoint project catalog

How to fill the **SharePoint catalog** tab: **MFA browser sync** (recommended when you can sign into SharePoint), Graph app sync, or Excel/CSV import.

Default library in this app:

- Site: `https://ahsonline.sharepoint.com/teams/AITTechnologyEngagement`
- Folder: `Shared Documents / Architectural Projects [Public]`

---

## 0. Resync with your SharePoint login (MFA) — recommended

Your PHP server cannot see SharePoint cookies. Use the admin helper on **SharePoint catalog**:

1. Sign in as **admin**.
2. Paste the folder URL into **Registered SharePoint folder URL** (or keep the saved one) → **Save settings**.
3. Click **🔐 Prepare MFA sync** (opens the folder; creates a 30‑minute token).
4. Complete MFA on the SharePoint tab if prompted.
5. Click **📋 Copy console script**.
6. On the SharePoint tab: **F12** → **Console** → paste → **Enter**.
7. When the alert says sync complete, refresh the catalog page.

This replaces the local catalog with a **deep crawl** of the registered folder (every subfolder, paginated file lists — Visio, PDF, Office, etc.).

---

## 1. Get your Tenant ID (Microsoft Entra)

You need this for **Graph sync** only. Skip this section if you use MFA browser sync or CSV import.

### Option A — Entra admin center (clearest)

1. Sign in at [https://entra.microsoft.com](https://entra.microsoft.com) with an account that can view directory info (or ask IT).
2. Go to **Identity** → **Overview** (or **Microsoft Entra ID** → **Overview**).
3. Copy **Tenant ID** (a GUID like `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`).

For AdventHealth (`ahsonline.sharepoint.com`), the Tenant ID is typically:

`6ac36678-7785-476f-be03-b68b403734c2`

(Confirm in Entra Overview if your IT changes tenants.)

### Option B — Azure portal

1. Open [https://portal.azure.com](https://portal.azure.com).
2. Search for **Microsoft Entra ID**.
3. On the Overview blade, copy **Tenant ID**.

### Option C — From any SharePoint URL (tenant name only)

Your SharePoint host `ahsonline.sharepoint.com` is the **tenant name**, not the Tenant ID GUID.  
The catalog page still needs the **GUID** from Option A/B for Graph auth.

### Also register an app (for Graph sync)

1. Entra → **App registrations** → **New registration**.
2. Name e.g. `RiskRegister SharePoint Catalog`, single tenant.
3. After create, copy **Application (client) ID**.
4. **Certificates & secrets** → **New client secret** → copy the **Value** once (this is the client secret).
5. **API permissions** → **Add a permission** → **Microsoft Graph** → **Application permissions** → add `Sites.Read.All`  
   (or `Sites.Selected` if IT will grant only this site).
6. Click **Grant admin consent** for your tenant.
7. Paste Tenant ID, Client ID, and Client secret into **SharePoint catalog** → admin settings → **Save** → **Test connection** → **Sync**.

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
