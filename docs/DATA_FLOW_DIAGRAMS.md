# Risk Register data flow diagrams

Mermaid source for Ticket Dossier, SharePoint Catalog, and SharePoint Owners. Copy each fenced `mermaid` block into Mermaid Live, mermaid-cli, or an imaging app.

**Relationship in one sentence:** Catalog is the searchable SharePoint listing; Owners is a read model over the same `person` and date fields; Ticket Dossier is a signed-in ServiceNow packet viewer on its own SQLite file and has no public share and no SharePoint foreign key.

Ticket Dossier is a **separate ServiceNow packet store**. SharePoint Catalog and SharePoint Owners are **two views of the same SharePoint index**. They share login and navigation, but dossier records never join catalog folders.

If you want a single combined poster, start with diagram **1**, then drop **2**, **3**, and **4** as swimlanes under it.

---

## 1. Context diagram — who uses what, and which systems sit outside the app

```mermaid
flowchart TB
    subgraph PEOPLE["People who use Risk Register"]
        Assessor["Signed-in assessor / engagement / architecture user<br/>Must be approved and not disabled.<br/>Opens Ticket Dossier, Catalog, and Owners<br/>after AppModules gates allow each module."]
        Admin["Administrator / Superadmin<br/>Registers SharePoint folder sources,<br/>runs sync or import, manages tags and archives,<br/>creates public catalog or owners links,<br/>issues MCP API tokens.<br/>Superadmin can open a disabled module."]
        Public["Anonymous share recipient<br/>Opens catalog-share.php or owners-share.php<br/>with an opaque token. Read-only browse/search only.<br/>Cannot sync, edit, or open Ticket Dossier."]
        AI["AI assistant / MCP client<br/>for example Cursor using the catalog tools.<br/>Authenticates with a ramcp_ bearer token or API key.<br/>Catalog search and stats only — not Ticket Dossier."]
    end

    subgraph APP["Risk Register PHP 8 + SQLite web application"]
        Auth["Session authentication<br/>Local account and/or LDAP.<br/>Auth::requireAuth on signed-in pages.<br/>Module flags: app_ticket_dossier_enabled,<br/>app_sharepoint_enabled."]
        TD["Ticket Dossier module<br/>URL: public/ticket-dossier/<br/>Purpose: turn ServiceNow Demand, Story, Task,<br/>and DDR exports into one readable dossier.<br/>Does not replace the Excel assessment register."]
        CAT["SharePoint Catalog module<br/>URL: sharepoint.php?view=catalog<br/>Purpose: searchable index of architecture<br/>project folders. Stores names, paths, people,<br/>sizes, and tags — not file body content."]
        OWN["SharePoint Owners module<br/>URL: sharepoint.php?view=owners<br/>Purpose: group catalog project folders<br/>by Created By person, then show period,<br/>dormancy, collaborators, and assessment chips."]
        RISK["Excel risk assessments<br/>optional soft link only<br/>Match assessments.solution_name<br/>to sharepoint_items.project_name."]
    end

    subgraph EXT["External systems — credentials never stored for these flows"]
        SN["ServiceNow instance<br/>User stays signed in to ServiceNow in the browser.<br/>Extension or F12 console script reads TASK,<br/>Task Relationships, and attachments.<br/>Risk Register never stores a ServiceNow password."]
        SPO["SharePoint Online + Microsoft Graph / Entra ID<br/>Delegated MSAL sync, app-only Graph crawl,<br/>or browser/MFA console listing.<br/>Risk Register stores listing metadata only."]
        SMTP["Optional SMTP<br/>Emails catalog or owners public share links<br/>when Admin Email is configured."]
    end

    Assessor -->|"Sign in, then open Ticket Dossier,<br/>Catalog, or Owners"| Auth
    Admin -->|"Sign in, then administer sources,<br/>sync, tags, shares, MCP tokens"| Auth
    Auth --> TD
    Auth --> CAT
    Auth --> OWN
    Public -->|"Token in query string.<br/>No session cookie."| CAT
    Public -->|"Token in query string.<br/>Owner cards only."| OWN
    AI -->|"MCP catalog tools"| CAT
    TD -->|"Console or extension packet import.<br/>CORS from ServiceNow origin.<br/>Short-lived sync token."| SN
    CAT -->|"Admin sync / import listing rows"| SPO
    OWN -.->|"Read-only aggregation.<br/>No extra SharePoint call."| CAT
    CAT -.->|"Optional name match:<br/>SharePoint folder panel"| RISK
    OWN -.->|"Optional name match:<br/>Has assessment chip"| RISK
    Admin -->|"Email this link"| SMTP
    SMTP -->|"Share URL to recipient"| Public
```

---

## 2. Ticket Dossier — detailed data flow

