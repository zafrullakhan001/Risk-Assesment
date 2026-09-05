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
  const apiPost = async (url, body, digest) => {
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
        "<FieldRef Name=\\"Editor\\"/>" +
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

      const payload = await apiPost(
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
          (item.Editor && (item.Editor[0] && (item.Editor[0].title || item.Editor[0].email))) ||
          item.EditorTitle ||
          item["Editor.title"] ||
          "";

        if (isFolder) {
          foldersDone += 1;
          pushRow({
            name: name,
            path: rel,
            type: "Folder",
            url: folderBrowseUrl(fileRef),
            modified: item.Modified || item["Modified."] || "",
            modified_by: editor,
            person: "",
          });
        } else {
          if (
            pushRow({
              name: name,
              path: rel || name,
              type: "File",
              url: fileBrowseUrl(fileRef),
              modified: item.Modified || item["Modified."] || "",
              modified_by: editor,
              person: "",
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
  const crawlViaFolders = async () => {
    const queue = [{ path: cleanPath(CFG.rootServerRelative), depth: 0 }];
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
          modified: "",
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
      }

      for (let i = 0; i < childFolders.length; i++) {
        const folder = childFolders[i] || {};
        const folderName = folder.Name || "";
        if (!folderName || skipFolderNames.has(folderName)) continue;
        if (folderName.charAt(0) === "_" && folderName !== "_private") continue;
        const childPath = cleanPath(folder.ServerRelativeUrl || serverRelative + "/" + folderName);
        if (!visitedFolders.has(pathKey(childPath))) {
          queue.push({ path: childPath, depth: depth + 1 });
        }
      }

      if (foldersDone === 1 || foldersDone % 10 === 0) {
        console.log("…folder walk:", foldersDone, "folders |", filesFound, "files | visio", visioFound);
      }
    }
  };

  console.log("%cRiskRegister MFA deep sync starting…", "color:#0f766e;font-weight:bold;font-size:14px");
  console.log("Includes Visio (.vsdx/.vsd/…). Root:", CFG.rootServerRelative);
  try {
    let usedList = false;
    try {
      usedList = await crawlViaList();
      console.log(usedList ? "List RecursiveAll crawl finished." : "List crawl returned 0 rows — falling back to folder walk.");
    } catch (listError) {
      console.warn("List crawl failed, falling back to folder walk:", listError && listError.message);
    }
    if (!usedList || filesFound === 0) {
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
    console.log("%c✅ Sync complete: " + json.message + " | Visio files: " + visioFound, "color:#047857;font-weight:bold;font-size:14px");
    alert("RiskRegister sync complete:\\n" + json.message + "\\nVisio (.vsdx) files found: " + visioFound + "\\n\\nReturn to the catalog page and refresh.");
  } catch (error) {
    console.error("%c❌ Sync failed", "color:#b91c1c;font-weight:bold", error);
    alert("RiskRegister sync failed: " + (error && error.message ? error.message : error));
  }
})();`;
  }

  window.SharePointMfaSync = { buildConsoleScript };
})();
