/**
 * Builds the SharePoint console crawler script for MFA browser sync.
 * Deep-walks every subfolder with paginated Files/Folders APIs (not truncated $expand).
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

    // Pasted into the SharePoint page console while MFA-authenticated.
    // Wrapped in void(...) so DevTools does not print Promise {<pending>} as the main result.
    return `void (async function () {
  const CFG = ${configJson};
  const rows = [];
  const seen = new Set();
  const visitedFolders = new Set();
  let foldersDone = 0;
  let filesFound = 0;
  let pagesFetched = 0;
  let deepest = 0;
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
  const pushRow = (row) => {
    const key = ((row.path || "") + "|" + (row.type || "")).toLowerCase();
    if (seen.has(key) || rows.length >= CFG.maxRows) return false;
    seen.add(key);
    rows.push(row);
    return true;
  };
  const apiGet = async (url) => {
    const response = await fetch(url, {
      credentials: "include",
      headers: { Accept: "application/json;odata=nometadata" },
    });
    if (!response.ok) {
      throw new Error("SharePoint API " + response.status + " for " + url.slice(0, 160) + " — are you signed in on this tab?");
    }
    pagesFetched += 1;
    return response.json();
  };
  const folderApiBase = (serverRelative, collection) => {
    const encoded = encodeURIComponent(quote(serverRelative));
    const modern =
      location.origin +
      CFG.sitePath +
      "/_api/web/GetFolderByServerRelativePath(DecodedUrl=@p)/" +
      collection +
      "?@p=" +
      encoded +
      "&$top=" +
      CFG.pageSize;
    const legacy =
      location.origin +
      CFG.sitePath +
      "/_api/web/GetFolderByServerRelativeUrl(" +
      encoded +
      ")/" +
      collection +
      "?$top=" +
      CFG.pageSize;
    return { modern, legacy };
  };
  const folderMetaUrl = (serverRelative) => {
    const encoded = encodeURIComponent(quote(serverRelative));
    return {
      modern:
        location.origin +
        CFG.sitePath +
        "/_api/web/GetFolderByServerRelativePath(DecodedUrl=@p)?@p=" +
        encoded +
        "&$select=Name,ServerRelativeUrl,TimeLastModified,ItemCount",
      legacy:
        location.origin +
        CFG.sitePath +
        "/_api/web/GetFolderByServerRelativeUrl(" +
        encoded +
        ")?$select=Name,ServerRelativeUrl,TimeLastModified,ItemCount",
    };
  };
  const getWithFallback = async (urls) => {
    try {
      return await apiGet(urls.modern);
    } catch (error) {
      return apiGet(urls.legacy);
    }
  };
  const collectPaged = async (serverRelative, collection, selectExtra) => {
    const urls = folderApiBase(serverRelative, collection);
    let url =
      urls.modern +
      (selectExtra
        ? "&$select=" + selectExtra + (collection === "Files" ? "&$expand=Author,ModifiedBy" : "")
        : collection === "Files"
          ? "&$select=Name,ServerRelativeUrl,TimeLastModified,Length,LinkingUrl&$expand=Author,ModifiedBy"
          : "&$select=Name,ServerRelativeUrl,TimeLastModified,ItemCount");
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
              ? "&$select=Name,ServerRelativeUrl,TimeLastModified,Length,LinkingUrl&$expand=Author,ModifiedBy"
              : "&$select=Name,ServerRelativeUrl,TimeLastModified,ItemCount");
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
  const logProgress = (force) => {
    if (!force && foldersDone !== 1 && foldersDone % 5 !== 0) return;
    console.log(
      "…deep crawl:",
      foldersDone,
      "folders |",
      filesFound,
      "files |",
      rows.length,
      "rows | depth",
      deepest,
      "| API pages",
      pagesFetched
    );
  };

  const walk = async () => {
    const queue = [{ path: cleanPath(CFG.rootServerRelative), depth: 0 }];
    while (queue.length) {
      if (rows.length >= CFG.maxRows) {
        console.warn("Stopped at maxRows=" + CFG.maxRows);
        break;
      }
      const current = queue.shift();
      const serverRelative = cleanPath(current.path);
      const depth = current.depth || 0;
      const key = pathKey(serverRelative);
      if (!serverRelative || visitedFolders.has(key)) continue;
      if (depth > CFG.maxDepth) {
        console.warn("Skipped deeper than maxDepth=" + CFG.maxDepth + ":", serverRelative);
        continue;
      }
      visitedFolders.add(key);
      deepest = Math.max(deepest, depth);

      let meta = null;
      try {
        meta = await getWithFallback(folderMetaUrl(serverRelative));
      } catch (error) {
        console.warn("Folder meta failed, continuing with children:", serverRelative, error && error.message);
      }

      foldersDone += 1;
      const rel = relativeFromServer(serverRelative);
      const name =
        (meta && meta.Name) ||
        serverRelative.split("/").filter(Boolean).pop() ||
        "";
      if (rel) {
        pushRow({
          name: name,
          path: rel,
          type: "Folder",
          url: folderBrowseUrl(serverRelative),
          modified: (meta && meta.TimeLastModified) || "",
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
        const modifiedBy =
          (file.ModifiedBy && (file.ModifiedBy.Title || file.ModifiedBy.Email)) ||
          (file.Author && (file.Author.Title || file.Author.Email)) ||
          "";
        if (
          pushRow({
            name: fileName,
            path: relativeFromServer(childPath),
            type: "File",
            url: file.LinkingUrl || fileBrowseUrl(childPath),
            modified: file.TimeLastModified || "",
            modified_by: modifiedBy,
            person: "",
          })
        ) {
          filesFound += 1;
        }
        if (rows.length >= CFG.maxRows) break;
      }

      for (let i = 0; i < childFolders.length; i++) {
        const folder = childFolders[i] || {};
        const folderName = folder.Name || "";
        if (!folderName || skipFolderNames.has(folderName)) continue;
        if (folderName.charAt(0) === "_" && folderName !== "_private") continue;
        const childPath = cleanPath(folder.ServerRelativeUrl || serverRelative + "/" + folderName);
        if (visitedFolders.has(pathKey(childPath))) continue;
        queue.push({ path: childPath, depth: depth + 1 });
      }

      logProgress(false);
    }
  };

  console.log("%cRiskRegister MFA deep sync starting…", "color:#0f766e;font-weight:bold;font-size:14px");
  console.log(
    "Walks EVERY subfolder with paginated Files/Folders APIs (Visio/PDF/etc). Root:",
    CFG.rootServerRelative
  );
  console.log("This can take several minutes on large libraries. Ignore any brief pending Promise.");
  try {
    await walk();
    logProgress(true);
    console.log(
      "%cCollected " + rows.length + " rows (" + filesFound + " files, " + foldersDone + " folders, depth " + deepest + "). Posting to RiskRegister…",
      "color:#0f766e;font-weight:bold"
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
    console.log("%c✅ Sync complete: " + json.message, "color:#047857;font-weight:bold;font-size:14px");
    alert("RiskRegister sync complete:\\n" + json.message + "\\n\\nReturn to the catalog page and refresh.");
  } catch (error) {
    console.error("%c❌ Sync failed", "color:#b91c1c;font-weight:bold", error);
    alert("RiskRegister sync failed: " + (error && error.message ? error.message : error) + "\\n\\nTip: click Prepare MFA sync again (token expires in 30 min), then copy a fresh script.");
  }
})();`;
  }

  window.SharePointMfaSync = { buildConsoleScript };
})();