```mermaid
flowchart TB
    subgraph ACTORS["Actors"]
        User["Signed-in user with Ticket module on<br/>Creates and reads dossiers they own.<br/>Owner fields are metadata, not a hard ACL lock."]
        ExtUser["Same user, still signed in to ServiceNow<br/>Runs the bundled Chrome/Edge extension<br/>or pastes the F12 console exporter."]
    end

    subgraph PAGES["Ticket Dossier pages — public/ticket-dossier/"]
        Index["index.php — Projects list<br/>Search title, vendor, owner, demand,<br/>story, task, and DDR numbers.<br/>New project, Console pull, ZIP import,<br/>export-all, delete."]
        Upload["upload.php — multipart create<br/>Up to 10 files, 15 MB each,<br/>PDF and JSON."]
        Classify["classify.php — AJAX classify<br/>before the user submits."]
        Project["project.php — chapter view<br/>Demand, Story, Task, DDR,<br/>related tickets, vendor,<br/>questionnaires, source pills."]
        Summary["summary.php — product-style summary"]
        FileAct["file-actions.php — reparse or delete stored files"]
        FieldEd["field-edit.php / update.php / edit.php<br/>Patch title, vendor, owner, parsed fields"]
        Browser["browser-sync.php<br/>Prepare step requires session + CSRF.<br/>Import step accepts the packet with<br/>the short-lived token, no session."]
        ZipIO["export.php, export-zip.php, import-zip.php, download.php<br/>JSON or ZIP backup. Import creates NEW project IDs."]
    end

    subgraph PIPE["Ingest and parse pipeline"]
        FC["FileClassifier<br/>Looks at content first, then filename.<br/>Kinds: ddr, demand, story, task, packet."]
        DDR["DdrJsonParser<br/>Due Diligence questionnaire JSON."]
        PDF["ServicenowPdfParser<br/>Demand / Story / Task PDF text and fields."]
        PKT["ServicenowTaskPacketParser<br/>Console packet: TASK + relationships + attachments."]
        Imp["ProjectImporter::import<br/>Writes original bytes to disk<br/>and upserts the projects row."]
    end

    subgraph STORE["Isolated Ticket Dossier store — not the main register database"]
        DB[("ticketdetails.sqlite<br/>Table projects:<br/>id, title, vendor,<br/>demand_number, story_number, task_number, ddr_number,<br/>state columns, sources_json, parsed_json,<br/>owner_user_id, owner_username,<br/>owner_display_name, owner_auth_source,<br/>created_at, updated_at<br/><br/>Table project_files:<br/>kind, original_name, stored_name, size_bytes<br/><br/>Table servicenow_browser_sync:<br/>token_hash, instance_origin, task_number,<br/>owner fields, optional project_id,<br/>expires_at about 30 minutes")]
        Disk[("database/ticket-dossier-storage/<br/>projects/{project_id}/<br/>Original PDF and JSON bytes<br/>Downloadable; ServiceNow is not re-queried later")]
    end

    subgraph SN["ServiceNow — live only during console pull"]
        TaskAPI["TASK record + Task Relationships<br/>+ attachment bytes<br/>Called in the user's browser session"]
    end

    User --> Index
    Index -->|"New project dropzone"| Upload
    Upload --> Classify
    Classify --> FC
    FC --> DDR
    FC --> PDF
    FC --> PKT
    DDR --> Imp
    PDF --> Imp
    PKT --> Imp
    Imp -->|"INSERT/UPDATE denormalized index<br/>and parsed_json view model"| DB
    Imp -->|"Save originals"| Disk

    User -->|"Console pull from ServiceNow"| Browser
    Browser -->|"Prepare: store token hash"| DB
    Browser -->|"Open ServiceNow with token"| ExtUser
    ExtUser -->|"Export packet overlay<br/>writes TASK….json and attachments/"| TaskAPI
    TaskAPI -->|"POST packet + files + token<br/>CORS from ServiceNow origin"| Browser
    Browser --> PKT

    Index --> Project
    Index --> Summary
    Project --> FileAct
    FileAct -->|"Reparse selected files"| FC
    Project --> FieldEd
    FieldEd -->|"DossierFieldEditor patches<br/>parsed_json and ticket number columns"| DB
    Project -->|"Download original"| Disk
    Index --> ZipIO
    ZipIO -->|"ProjectPackager clone"| DB
    ZipIO --> Disk

    note1["Displayed data is always the stored parsed_json.<br/>Deleting a dossier deletes local files only.<br/>The ServiceNow tickets are unchanged.<br/>There is no public share URL for Ticket Dossier."]
```

---

## 3. SharePoint Catalog — detailed data flow

