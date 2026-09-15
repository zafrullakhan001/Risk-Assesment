/**
 * Builds the SharePoint console crawler script for MFA browser sync.
 * Prefers RecursiveAll list query (matches AllItems UI, includes .vsdx Visio files),
 * then falls back to paginated Folders/Files walk.
 */
(() => {
  function buildConsoleScript(cfg) {
    const configJson = JSON.stringify({
      token: cfg.token,
      importUrl: cfg.import_url,
      sourceKey: cfg.source_key || '',
      sitePath: cfg.site_path,
      rootServerRelative: cfg.server_relative_folder,
      maxRows: 25000,
      maxDepth: 30,
      pageSize: 5000,
    });

    return `void (async function () {
  const CFG = ${configJson};
  if (window.__rrSharePointSyncRunning) {
    console.warn("RiskRegister SharePoint sync is already running.");
    return;
  }
  window.__rrSharePointSyncRunning = true;

  const existingToast = document.getElementById("rr-sharepoint-sync-toast");
  if (existingToast) existingToast.remove();
  const existingStyle = document.getElementById("rr-sharepoint-sync-style");
  if (existingStyle) existingStyle.remove();

  const style = document.createElement("style");
  style.id = "rr-sharepoint-sync-style";
  style.textContent =
    "@keyframes rrSpToastIn{from{opacity:0;transform:translateY(18px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}" +
    "@keyframes rrSpPulse{0%,100%{box-shadow:0 12px 40px rgba(0,0,0,.35)}50%{box-shadow:0 12px 40px rgba(20,184,166,.28)}}" +
    "@keyframes rrSpShimmer{0%{transform:translateX(-120%)}100%{transform:translateX(220%)}}" +
    "@keyframes rrSpFlow{0%{left:0;opacity:0}12%{opacity:1}88%{opacity:1}100%{left:calc(100% - 18px);opacity:0}}" +
    "@keyframes rrSpNodePulse{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}" +
    "@keyframes rrSpLane{0%{background-position:0 0}100%{background-position:24px 0}}" +
    "#rr-sharepoint-sync-toast{animation:rrSpToastIn .45s cubic-bezier(.22,1,.36,1) both}" +
    "#rr-sharepoint-sync-toast.is-running{animation:rrSpPulse 2.2s ease-in-out infinite}" +
    "#rr-sharepoint-sync-toast .rr-sp-flow{margin-top:12px;padding:10px 8px;border-radius:10px;background:rgba(15,23,42,.65);border:1px solid #334155}" +
    "#rr-sharepoint-sync-toast .rr-sp-flow-row{display:flex;align-items:center;justify-content:space-between;gap:6px}" +
    "#rr-sharepoint-sync-toast .rr-sp-node{display:flex;flex-direction:column;align-items:center;gap:2px;min-width:64px;font-size:10px;color:#94a3b8}" +
    "#rr-sharepoint-sync-toast .rr-sp-node-icon{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;background:#1e293b;border:1px solid #475569}" +
    "#rr-sharepoint-sync-toast.is-running .rr-sp-node-icon{animation:rrSpNodePulse 1.6s ease-in-out infinite}" +
    "#rr-sharepoint-sync-toast .rr-sp-lane{position:relative;flex:1;height:28px;margin:0 4px;overflow:hidden;border-radius:999px;background:linear-gradient(90deg,#1e293b,#0f766e33,#1e293b);background-size:24px 100%}" +
    "#rr-sharepoint-sync-toast.is-running .rr-sp-lane{animation:rrSpLane 1s linear infinite}" +
    "#rr-sharepoint-sync-toast .rr-sp-packet{position:absolute;top:4px;left:0;width:18px;font-size:14px;line-height:1;opacity:0;pointer-events:none;will-change:left,opacity}" +
    "#rr-sharepoint-sync-toast.is-running .rr-sp-packet{animation:rrSpFlow 2.1s ease-in-out infinite}" +
    "#rr-sharepoint-sync-toast.is-running .rr-sp-packet:nth-child(2){animation-delay:.7s}" +
    "#rr-sharepoint-sync-toast.is-running .rr-sp-packet:nth-child(3){animation-delay:1.4s}" +
    "#rr-sharepoint-sync-toast .rr-sp-bar{position:relative;overflow:hidden}" +
    "#rr-sharepoint-sync-toast.is-running .rr-sp-bar::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.28),transparent);animation:rrSpShimmer 1.4s ease-in-out infinite}" +
    "#rr-sharepoint-sync-toast.is-done .rr-sp-node-rr .rr-sp-node-icon{border-color:#22c55e;background:#14532d}" +
    "#rr-sharepoint-sync-toast.is-error .rr-sp-lane{background:#7f1d1d}" +
    "@media (prefers-reduced-motion:reduce){#rr-sharepoint-sync-toast,#rr-sharepoint-sync-toast.is-running,#rr-sharepoint-sync-toast .rr-sp-packet,#rr-sharepoint-sync-toast .rr-sp-node-icon,#rr-sharepoint-sync-toast .rr-sp-lane,#rr-sharepoint-sync-toast.is-running .rr-sp-bar::after{animation:none!important}}";
  document.documentElement.appendChild(style);

  const toast = document.createElement("div");
  toast.id = "rr-sharepoint-sync-toast";
  toast.setAttribute(
    "style",
    "position:fixed;z-index:2147483646;right:16px;bottom:16px;width:380px;max-width:calc(100vw - 24px);" +
      "background:#0f172a;color:#e2e8f0;border:1px solid #334155;border-radius:12px;padding:14px 16px;" +
      "font:14px/1.4 system-ui,Segoe UI,sans-serif;box-shadow:0 12px 40px rgba(0,0,0,.35);"
  );
  toast.innerHTML =
    '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px">' +
      '<strong style="color:#5eead4">RiskRegister · SharePoint sync</strong>' +
      '<button id="rr-sp-sync-close" type="button" aria-label="Close progress" ' +
        'style="border:0;background:transparent;color:#94a3b8;font-size:20px;line-height:1;cursor:pointer;padding:0">×</button>' +
    "</div>" +
    '<div id="rr-sp-sync-status" role="status" aria-live="polite" style="margin-top:8px;color:#99f6e4">Starting…</div>' +
    '<div class="rr-sp-flow" aria-hidden="true">' +
      '<div class="rr-sp-flow-row">' +
        '<div class="rr-sp-node"><span class="rr-sp-node-icon">📂</span><span>SharePoint</span></div>' +
        '<div class="rr-sp-lane">' +
          '<span class="rr-sp-packet">📁</span>' +
          '<span class="rr-sp-packet">📄</span>' +
          '<span class="rr-sp-packet">🗂️</span>' +
        "</div>" +
        '<div class="rr-sp-node rr-sp-node-rr"><span class="rr-sp-node-icon">🛡️</span><span>RiskRegister</span></div>' +
      "</div>" +
    "</div>" +
    '<div class="rr-sp-bar" style="height:6px;background:#334155;border-radius:999px;overflow:hidden;margin-top:10px">' +
      '<div id="rr-sp-sync-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="2" ' +
        'style="height:100%;width:2%;background:#14b8a6;border-radius:999px;transition:width .25s ease,background-color .25s ease"></div>' +
    "</div>" +
    '<div id="rr-sp-sync-detail" style="margin-top:8px;font-size:12px;color:#94a3b8">Ready for confirmation.</div>' +
    '<button id="rr-sp-sync-start" type="button" style="margin-top:12px;background:#0d9488;color:#fff;border:0;' +
      'border-radius:8px;padding:8px 12px;font-weight:600;cursor:pointer">Start sync</button>';
  document.documentElement.appendChild(toast);

  const toastStatus = document.getElementById("rr-sp-sync-status");
  const toastProgress = document.getElementById("rr-sp-sync-progress");
  const toastDetail = document.getElementById("rr-sp-sync-detail");
  const toastStart = document.getElementById("rr-sp-sync-start");
  const toastClose = document.getElementById("rr-sp-sync-close");
  let syncStartedAt = 0;

  const waitForStart = () => new Promise((resolve) => {
    let settled = false;
    const finish = (shouldStart) => {
      if (settled) return;
      settled = true;
      resolve(shouldStart);
    };
    if (toastStart) {
      toastStart.addEventListener("click", () => {
        toastStart.disabled = true;
        toastStart.style.display = "none";
        finish(true);
      }, { once: true });
    } else {
      finish(false);
    }
    if (toastClose) {
      toastClose.addEventListener("click", () => {
        toast.remove();
        finish(false);
      }, { once: true });
    }
  });

  const formatElapsed = () => {
    const totalSeconds = Math.max(0, Math.round((Date.now() - (syncStartedAt || Date.now())) / 1000));
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    return minutes > 0 ? minutes + "m " + seconds + "s" : seconds + "s";
  };

  const setProgress = (message, detail, percent, state) => {
    if (toastStatus) {
      toastStatus.textContent = message;
      toastStatus.style.color = state === "error" ? "#fca5a5" : state === "done" ? "#86efac" : "#99f6e4";
    }
    if (toastDetail) toastDetail.textContent = detail || "";
    if (toastProgress) {
      const value = Math.max(0, Math.min(100, Number(percent) || 0));
      toastProgress.style.width = value + "%";
      toastProgress.style.backgroundColor = state === "error" ? "#ef4444" : state === "done" ? "#22c55e" : "#14b8a6";
      toastProgress.setAttribute("aria-valuenow", String(value));
    }
    toast.classList.toggle("is-running", state !== "done" && state !== "error" && Number(percent) > 2);
    toast.classList.toggle("is-done", state === "done");
    toast.classList.toggle("is-error", state === "error");
  };

  const rows = [];
  const seen = new Set();
  const visitedFolders = new Set();
  let foldersDone = 0;
  let filesFound = 0;
  let pagesFetched = 0;
  let deepest = 0;
  let visioFound = 0;
  const skipFolderNames = new Set(["Forms", "_w", "_t", "_vti_cnf", "_catalogs", "SiteAssets", "Style Library"]);
  const quote = (value) => "'" + String(value).replace(/'/g, "''") + "'";
  const cleanPath = (value) => String(value || "").split("\\\\").join("/").replace(/\\/+$/g, "");
  const pathKey = (value) => cleanPath(value).toLowerCase();
  const asList = (value) => {
    if (!value) return [];
    if (Array.isArray(value)) return value;
    if (Array.isArray(value.value)) return value.value;
    if (Array.isArray(value.results)) return value.results;
    return [];
  };
  const fileExt = (name) => {
    const base = String(name || "").split("/").pop() || "";
    const dot = base.lastIndexOf(".");
    return dot > 0 ? base.slice(dot + 1).toLowerCase() : "";
  };
  const isVisio = (name) => {
    const ext = fileExt(name);
    return ext === "vsdx" || ext === "vsd" || ext === "vssx" || ext === "vstx" || ext === "vsdm";
  };
  const relativeFromServer = (serverPath) => {
    const root = cleanPath(CFG.rootServerRelative);
    const path = cleanPath(serverPath);
    const lower = path.toLowerCase();
    const rootLower = root.toLowerCase();
    if (lower === rootLower) return "";
    if (lower.startsWith(rootLower + "/")) return path.slice(root.length + 1);
    return path.replace(/^\\/+/, "");
  };
  const folderBrowseUrl = (serverPath) =>
    location.origin + CFG.sitePath + "/Shared%20Documents/Forms/AllItems.aspx?id=" + encodeURIComponent(serverPath);
  const fileBrowseUrl = (serverPath) => {
    const parts = String(serverPath || "").split("/");
    return location.origin + parts.map((part, index) => (index === 0 ? part : encodeURIComponent(part))).join("/");
  };
  const lookupName = (value) => {
    if (!value) return "";
    if (typeof value === "string") return value.trim();
    if (Array.isArray(value) && value[0]) {
      const first = value[0] || {};
      return String(first.title || first.email || first.lookupValue || first.Title || "").trim();
    }
    return String(value.Title || value.Email || value.title || value.email || value.lookupValue || "").trim();
  };
  const toSizeBytes = (value) => {
    if (value == null || value === "") return 0;
    if (typeof value === "number" && Number.isFinite(value)) return Math.max(0, Math.round(value));
    const raw = String(value).trim().replace(/,/g, "");
    if (!raw) return 0;
    if (/^\\d+(\\.\\d+)?$/.test(raw)) return Math.max(0, Math.round(Number(raw)));
    const match = raw.match(/^([\\d.]+)\\s*([kmgt]i?b)?$/i);
    if (!match) return 0;
    const n = Number(match[1]);
    if (!Number.isFinite(n)) return 0;
    const unit = (match[2] || "").toLowerCase();
    const mul =
      unit === "kb" || unit === "kib" ? 1024 :
      unit === "mb" || unit === "mib" ? 1024 * 1024 :
      unit === "gb" || unit === "gib" ? 1024 * 1024 * 1024 :
      unit === "tb" || unit === "tib" ? 1024 * 1024 * 1024 * 1024 :
      1;
    return Math.max(0, Math.round(n * mul));
  };
  const itemCreated = (item) =>
    item.Created || item["Created."] || item.TimeCreated || item.Created_x0020_Date || "";
  const itemSize = (item, isFolder) => {
    const stream = toSizeBytes(item.SMTotalFileStreamSize);
    if (stream > 0) return stream;
    const fileSize = toSizeBytes(item.File_x0020_Size || item["File_x0020_Size"] || item.Length);
    if (fileSize > 0) return fileSize;
    if (isFolder) return toSizeBytes(item.SMTotalSize);
    return toSizeBytes(item.FileSizeDisplay);
  };
  const pushRow = (row) => {
    const key = ((row.path || "") + "|" + (row.type || "")).toLowerCase();
    if (seen.has(key) || rows.length >= CFG.maxRows) return false;
    seen.add(key);
    rows.push(row);
    if (row.type === "File" && isVisio(row.name)) {
      visioFound += 1;
      console.log("%cVisio found: " + row.path, "color:#7c3aed");
    }
    return true;
  };
  const apiGet = async (url) => {
    const response = await fetch(url, {
      credentials: "include",
      headers: { Accept: "application/json;odata=nometadata" },
    });
    if (!response.ok) {
      throw new Error("SharePoint API " + response.status + " — are you signed in on this tab?");
    }
    pagesFetched += 1;
    return response.json();
  };
  // SharePoint remains strictly read-only. RenderListDataAsStream requires
  // POST syntactically, but it only queries list data and cannot alter content.
  const apiReadPost = async (url, body, digest) => {
    const endpoint = new URL(url, location.origin);
    if (!/\\/RenderListDataAsStream$/i.test(endpoint.pathname)) {
      throw new Error("Blocked non-read SharePoint POST endpoint.");
    }
    const response = await fetch(url, {
      method: "POST",
      credentials: "include",
      headers: {
        Accept: "application/json;odata=nometadata",
        "Content-Type": "application/json;odata=nometadata",
        "X-RequestDigest": digest,
      },
      body: JSON.stringify(body),
    });
    if (!response.ok) {
      const text = await response.text().catch(function () { return ""; });
      throw new Error("SharePoint POST " + response.status + " " + text.slice(0, 180));
    }
    pagesFetched += 1;
    return response.json();
  };
  const getDigest = async () => {
    const response = await fetch(location.origin + CFG.sitePath + "/_api/contextinfo", {
      method: "POST",
      credentials: "include",
      headers: { Accept: "application/json;odata=nometadata" },
    });
    if (!response.ok) throw new Error("Could not get request digest (" + response.status + ")");
    pagesFetched += 1;
    const data = await response.json();
    return (
      (data && data.FormDigestValue) ||
      (data && data.d && data.d.GetContextWebInformation && data.d.GetContextWebInformation.FormDigestValue) ||
      ""
    );
  };
  const libraryRootFromFolder = (serverRelative) => {
    const path = cleanPath(serverRelative);
    const marker = "/Shared Documents";
    const idx = path.toLowerCase().indexOf(marker.toLowerCase());
    if (idx >= 0) return path.slice(0, idx + marker.length);
    const parts = path.split("/").filter(Boolean);
    if (parts.length >= 3) return "/" + parts.slice(0, 3).join("/");
    return path;
  };

  // --- Primary: RecursiveAll list crawl (same universe as AllItems.aspx, includes .vsdx) ---
  const crawlViaList = async () => {
    const root = cleanPath(CFG.rootServerRelative);
    const listPath = libraryRootFromFolder(root);
    const digest = await getDigest();
    if (!digest) throw new Error("Missing form digest for list crawl.");

    let listDataNext = "";
    let page = 0;
    const rootPrefix = root.toLowerCase() + "/";
    const rootExact = root.toLowerCase();

    do {
      page += 1;
      let viewXml =
        "<View Scope=\\"RecursiveAll\\"><Query><Where><Or>" +
        "<Eq><FieldRef Name=\\"FileRef\\"/><Value Type=\\"Text\\">" +
        root.replace(/&/g, "&amp;").replace(/</g, "&lt;") +
        "</Value></Eq>" +
        "<BeginsWith><FieldRef Name=\\"FileRef\\"/><Value Type=\\"Text\\">" +
        (root + "/").replace(/&/g, "&amp;").replace(/</g, "&lt;") +
        "</Value></BeginsWith>" +
        "</Or></Where></Query>" +
        "<ViewFields>" +
        "<FieldRef Name=\\"FileLeafRef\\"/>" +
        "<FieldRef Name=\\"FileRef\\"/>" +
        "<FieldRef Name=\\"FSObjType\\"/>" +
        "<FieldRef Name=\\"File_x0020_Type\\"/>" +
        "<FieldRef Name=\\"Modified\\"/>" +
        "<FieldRef Name=\\"Created\\"/>" +
        "<FieldRef Name=\\"File_x0020_Size\\"/>" +
        "<FieldRef Name=\\"FileSizeDisplay\\"/>" +
        "<FieldRef Name=\\"SMTotalFileStreamSize\\"/>" +
        "<FieldRef Name=\\"SMTotalSize\\"/>" +
        "<FieldRef Name=\\"Editor\\"/>" +
        "<FieldRef Name=\\"Author\\"/>" +
        "</ViewFields>" +
        "<RowLimit Paged=\\"TRUE\\">" +
        CFG.pageSize +
        "</RowLimit></View>";

      const url =
        location.origin +
        CFG.sitePath +
        "/_api/web/GetListUsingPath(DecodedUrl=@u)/RenderListDataAsStream?@u=" +
        encodeURIComponent(quote(listPath)) +
        (listDataNext ? "&" + listDataNext.replace(/^\\?/, "") : "");

      const payload = await apiReadPost(
        url,
        { parameters: { ViewXml: viewXml, RenderOptions: 4103 } },
        digest
      );

      const rowList =
        (payload && payload.Row) ||
        (payload && payload.ListData && payload.ListData.Row) ||
        [];
      const rowsPage = Array.isArray(rowList) ? rowList : [];

      for (let i = 0; i < rowsPage.length; i++) {
        const item = rowsPage[i] || {};
        const fileRef = cleanPath(item.FileRef || item.FileRefEncoded || "");
        const name = String(item.FileLeafRef || item.FileName || "").trim();
        if (!fileRef || !name) continue;
        const lower = fileRef.toLowerCase();
        if (lower !== rootExact && !lower.startsWith(rootPrefix)) continue;

        const fsObj = String(item.FSObjType != null ? item.FSObjType : item["FSObjType.Value"] != null ? item["FSObjType.Value"] : "");
        const isFolder = fsObj === "1" || fsObj === "Folder";
        const rel = relativeFromServer(fileRef);
        if (!rel && isFolder) continue;

        const editor =
          lookupName(item.Editor) ||
          item.EditorTitle ||
          item["Editor.title"] ||
          "";
        const author =
          lookupName(item.Author) ||
          item.AuthorTitle ||
          item["Author.title"] ||
          "";

        if (isFolder) {
          foldersDone += 1;
          pushRow({
            name: name,
            path: rel,
            type: "Folder",
            url: folderBrowseUrl(fileRef),
            modified: item.Modified || item["Modified."] || "",
            created: itemCreated(item),
            size: itemSize(item, true),
            modified_by: editor,
            person: author,
          });
        } else {
          if (
            pushRow({
              name: name,
              path: rel || name,
              type: "File",
              url: fileBrowseUrl(fileRef),
              modified: item.Modified || item["Modified."] || "",
              created: itemCreated(item),
              size: itemSize(item, false),
              modified_by: editor,
              person: author,
            })
          ) {
            filesFound += 1;
          }
        }
        if (rows.length >= CFG.maxRows) break;
      }

      console.log(
        "…list crawl page " + page + ":",
        rowsPage.length,
        "rows | total",
        rows.length,
        "| files",
        filesFound,
        "| visio",
        visioFound
      );
      setProgress(
        "Scanning SharePoint library…",
        "Page " + page + " · " + rows.length + " items · " + filesFound + " files · " + foldersDone + " folders",
        Math.min(70, 12 + page * 6)
      );

      const next =
        (payload && payload.NextHref) ||
        (payload && payload.ListData && payload.ListData.NextHref) ||
        "";
      listDataNext = next ? String(next).replace(/^\\?/, "") : "";
    } while (listDataNext && rows.length < CFG.maxRows);

    return rows.length > 0;
  };

  // --- Fallback: paginated Folders/Files walk ---
  const folderApiBase = (serverRelative, collection) => {
    const encoded = encodeURIComponent(quote(serverRelative));
    return {
      modern:
        location.origin +
        CFG.sitePath +
        "/_api/web/GetFolderByServerRelativePath(DecodedUrl=@p)/" +
        collection +
        "?@p=" +
        encoded +
        "&$top=" +
        CFG.pageSize,
      legacy:
        location.origin +
        CFG.sitePath +
        "/_api/web/GetFolderByServerRelativeUrl(" +
        encoded +
        ")/" +
        collection +
        "?$top=" +
        CFG.pageSize,
    };
  };
  const collectPaged = async (serverRelative, collection) => {
    const urls = folderApiBase(serverRelative, collection);
    let url =
      urls.modern +
      (collection === "Files"
        ? "&$select=Name,ServerRelativeUrl,TimeLastModified,TimeCreated,Length,LinkingUrl&$expand=Author,ModifiedBy"
        : "&$select=Name,ServerRelativeUrl,TimeLastModified,TimeCreated,ItemCount");
    const items = [];
    let usedLegacy = false;
    while (url) {
      let data;
      try {
        data = await apiGet(url);
      } catch (error) {
        if (!usedLegacy && url.indexOf("GetFolderByServerRelativePath") !== -1) {
          usedLegacy = true;
          url =
            urls.legacy +
            (collection === "Files"
              ? "&$select=Name,ServerRelativeUrl,TimeLastModified,TimeCreated,Length,LinkingUrl&$expand=Author,ModifiedBy"
              : "&$select=Name,ServerRelativeUrl,TimeLastModified,TimeCreated,ItemCount");
          data = await apiGet(url);
        } else {
          throw error;
        }
      }
      items.push.apply(items, asList(data));
      url = data["odata.nextLink"] || data["@odata.nextLink"] || "";
    }
    return items;
  };
  const crawlViaFolders = async () => {
    const queue = [{ path: cleanPath(CFG.rootServerRelative), depth: 0, modified: "", created: "" }];
    while (queue.length) {
      if (rows.length >= CFG.maxRows) break;
      const current = queue.shift();
      const serverRelative = cleanPath(current.path);
      const depth = current.depth || 0;
      const key = pathKey(serverRelative);
      if (!serverRelative || visitedFolders.has(key) || depth > CFG.maxDepth) continue;
      visitedFolders.add(key);
      deepest = Math.max(deepest, depth);
      foldersDone += 1;

      const rel = relativeFromServer(serverRelative);
      const name = serverRelative.split("/").filter(Boolean).pop() || "";
      if (rel) {
        pushRow({
          name: name,
          path: rel,
          type: "Folder",
          url: folderBrowseUrl(serverRelative),
          modified: current.modified || "",
          created: current.created || "",
          size: 0,
          modified_by: "",
          person: "",
        });
      }

      let childFolders = [];
      let childFiles = [];
      try {
        childFolders = await collectPaged(serverRelative, "Folders");
      } catch (error) {
        console.warn("Folders list failed:", serverRelative, error && error.message);
      }
      try {
        childFiles = await collectPaged(serverRelative, "Files");
      } catch (error) {
        console.warn("Files list failed:", serverRelative, error && error.message);
      }

      for (let i = 0; i < childFiles.length; i++) {
        const file = childFiles[i] || {};
        const fileName = file.Name || "";
        if (!fileName) continue;
        const childPath = cleanPath(file.ServerRelativeUrl || serverRelative + "/" + fileName);
        const modifiedBy = lookupName(file.ModifiedBy);
        const author = lookupName(file.Author);
        if (
          pushRow({
            name: fileName,
            path: relativeFromServer(childPath),
            type: "File",
            url: file.LinkingUrl || fileBrowseUrl(childPath),
            modified: file.TimeLastModified || "",
            created: file.TimeCreated || "",
            size: toSizeBytes(file.Length),
            modified_by: modifiedBy || author,
            person: author,
          })
        ) {
          filesFound += 1;
        }
      }

      for (let i = 0; i < childFolders.length; i++) {
        const folder = childFolders[i] || {};
        const folderName = folder.Name || "";
        if (!folderName || skipFolderNames.has(folderName)) continue;
        if (folderName.charAt(0) === "_" && folderName !== "_private") continue;
        const childPath = cleanPath(folder.ServerRelativeUrl || serverRelative + "/" + folderName);
        if (!visitedFolders.has(pathKey(childPath))) {
          queue.push({
            path: childPath,
            depth: depth + 1,
            modified: folder.TimeLastModified || "",
            created: folder.TimeCreated || "",
          });
        }
      }

      if (foldersDone === 1 || foldersDone % 10 === 0) {
        console.log("…folder walk:", foldersDone, "folders |", filesFound, "files | visio", visioFound);
        setProgress(
          "Walking SharePoint folders…",
          foldersDone + " folders · " + filesFound + " files · " + rows.length + " total items",
          Math.min(75, 15 + foldersDone)
        );
      }
    }
  };

  setProgress(
    "Ready to start read-only sync",
    "No SharePoint content will be changed. Click Start sync to continue.",
    2
  );
  const shouldStart = await waitForStart();
  if (!shouldStart) {
    window.__rrSharePointSyncRunning = false;
    console.log("RiskRegister SharePoint sync cancelled before start.");
    return;
  }
  syncStartedAt = Date.now();
  toast.classList.add("is-running");

  console.log("%cRiskRegister MFA deep sync starting…", "color:#0f766e;font-weight:bold;font-size:14px");
  console.log("Includes Visio (.vsdx/.vsd/…). Root:", CFG.rootServerRelative);
  setProgress("Connecting to SharePoint…", "Root: " + CFG.rootServerRelative, 5);
  try {
    let usedList = false;
    try {
      setProgress("Scanning SharePoint library…", "Starting recursive list crawl", 10);
      usedList = await crawlViaList();
      console.log(usedList ? "List RecursiveAll crawl finished." : "List crawl returned 0 rows — falling back to folder walk.");
    } catch (listError) {
      console.warn("List crawl failed, falling back to folder walk:", listError && listError.message);
      setProgress("Switching to folder-by-folder scan…", "The recursive list query was unavailable", 15);
    }
    if (!usedList || filesFound === 0) {
      setProgress("Walking SharePoint folders…", "Starting folder scan", 18);
      await crawlViaFolders();
    }
    console.log(
      "%cCollected " +
        rows.length +
        " rows (" +
        filesFound +
        " files, " +
        foldersDone +
        " folders, " +
        visioFound +
        " Visio). Posting…",
      "color:#0f766e;font-weight:bold"
    );
    setProgress(
      "Uploading catalog to RiskRegister…",
      rows.length + " items · " + filesFound + " files · " + foldersDone + " folders · " + visioFound + " Visio",
      88
    );
    const response = await fetch(CFG.importUrl, {
      method: "POST",
      mode: "cors",
      headers: {
        "Content-Type": "application/json",
        "X-Sync-Token": CFG.token,
        "X-Source-Key": CFG.sourceKey || "",
      },
      body: JSON.stringify({ token: CFG.token, source_key: CFG.sourceKey || "", rows: rows }),
    });
    const json = await response.json().catch(function () { return {}; });
    if (!response.ok || !json.ok) {
      throw new Error(json.error || ("Import failed HTTP " + response.status));
    }
    const newItems = Math.max(0, Number(json.new_items) || 0);
    const removedItems = Math.max(0, Number(json.removed_items) || 0);
    const changeSummary = newItems > 0
      ? newItems + " new SharePoint item" + (newItems === 1 ? "" : "s") + " found"
      : "No new SharePoint items found";
    const removalSummary = removedItems > 0
      ? " · " + removedItems + " local catalog item" + (removedItems === 1 ? "" : "s") + " no longer present"
      : "";
    setProgress(
      "✅ Sync complete · " + changeSummary,
      changeSummary + removalSummary + " · " +
        (json.message || (rows.length + " items imported")) +
        " into RiskRegister · " + visioFound + " Visio files · Completed in " + formatElapsed(),
      100,
      "done"
    );
    console.log("%c✅ Sync complete: " + json.message + " | Visio files: " + visioFound, "color:#047857;font-weight:bold;font-size:14px");
    window.postMessage({
      source: "riskregister-sharepoint-sync",
      type: "RR_SP_SYNC_COMPLETE",
      sourceKey: CFG.sourceKey
    }, window.location.origin);
  } catch (error) {
    console.error("%c❌ Sync failed", "color:#b91c1c;font-weight:bold", error);
    setProgress(
      "❌ Sync failed",
      (error && error.message ? error.message : String(error)) + " · Stopped after " + formatElapsed(),
      100,
      "error"
    );
  } finally {
    window.__rrSharePointSyncRunning = false;
  }
})();`;
  }

  window.SharePointMfaSync = { buildConsoleScript };
})();
