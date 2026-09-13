/**
 * Builds the ServiceNow console crawler for Ticket Dossier task-packet export.
 * Paste into a signed-in ServiceNow tab; overlay click required for folder picker.
 */
(() => {
  function buildConsoleScript(cfg) {
    const configJson = JSON.stringify({
      token: cfg.token,
      importUrl: cfg.import_url,
      attachmentUrl: cfg.attachment_url,
      completeUrl: cfg.complete_url,
      instanceOrigin: cfg.instance_origin,
      taskNumber: cfg.task_number,
      maxRelated: cfg.max_related || 50,
      maxAttachments: cfg.max_attachments || 100,
      maxAttachmentBytes: cfg.max_attachment_bytes || 25 * 1024 * 1024,
      format: 'architecture-risk.servicenow-task-packet.v1',
    });

    return `void (async function () {
  const CFG = ${configJson};
  if (window.__rrSnPacketRunning) {
    console.warn("RiskRegister ServiceNow export already open.");
    return;
  }
  window.__rrSnPacketRunning = true;

  const dv = (v) => {
    if (v == null) return "";
    if (typeof v === "object") {
      if (v.display_value != null && v.display_value !== "") return String(v.display_value);
      if (v.value != null) return String(v.value);
      try { return JSON.stringify(v); } catch (e) { return ""; }
    }
    return String(v);
  };
  const raw = (v) => {
    if (v == null) return "";
    if (typeof v === "object" && v.value != null) return String(v.value);
    return dv(v);
  };
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const endpointWithToken = (url) => {
    const u = new URL(url, location.origin);
    if (!u.searchParams.get("token")) {
      u.searchParams.set("token", CFG.token);
    }
    return u.toString();
  };
  const userToken = () =>
    (window.g_ck || (window.NOW && window.NOW.user && window.NOW.user.token) ||
      (document.querySelector('meta[name="X-UserToken"]') || {}).content || "").trim();

  const apiGet = async (path) => {
    const url = path.indexOf("http") === 0 ? path : (location.origin + path);
    const headers = { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" };
    const tok = userToken();
    if (tok) headers["X-UserToken"] = tok;
    const response = await fetch(url, { credentials: "include", headers });
    if (!response.ok) {
      const text = await response.text().catch(() => "");
      throw new Error("ServiceNow API " + response.status + " for " + path + (text ? ": " + text.slice(0, 200) : "") + " — are you signed in?");
    }
    return response.json();
  };

  const apiGetBlob = async (path) => {
    const url = path.indexOf("http") === 0 ? path : (location.origin + path);
    const headers = { "X-Requested-With": "XMLHttpRequest" };
    const tok = userToken();
    if (tok) headers["X-UserToken"] = tok;
    const response = await fetch(url, { credentials: "include", headers });
    if (!response.ok) {
      throw new Error("Attachment download failed HTTP " + response.status);
    }
    return response.blob();
  };

  const setStatus = (el, msg, isError) => {
    if (!el) return;
    el.textContent = msg;
    el.style.color = isError ? "#b91c1c" : "#0f766e";
  };

  const sanitizeFileName = (name) => {
    const base = String(name || "attachment.bin").split(/[/\\\\]/).pop() || "attachment.bin";
    return base.replace(/[^\\w.\\- ()\\[\\]]+/g, "_").replace(/^[._]+|[._]+$/g, "") || "attachment.bin";
  };

  const humanize = (col) =>
    String(col || "")
      .replace(/_/g, " ")
      .replace(/\\b\\w/g, (c) => c.toUpperCase());

  const fieldsFromRecord = (rec) => {
    const fields = {};
    const skip = new Set(["sys_id", "sys_mod_count", "sys_tags"]);
    Object.keys(rec || {}).forEach((key) => {
      if (skip.has(key)) return;
      const label = humanize(key);
      const value = dv(rec[key]);
      if (value !== "") fields[label] = value;
    });
    return fields;
  };

  const fetchTaskByNumber = async (number) => {
    const q = encodeURIComponent("number=" + number);
    const data = await apiGet(
      "/api/now/table/task?sysparm_query=" + q +
        "&sysparm_limit=1&sysparm_display_value=all&sysparm_exclude_reference_link=true"
    );
    const rows = (data && data.result) || [];
    if (!rows.length) throw new Error("Task " + number + " not found.");
    return rows[0];
  };

  const fetchTaskBySysId = async (sysId) => {
    const data = await apiGet(
      "/api/now/table/task/" + encodeURIComponent(sysId) +
        "?sysparm_display_value=all&sysparm_exclude_reference_link=true"
    );
    return data && data.result ? data.result : null;
  };

  const fetchRelationships = async (sysId) => {
    const q = encodeURIComponent("parent=" + sysId + "^ORchild=" + sysId);
    const data = await apiGet(
      "/api/now/table/task_rel_task?sysparm_query=" + q +
        "&sysparm_limit=" + CFG.maxRelated +
        "&sysparm_display_value=all&sysparm_exclude_reference_link=true"
    );
    return (data && data.result) || [];
  };

  const fetchJournal = async (sysId) => {
    try {
      const q = encodeURIComponent("element_id=" + sysId);
      const data = await apiGet(
        "/api/now/table/sys_journal_field?sysparm_query=" + q +
          "&sysparm_limit=200&sysparm_display_value=all&sysparm_exclude_reference_link=true"
      );
      return ((data && data.result) || []).map((j) => ({
        element: dv(j.element) || raw(j.element),
        value: dv(j.value),
        created: dv(j.sys_created_on),
        created_by: dv(j.sys_created_by),
      }));
    } catch (e) {
      console.warn("Journal fetch skipped:", e && e.message);
      return [];
    }
  };

  const fetchAttachmentMeta = async (tableName, sysId) => {
    const q = encodeURIComponent("table_name=" + tableName + "^table_sys_id=" + sysId);
    const data = await apiGet(
      "/api/now/attachment?sysparm_query=" + q +
        "&sysparm_limit=100&sysparm_display_value=all"
    );
    return (data && data.result) || [];
  };

  const mapTicket = async (rec, numberHint) => {
    const sysId = raw(rec.sys_id) || dv(rec.sys_id);
    const number = (dv(rec.number) || numberHint || "").toUpperCase();
    const tableName = raw(rec.sys_class_name) || dv(rec.sys_class_name) || "task";
    const journal = await fetchJournal(sysId);
    let attMeta = [];
    try {
      attMeta = await fetchAttachmentMeta(tableName, sysId);
      if (!attMeta.length && tableName !== "task") {
        attMeta = await fetchAttachmentMeta("task", sysId);
      }
    } catch (e) {
      console.warn("Attachment list failed for " + number + ":", e && e.message);
    }
    return {
      number: number,
      sys_id: sysId,
      sys_class_name: tableName,
      table: tableName,
      state: dv(rec.state),
      short_description: dv(rec.short_description),
      description: dv(rec.description),
      fields: fieldsFromRecord(rec),
      journal: journal,
      attachments: attMeta.map((a) => ({
        sys_id: raw(a.sys_id) || dv(a.sys_id),
        file_name: dv(a.file_name) || raw(a.file_name) || "attachment.bin",
        content_type: dv(a.content_type) || raw(a.content_type) || "",
        size_bytes: parseInt(raw(a.size_bytes) || dv(a.size_bytes) || "0", 10) || 0,
        relative_path: "",
      })),
      _rec: rec,
    };
  };

  const downloadAttachments = async (tickets, onProgress) => {
    const files = [];
    let total = 0;
    tickets.forEach((t) => { total += (t.attachments || []).length; });
    let done = 0;
    let skipped = 0;
    for (let ti = 0; ti < tickets.length; ti++) {
      const ticket = tickets[ti];
      const kept = [];
      for (let ai = 0; ai < (ticket.attachments || []).length; ai++) {
        if (files.length >= CFG.maxAttachments) {
          skipped += 1;
          continue;
        }
        const att = ticket.attachments[ai];
        const size = att.size_bytes || 0;
        if (size > CFG.maxAttachmentBytes) {
          console.warn("Skip oversized attachment", att.file_name, size);
          skipped += 1;
          done += 1;
          if (onProgress) onProgress(done, total, "skipped " + att.file_name);
          continue;
        }
        try {
          const blob = await apiGetBlob("/api/now/attachment/" + encodeURIComponent(att.sys_id) + "/file");
          const safeName = sanitizeFileName(att.file_name);
          const relativePath = "attachments/" + ticket.number + "/" + safeName;
          att.relative_path = relativePath;
          files.push({
            ticket_number: ticket.number,
            relative_path: relativePath,
            file_name: safeName,
            blob: blob,
            size_bytes: blob.size || size,
          });
          kept.push(att);
        } catch (e) {
          console.warn("Failed to download", att.file_name, e && e.message);
          skipped += 1;
        }
        done += 1;
        if (onProgress) onProgress(done, total, att.file_name);
        await sleep(40);
      }
      ticket.attachments = kept;
    }
    return { files: files, skipped: skipped };
  };

  const writeLocalFolder = async (packet, files, rootNumber) => {
    if (!window.showDirectoryPicker) {
      // Fallback: trigger downloads
      const jsonBlob = new Blob([JSON.stringify(packet, null, 2)], { type: "application/json" });
      const a = document.createElement("a");
      a.href = URL.createObjectURL(jsonBlob);
      a.download = rootNumber + ".json";
      a.click();
      URL.revokeObjectURL(a.href);
      for (let i = 0; i < files.length; i++) {
        const f = files[i];
        const link = document.createElement("a");
        link.href = URL.createObjectURL(f.blob);
        link.download = f.ticket_number + "_" + f.file_name;
        link.click();
        URL.revokeObjectURL(link.href);
        await sleep(120);
      }
      return { mode: "download" };
    }

    const rootHandle = await window.showDirectoryPicker({ mode: "readwrite" });
    const packetDir = await rootHandle.getDirectoryHandle(rootNumber, { create: true });
    const jsonHandle = await packetDir.getFileHandle(rootNumber + ".json", { create: true });
    const jsonWritable = await jsonHandle.createWritable();
    await jsonWritable.write(JSON.stringify(packet, null, 2));
    await jsonWritable.close();

    const attRoot = await packetDir.getDirectoryHandle("attachments", { create: true });
    for (let i = 0; i < files.length; i++) {
      const f = files[i];
      const ticketDir = await attRoot.getDirectoryHandle(f.ticket_number, { create: true });
      const fileHandle = await ticketDir.getFileHandle(f.file_name, { create: true });
      const writable = await fileHandle.createWritable();
      await writable.write(f.blob);
      await writable.close();
    }
    return { mode: "folder" };
  };

  const postPacket = async (packet) => {
    const response = await fetch(endpointWithToken(CFG.importUrl), {
      method: "POST",
      mode: "cors",
      headers: {
        "Content-Type": "application/json",
        "X-Sync-Token": CFG.token,
      },
      body: JSON.stringify({ token: CFG.token, packet: packet }),
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok || !json.ok) {
      throw new Error(json.error || ("Import failed HTTP " + response.status));
    }
    return json;
  };

  const postAttachment = async (projectId, file) => {
    const form = new FormData();
    form.append("token", CFG.token);
    form.append("project_id", String(projectId));
    form.append("ticket_number", file.ticket_number);
    form.append("relative_path", file.relative_path);
    form.append("file", file.blob, file.file_name);
    const response = await fetch(endpointWithToken(CFG.attachmentUrl), {
      method: "POST",
      mode: "cors",
      headers: {
        "X-Sync-Token": CFG.token,
        "X-Project-Id": String(projectId),
      },
      body: form,
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok || !json.ok) {
      throw new Error(json.error || ("Attachment upload failed for " + file.file_name));
    }
    return json;
  };

  const postComplete = async (projectId) => {
    const response = await fetch(endpointWithToken(CFG.completeUrl), {
      method: "POST",
      mode: "cors",
      headers: {
        "Content-Type": "application/json",
        "X-Sync-Token": CFG.token,
        "X-Project-Id": String(projectId),
      },
      body: JSON.stringify({ token: CFG.token, project_id: projectId }),
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok || !json.ok) {
      throw new Error(json.error || ("Complete failed HTTP " + response.status));
    }
    return json;
  };

  const runExport = async (statusEl, btn) => {
    btn.disabled = true;
    try {
      if (location.origin.replace(/\\/$/, "").toLowerCase() !== String(CFG.instanceOrigin).replace(/\\/$/, "").toLowerCase()) {
        console.warn("Current origin", location.origin, "vs prepared", CFG.instanceOrigin);
      }
      setStatus(statusEl, "Looking up " + CFG.taskNumber + "…");
      const rootRec = await fetchTaskByNumber(CFG.taskNumber);
      const rootSysId = raw(rootRec.sys_id) || dv(rootRec.sys_id);
      const rootNumber = (dv(rootRec.number) || CFG.taskNumber).toUpperCase();

      setStatus(statusEl, "Loading Task Relationships…");
      const relRows = await fetchRelationships(rootSysId);
      const relationships = [];
      const relatedIds = new Map();
      relRows.forEach((row) => {
        const parentId = raw(row.parent) || raw((row.parent || {}).value);
        const childId = raw(row.child) || raw((row.child || {}).value);
        const parentNum = dv(row.parent) || "";
        const childNum = dv(row.child) || "";
        let type = dv(row.type) || dv(row.relationship_type) || "";
        if (!type && row.parent_descriptor && row.child_descriptor) {
          type = dv(row.parent_descriptor) + "::" + dv(row.child_descriptor);
        }
        relationships.push({
          parent: String(parentNum).toUpperCase() || parentId,
          child: String(childNum).toUpperCase() || childId,
          type: type || "Related",
          parent_sys_id: parentId,
          child_sys_id: childId,
        });
        [parentId, childId].forEach((id) => {
          if (id && id !== rootSysId) relatedIds.set(id, true);
        });
      });

      if (relatedIds.size > CFG.maxRelated) {
        console.warn("Truncating related tickets to", CFG.maxRelated);
      }
      const relatedList = Array.from(relatedIds.keys()).slice(0, CFG.maxRelated);

      setStatus(statusEl, "Fetching related tickets (" + relatedList.length + ")…");
      const tickets = [];
      tickets.push(await mapTicket(rootRec, rootNumber));
      for (let i = 0; i < relatedList.length; i++) {
        const rec = await fetchTaskBySysId(relatedList[i]);
        if (rec) {
          tickets.push(await mapTicket(rec));
        }
        setStatus(statusEl, "Fetching related tickets… " + (i + 1) + "/" + relatedList.length);
        await sleep(40);
      }

      // Resolve relationship numbers from tickets when display was a sys_id.
      const byId = {};
      tickets.forEach((t) => { byId[t.sys_id] = t.number; });
      relationships.forEach((r) => {
        if (byId[r.parent_sys_id]) r.parent = byId[r.parent_sys_id];
        if (byId[r.child_sys_id]) r.child = byId[r.child_sys_id];
      });

      setStatus(statusEl, "Downloading attachments…");
      const { files, skipped } = await downloadAttachments(tickets, (done, total, name) => {
        setStatus(statusEl, "Attachments " + done + "/" + total + (name ? ": " + name : ""));
      });

      // Strip internal helpers before serialize.
      const cleanTickets = tickets.map((t) => {
        const copy = Object.assign({}, t);
        delete copy._rec;
        return copy;
      });

      const packet = {
        format: CFG.format,
        instance: CFG.instanceOrigin,
        exported_at: new Date().toISOString(),
        root_number: rootNumber,
        root_sys_id: rootSysId,
        relationships: relationships,
        tickets: cleanTickets,
      };

      setStatus(statusEl, "Choose a folder to save the packet (or allow downloads)…");
      const local = await writeLocalFolder(packet, files, rootNumber);

      setStatus(statusEl, "Importing packet into Ticket Dossier…");
      const imported = await postPacket(packet);
      const projectId = imported.project_id;
      if (!projectId) throw new Error("Import did not return a project_id.");

      for (let i = 0; i < files.length; i++) {
        setStatus(statusEl, "Uploading attachment " + (i + 1) + "/" + files.length + "…");
        await postAttachment(projectId, files[i]);
        await sleep(30);
      }

      setStatus(statusEl, "Finalizing…");
      const done = await postComplete(projectId);
      const msg =
        "Done. Tickets: " + cleanTickets.length +
        ", relationships: " + relationships.length +
        ", attachments: " + files.length +
        (skipped ? " (skipped " + skipped + ")" : "") +
        (local.mode === "folder" ? ". Saved to local folder." : ". Browser downloads used (no folder API).") +
        (done.project_url ? "\\nOpen: " + done.project_url : "");
      setStatus(statusEl, msg);
      console.log("%c✅ RiskRegister ServiceNow packet complete", "color:#047857;font-weight:bold;font-size:14px", done);
      alert("RiskRegister ServiceNow export complete.\\n" + msg);
      if (done.project_url) {
        try { window.open(done.project_url, "_blank"); } catch (e) {}
      }
    } catch (error) {
      console.error("%c❌ ServiceNow export failed", "color:#b91c1c;font-weight:bold", error);
      setStatus(statusEl, "Failed: " + (error && error.message ? error.message : error), true);
      alert("RiskRegister ServiceNow export failed: " + (error && error.message ? error.message : error));
    } finally {
      btn.disabled = false;
    }
  };

  // Inject overlay (user gesture for showDirectoryPicker).
  const existing = document.getElementById("rr-sn-packet-overlay");
  if (existing) existing.remove();
  const overlay = document.createElement("div");
  overlay.id = "rr-sn-packet-overlay";
  overlay.setAttribute("style",
    "position:fixed;z-index:2147483646;right:16px;bottom:16px;width:360px;max-width:calc(100vw - 24px);" +
    "background:#0f172a;color:#e2e8f0;border:1px solid #334155;border-radius:12px;padding:14px 16px;" +
    "font:14px/1.4 system-ui,Segoe UI,sans-serif;box-shadow:0 12px 40px rgba(0,0,0,.35);"
  );
  overlay.innerHTML =
    '<div style="font-weight:700;margin-bottom:6px;color:#5eead4">RiskRegister · ServiceNow packet</div>' +
    '<div style="opacity:.9;margin-bottom:10px;font-size:13px">Task <code style="color:#a5f3fc">' +
    String(CFG.taskNumber).replace(/</g, "") +
    "</code> · direct Task Relationships + attachments. Click Export, then choose a save folder.</div>" +
    '<button id="rr-sn-export-btn" type="button" style="background:#0d9488;color:#fff;border:0;border-radius:8px;' +
    'padding:8px 12px;font-weight:600;cursor:pointer;margin-right:8px">Export packet</button>' +
    '<button id="rr-sn-close-btn" type="button" style="background:transparent;color:#94a3b8;border:1px solid #475569;' +
    'border-radius:8px;padding:8px 12px;cursor:pointer">Close</button>' +
    '<div id="rr-sn-status" style="margin-top:10px;font-size:12px;color:#99f6e4;white-space:pre-wrap"></div>';
  document.documentElement.appendChild(overlay);
  const statusEl = document.getElementById("rr-sn-status");
  const btn = document.getElementById("rr-sn-export-btn");
  const closeBtn = document.getElementById("rr-sn-close-btn");
  closeBtn.addEventListener("click", () => {
    overlay.remove();
    window.__rrSnPacketRunning = false;
  });
  btn.addEventListener("click", () => runExport(statusEl, btn));
  setStatus(statusEl, "Ready. Sign-in cookies on this tab will be used.");
  console.log("%cRiskRegister ServiceNow overlay ready — click Export packet", "color:#0f766e;font-weight:bold;font-size:14px");
})();`;
  }

  window.ServiceNowConsoleSync = { buildConsoleScript };
})();