```mermaid
flowchart TB
    subgraph WHO["Who feeds and who reads the catalog"]
        Admin["Admin registers a source<br/>source_key, title, folder_url,<br/>site_host, site_path, folder_path"]
        User["Signed-in user<br/>Search, open project dialog,<br/>favorites, QR / copy SharePoint URL"]
        Public["Public catalog share holder<br/>catalog-share.php?t=…<br/>Allowed source_keys only"]
        MCP["MCP client<br/>public/api/mcp.php<br/>search, list projects, get project,<br/>list sources, tags, stats"]
    end

    subgraph INGEST["Four admin ingest paths — each REPLACE-all for that source_key"]
        MSAL["One-click Sync MSAL<br/>Entra SPA Client ID + Tenant ID<br/>Delegated Graph Sites.Read.All"]
        Graph["App-only Graph crawl<br/>Client secret + application Sites.Read.All<br/>SharePointGraphClient"]
        Console["Browser / MFA console sync<br/>Prepare token on the source,<br/>run script in the SharePoint tab,<br/>POST rows to action=browser_sync_import<br/>before session auth, CORS from SharePoint origin"]
        Excel["Excel or CSV listing import<br/>SharePointListingImporter<br/>or PowerShell Export-SharePointCatalog.ps1"]
    end

    subgraph SP["SharePoint Online library root<br/>Architecture project folders under the registered path"]
        Lib["Folder listing: name, relative_path,<br/>item_type file or folder, mime_type,<br/>size_bytes, last_modified, date_created,<br/>modified_by, Created By person, web_url"]
    end

    subgraph PROC["Catalog processing on the server"]
        Imp["Importer writes rows then rebuilds FTS<br/>REPLACE FROM sharepoint_items WHERE source_key = ?<br/>then refresh sharepoint_items_fts"]
        Qry["SharePointCatalogQuery<br/>Operators: tag: ext: person: path: has:<br/>quoted phrase, -exclude, fuzzy / deep,<br/>AND or OR mode"]
        Cmp["SharePointCatalogComparer<br/>Diff two sources or snapshots"]
        Soft["SharePointCatalogRepository<br/>findMatchingProjectAnySource(solution_name)<br/>soft-links the assessment dashboard"]
    end

    subgraph DATA[("Main app SQLite — shared by Catalog and Owners<br/>NOT ticketdetails.sqlite")]
        Src["sharepoint_sources<br/>Registered folder roots and sync status,<br/>sync_token_hash / expiry, sort_order"]
        Items["sharepoint_items<br/>source_key + item_key unique,<br/>parent_item_key, project_name,<br/>name, item_type, relative_path, web_url,<br/>mime_type, size_bytes, last_modified,<br/>date_created, modified_by, person, synced_at"]
        FTS["sharepoint_items_fts FTS5<br/>Search mirror of name, path, people, project"]
        Tags["sharepoint_search_tags<br/>+ sharepoint_search_tag_assignments<br/>Keyed by source_key, scope project or item,<br/>project_name, relative_path — survive resync"]
        Arch["sharepoint_archives<br/>Hide source / project / path from default search"]
        Fav["user_catalog_favorites<br/>Per signed-in user"]
        Share["catalog_share_links kind=catalog<br/>token_hash, source_keys JSON,<br/>label, revoke, expiry, audit"]
    end

    subgraph UI["What the catalog displays — metadata only, never file bodies"]
        Hub["sharepoint.php hub<br/>Folders + search + admin panels"]
        Cards["Project cards / table<br/>Nested file tree, people, sizes,<br/>match highlights, SharePoint deep links, QR"]
        Assess["Assessment dashboard<br/>SharePoint folder panel when names match"]
    end

    Admin --> Src
    Admin --> MSAL
    Admin --> Graph
    Admin --> Console
    Admin --> Excel
    MSAL --> Lib
    Graph --> Lib
    Console --> Lib
    Excel --> Lib
    Lib --> Imp
    Imp --> Items
    Imp --> FTS
    User --> Hub
    Hub --> Qry
    Qry --> FTS
    Qry --> Items
    Qry --> Tags
    Qry --> Arch
    Hub --> Cards
    User --> Fav
    Admin --> Tags
    Admin --> Arch
    Admin --> Share
    Public --> Share
    Public --> Qry
    MCP --> Qry
    Hub --> Cmp
    Items --> Soft
    Soft --> Assess
```

---

## 4. SharePoint Owners — detailed data flow

```mermaid
flowchart TB
    subgraph READERS["Readers of the Owners board"]
        User["Signed-in SharePoint user<br/>Top bar Owners, or sharepoint.php?view=owners<br/>Filter, sort, compare 2 to 3 owners,<br/>CSV export, print snapshot"]
        Admin["Admin creates a public owners link<br/>Share project owner cards<br/>kind=owners on catalog_share_links"]
        Public["Anonymous recipient<br/>owners-share.php?t=…<br/>Owner cards and stats only.<br/>No sync, no edit, no assessment chips."]
    end

    subgraph SOURCE["Same rows Catalog already stored — Owners does not crawl SharePoint again"]
        Items[("sharepoint_items root project folders<br/>person ≈ SharePoint Created By<br/>date_created → month / quarter / year<br/>modified_by and other people → collaborators<br/>item_count, file_count, size_bytes, last_modified")]
        Assess[("assessments.solution_name<br/>case-normalized name match to project_name<br/>Produces assessment_count and Has assessment chip<br/>This join is skipped on the public owners share")]
        Shares[("catalog_share_links kind=owners<br/>token, allowed source_keys, expiry, revoke")]
    end

    subgraph ENGINE["SharePointOwnerDashboard.php<br/>JSON actions: owner_stats, optional owner_storage_stats"]
        Key["Build owner_key / owner_name from person<br/>Special buckets: _unassigned and unknown dates"]
        Time["Bucket each project into month, quarter, year"]
        Agg["Aggregate per owner:<br/>project count, streak, items, size,<br/>newest activity, dormancy"]
        Collab["Collect collaborators from modified_by<br/>and other person values on child items"]
        Join["LEFT-side soft join to assessments<br/>signed-in view only"]
        JSON["Return leaderboard, period matrix,<br/>folder list for a clicked cell,<br/>side-by-side compare payload"]
    end

    subgraph UI["What people see"]
        Board["Owner cards and period heatmap<br/>Click a cell to list folders in that period"]
        Compare["Compare 2–3 owners side by side"]
        Out["CSV download or print snapshot"]
        PublicUI["Public owners card board<br/>stats without assessment linkage"]
    end

    User --> Key
    Admin --> Shares
    Public --> Shares
    Shares --> PublicUI
    Items --> Key
    Key --> Time
    Time --> Agg
    Items --> Collab
    Agg --> Join
    Assess --> Join
    Collab --> JSON
    Join --> JSON
    JSON --> Board
    JSON --> Compare
    JSON --> Out
    JSON --> PublicUI
```

---

## 5. How the three modules relate — and what they do not share

```mermaid
flowchart LR
    subgraph AUTH["Shared chrome only"]
        Session["Login session, theme, top navigation,<br/>AppModules on/off flags"]
    end

    subgraph LEFT["Workstream A — ServiceNow packet"]
        TD["Ticket Dossier<br/>database/ticketdetails.sqlite<br/>database/ticket-dossier-storage/<br/>ServiceNow Demand / Story / Task / DDR"]
    end

    subgraph RIGHT["Workstream B — SharePoint architecture folders"]
        Items[("sharepoint_items + FTS + tags")]
        CAT["Catalog<br/>search and project tree"]
        OWN["Owners<br/>Created By aggregation"]
        RISK["Risk assessments<br/>optional name match only"]
    end

    Session --> TD
    Session --> CAT
    Session --> OWN
    Items --> CAT
    Items --> OWN
    CAT -->|"solution_name ≈ project_name"| RISK
    OWN -->|"Has assessment chip"| RISK

    TD -.-x CAT
    TD -.-x OWN
    TD -.-x RISK
```

---

## 6. Sequence — Catalog sync versus Dossier console pull

These two ingest paths look similar (browser token, CORS, no stored password) but they write to **different databases**.

```mermaid
sequenceDiagram
    autonumber
    actor Admin as Admin signed in
    actor User as Assessor signed in
    participant RR as Risk Register
    participant SP as SharePoint Online
    participant SN as ServiceNow
    participant CatDB as Main SQLite sharepoint_items
    participant TdDB as ticketdetails.sqlite plus file storage

    rect rgb(240,248,255)
        Note over Admin,CatDB: Catalog ingest — listing metadata only, never file bodies
        Admin->>RR: Register source_key and folder_url
        Admin->>RR: Start MSAL, Graph, console, or Excel import
        RR->>SP: List folders and files under the registered path
        SP-->>RR: name, path, person, dates, size, web_url
        RR->>CatDB: REPLACE all rows for that source_key, then rebuild FTS
        Note over CatDB: Owners dashboard reads these rows next. No second crawl.
    end

    rect rgb(255,248,240)
        Note over User,TdDB: Ticket Dossier ingest — original PDFs/JSON plus parsed_json
        User->>RR: New project upload, or Console pull from ServiceNow
        alt Manual upload
            User->>RR: PDF / DDR JSON up to 10 files, 15 MB each
            RR->>TdDB: Classify, parse, store bytes, upsert projects
        else Console / extension
            User->>RR: Prepare sync token, session plus CSRF
            RR->>TdDB: Store token_hash, expires in about 30 minutes
            User->>SN: Export packet overlay in the ServiceNow tab
            SN-->>RR: POST TASK packet, relationships, attachments, token
            RR->>TdDB: Same ProjectImporter path as packet JSON
        end
        Note over TdDB: Later views read parsed_json only. ServiceNow is not live-queried.
    end
```
