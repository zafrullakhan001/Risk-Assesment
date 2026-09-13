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
      rememberFolder: cfg.remember_folder === true,
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

  const fetchBinary = async (path, accept) => {
    const url = path.indexOf("http") === 0 ? path : (location.origin + path);
    const headers = { "X-Requested-With": "XMLHttpRequest" };
    if (accept) headers.Accept = accept;
    const tok = userToken();
    if (tok) headers["X-UserToken"] = tok;
    const response = await fetch(url, { credentials: "include", headers });
    if (!response.ok) {
      throw new Error("Binary fetch failed HTTP " + response.status + " for " + path);
    }
    return {
      blob: await response.blob(),
      contentType: String(response.headers.get("content-type") || "").toLowerCase(),
      finalUrl: response.url || url,
    };
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

  const normalizeAttachmentFileName = (name) => {
    const safe = sanitizeFileName(name);
    if (/DDR\\d+/i.test(safe) && /\\.json\\.txt$/i.test(safe)) {
      return safe.replace(/\\.txt$/i, "");
    }
    return safe;
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
    let rows = (data && data.result) || [];
    if (!rows.length) {
      // Related-list records do not always expose sys_class_name. Looking up by
      // record sys_id still safely scopes attachments to this known record.
      const byRecord = encodeURIComponent("table_sys_id=" + sysId);
      const fallback = await apiGet(
        "/api/now/attachment?sysparm_query=" + byRecord +
          "&sysparm_limit=100&sysparm_display_value=all"
      );
      rows = (fallback && fallback.result) || [];
    }
    return rows;
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

  const findDdrReferenceOnDemand = (demandRec) => {
    const record = demandRec || {};
    const keys = Object.keys(record);
    let candidate = null;
    for (let i = 0; i < keys.length; i++) {
      const key = keys[i];
      const low = key.toLowerCase();
      if (!(low.includes("diligence") || low.includes("third"))) continue;
      const val = record[key];
      if (!val || typeof val !== "object") continue;
      const display = String(val.display_value || "").trim();
      const value = String(val.value || "").trim();
      if (/^DDR\\d+$/i.test(display) || /^[0-9a-f]{32}$/i.test(value)) {
        candidate = { key, display, value, link: String(val.link || "") };
        break;
      }
    }
    if (candidate) return candidate;

    const explicitKeys = [
      "third_party_due_diligence_request",
      "u_third_party_due_diligence_request",
      "u_tprm_dd_request",
      "tprm_dd_request",
    ];
    for (let i = 0; i < explicitKeys.length; i++) {
      const key = explicitKeys[i];
      const val = record[key];
      if (!val || typeof val !== "object") continue;
      return {
        key,
        display: String(val.display_value || "").trim(),
        value: String(val.value || "").trim(),
        link: String(val.link || ""),
      };
    }
    return null;
  };

  const fetchDdrFromDemand = async (demandTicket) => {
    if (!demandTicket || !demandTicket._rec) return null;
    const ref = findDdrReferenceOnDemand(demandTicket._rec);
    const ddrNumber = String(ref && ref.display || "").toUpperCase();
    const ddrSysId = String(ref && ref.value || "");
    const demandSysId = String(demandTicket.sys_id || "");
    const demandNumber = String(demandTicket.number || "").toUpperCase();
    const candidates = [];
    if (ref && ref.link) {
      const linkPath = ref.link.indexOf("http") === 0 ? ref.link : (location.origin + ref.link);
      candidates.push({
        url: linkPath + (linkPath.includes("?") ? "&" : "?") + "sysparm_display_value=all&sysparm_exclude_reference_link=true",
        table: "sn_tprm_dd_request",
        mustMatchDemand: false,
      });
    }
    if (/^[0-9a-f]{32}$/i.test(ddrSysId)) {
      candidates.push({
        url: "/api/now/table/sn_tprm_dd_request/" + encodeURIComponent(ddrSysId) + "?sysparm_display_value=all&sysparm_exclude_reference_link=true",
        table: "sn_tprm_dd_request",
        mustMatchDemand: false,
      });
    }
    if (/^DDR\\d+$/i.test(ddrNumber)) {
      const q = encodeURIComponent("number=" + ddrNumber);
      candidates.push({
        url: "/api/now/table/sn_tprm_dd_request?sysparm_query=" + q + "&sysparm_limit=1&sysparm_display_value=all&sysparm_exclude_reference_link=true",
        table: "sn_tprm_dd_request",
        mustMatchDemand: false,
      });
      candidates.push({
        url: "/api/now/table/task?sysparm_query=" + q + "&sysparm_limit=1&sysparm_display_value=all&sysparm_exclude_reference_link=true",
        table: "task",
        mustMatchDemand: false,
      });
    }

    // The Demand form's “Third-party due diligence requests” tab is commonly a
    // related list, so its DDR is not present as a direct field on dmn_demand.
    // Query the DDR table using common Demand reference fields.
    if (/^[0-9a-f]{32}$/i.test(demandSysId)) {
      const relationFields = ["demand", "u_demand", "parent", "top_task", "task"];
      // If ACLs allow dictionary reads, discover the instance-specific reference
      // field used to build the related list.
      try {
        const dictionaryQuery = encodeURIComponent(
          "name=sn_tprm_dd_request^reference=dmn_demand^elementISNOTEMPTY"
        );
        const dictionary = await apiGet(
          "/api/now/table/sys_dictionary?sysparm_query=" +
            dictionaryQuery +
            "&sysparm_fields=element&sysparm_limit=20"
        );
        ((dictionary && dictionary.result) || []).forEach((row) => {
          const element = String(raw(row.element) || dv(row.element) || "").trim();
          if (element && !relationFields.includes(element)) relationFields.unshift(element);
        });
      } catch (dictionaryError) {
        console.warn("DDR relationship field discovery unavailable:", dictionaryError && dictionaryError.message);
      }

      const relationQueries = relationFields.map((field) => field + "=" + demandSysId);
      if (/^DMND\\d+$/i.test(demandNumber)) {
        relationQueries.push("demand.number=" + demandNumber);
        relationQueries.push("parent.number=" + demandNumber);
      }
      relationQueries.forEach((query) => {
        candidates.push({
          url:
            "/api/now/table/sn_tprm_dd_request?sysparm_query=" +
            encodeURIComponent(query) +
            "&sysparm_limit=10&sysparm_display_value=all&sysparm_exclude_reference_link=true",
          table: "sn_tprm_dd_request",
          mustMatchDemand: true,
        });
      });
    }

    for (let i = 0; i < candidates.length; i++) {
      try {
        const candidate = candidates[i];
        const data = await apiGet(candidate.url);
        const rec = data && data.result
          ? (Array.isArray(data.result) ? (data.result[0] || null) : data.result)
          : null;
        if (!rec) continue;
        if (candidate.mustMatchDemand) {
          const referencesDemand = Object.keys(rec).some((key) => {
            const val = rec[key];
            const value = String(val && typeof val === "object" ? val.value || "" : val || "");
            const display = String(val && typeof val === "object" ? val.display_value || "" : val || "");
            return value === demandSysId || display.toUpperCase() === demandNumber;
          });
          if (!referencesDemand) {
            console.warn("Rejected DDR result that does not reference", demandNumber, candidate.url);
            continue;
          }
        }
        if (!rec.sys_class_name) rec.sys_class_name = candidate.table;
        const mapped = await mapTicket(rec, ddrNumber || "DDR");
        mapped.table = candidate.table;
        mapped.sys_class_name = candidate.table;
        mapped.kind = "ddr";
        return mapped;
      } catch (error) {
        console.warn("DDR fetch candidate failed:", candidates[i].url, error && error.message);
      }
    }
    return null;
  };

  const exportTicketPdf = async (ticket) => {
    const sysId = String(ticket && ticket.sys_id || "").trim();
    const number = String(ticket && ticket.number || "").trim().toUpperCase();
    if (!sysId || !number) return null;
    if (!/^(TASK|STRY|DMND)\\d+$/i.test(number)) return null;

    const table = String(ticket.table || ticket.sys_class_name || "task").trim() || "task";
    const candidates = [
      "/" + table + ".do?PDF&sys_id=" + encodeURIComponent(sysId),
      "/task.do?PDF&sys_id=" + encodeURIComponent(sysId),
    ];
    for (let i = 0; i < candidates.length; i++) {
      try {
        const out = await fetchBinary(candidates[i], "application/pdf,*/*;q=0.8");
        const blob = out.blob;
        const ct = out.contentType;
        if (blob.size <= 0) continue;
        const looksPdf = ct.includes("application/pdf") || ct.includes("pdf");
        if (!looksPdf) {
          const probe = await blob.slice(0, 5).text().catch(() => "");
          if (!probe.startsWith("%PDF")) continue;
        }
        return {
          ticket_number: number,
          file_name: number + ".pdf",
          relative_path: "pdf/" + number + ".pdf",
          blob,
          size_bytes: blob.size,
          source: candidates[i],
          generated: false,
        };
      } catch (error) {
        console.warn("PDF export candidate failed:", candidates[i], error && error.message);
      }
    }

    // Fallback: generate a simple PDF from fetched ticket data so PDF output always exists.
    try {
      const blob = buildTicketPdf(ticket);
      return {
        ticket_number: number,
        file_name: number + ".pdf",
        relative_path: "pdf/" + number + ".pdf",
        blob,
        size_bytes: blob.size,
        source: "generated",
        generated: true,
      };
    } catch (error) {
      console.warn("Generated PDF fallback failed:", number, error && error.message);
    }
    return null;
  };

  const pdfEscape = (text) =>
    String(text == null ? "" : text)
      .replace(/\\\\/g, "\\\\\\\\")
      .replace(/\\(/g, "\\\\(")
      .replace(/\\)/g, "\\\\)")
      .replace(/[^\\x20-\\x7E]/g, "?");

  const toPdfBlob = (lines) => {
    const safeLines = (lines || []).map((l) => pdfEscape(l)).filter((l) => l !== "");
    const maxLines = 52;
    const shown = safeLines.slice(0, maxLines);
    if (safeLines.length > maxLines) {
      shown.push("... truncated ...");
    }
    const content =
      "BT\\n" +
      "/F1 10 Tf\\n" +
      "40 800 Td\\n" +
      "14 TL\\n" +
      shown.map((line) => "(" + line + ") Tj\\nT*").join("") +
      "\\nET\\n";

    const objects = [
      "<< /Type /Catalog /Pages 2 0 R >>",
      "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
      "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>",
      "<< /Length " + content.length + " >>\\nstream\\n" + content + "endstream",
      "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ];

    let pdf = "%PDF-1.4\\n";
    const offsets = [0];
    for (let i = 0; i < objects.length; i++) {
      offsets.push(pdf.length);
      pdf += (i + 1) + " 0 obj\\n" + objects[i] + "\\nendobj\\n";
    }
    const xrefStart = pdf.length;
    pdf += "xref\\n0 " + (objects.length + 1) + "\\n";
    pdf += "0000000000 65535 f \\n";
    for (let i = 1; i < offsets.length; i++) {
      pdf += String(offsets[i]).padStart(10, "0") + " 00000 n \\n";
    }
    pdf +=
      "trailer\\n<< /Size " + (objects.length + 1) + " /Root 1 0 R >>\\n" +
      "startxref\\n" + xrefStart + "\\n%%EOF";

    const bytes = new Uint8Array(pdf.length);
    for (let i = 0; i < pdf.length; i++) {
      bytes[i] = pdf.charCodeAt(i) & 0xff;
    }
    return new Blob([bytes], { type: "application/pdf" });
  };

  const buildTicketPdf = (ticket) => {
    const lines = [];
    const num = String(ticket.number || "").toUpperCase();
    lines.push("RiskRegister ServiceNow Ticket Export");
    lines.push(num || "Ticket");
    lines.push("");
    lines.push("State: " + String(ticket.state || ""));
    lines.push("Class/Table: " + String(ticket.sys_class_name || ticket.table || ""));
    lines.push("");
    lines.push("Short description:");
    lines.push(String(ticket.short_description || ticket.title || ""));
    lines.push("");
    lines.push("Description:");
    const desc = String(ticket.description || "");
    if (desc) {
      desc.split(/\\r?\\n/).forEach((ln) => lines.push(ln));
    }
    lines.push("");
    lines.push("Fields:");
    const fields = ticket.fields || {};
    Object.keys(fields).slice(0, 30).forEach((k) => {
      lines.push(k + ": " + String(fields[k] == null ? "" : fields[k]));
    });
    lines.push("");
    lines.push("Relationships:");
    (ticket.related || []).slice(0, 20).forEach((r) => {
      lines.push(
        String(r.parent || "") + " -> " + String(r.child || "") + " " + String(r.type || "")
      );
    });
    lines.push("");
    lines.push("Generated at: " + new Date().toISOString());
    return toPdfBlob(lines);
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
          const safeName = normalizeAttachmentFileName(att.file_name);
          const relativePath = "attachments/" + ticket.number + "/" + safeName;
          att.file_name = safeName;
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

  const writeLocalFolder = async (rootHandle, packet, files, pdfFiles, rootNumber) => {
    if (!rootHandle) {
      // Fallback: trigger downloads
      const jsonBlob = new Blob([JSON.stringify(packet, null, 2)], { type: "application/json" });
      const a = document.createElement("a");
      a.href = URL.createObjectURL(jsonBlob);
      a.download = rootNumber + ".json";
      a.click();
      URL.revokeObjectURL(a.href);
      const downloadFiles = files.concat(pdfFiles || []);
      for (let i = 0; i < downloadFiles.length; i++) {
        const f = downloadFiles[i];
        const link = document.createElement("a");
        link.href = URL.createObjectURL(f.blob);
        link.download = (f.ticket_number || rootNumber) + "_" + f.file_name;
        link.click();
        URL.revokeObjectURL(link.href);
        await sleep(120);
      }
      return { mode: "download" };
    }

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
    const pdfRoot = await packetDir.getDirectoryHandle("pdf", { create: true });
    for (let i = 0; i < (pdfFiles || []).length; i++) {
      const f = pdfFiles[i];
      const fileHandle = await pdfRoot.getFileHandle(f.file_name, { create: true });
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

  const folderDbName = "riskregister-servicenow-folder-v1";
  const folderStoreName = "handles";
  const folderHandleKey = "package-folder";
  let rememberedFolderHandle = null;
  let rememberedFolderGranted = false;

  const openFolderDb = () => new Promise((resolve, reject) => {
    if (!window.indexedDB) {
      reject(new Error("IndexedDB is unavailable."));
      return;
    }
    const request = window.indexedDB.open(folderDbName, 1);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(folderStoreName)) {
        db.createObjectStore(folderStoreName);
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error || new Error("Could not open saved-folder storage."));
  });

  const loadRememberedFolder = async () => {
    const db = await openFolderDb();
    try {
      return await new Promise((resolve, reject) => {
        const request = db.transaction(folderStoreName, "readonly")
          .objectStore(folderStoreName)
          .get(folderHandleKey);
        request.onsuccess = () => resolve(request.result || null);
        request.onerror = () => reject(request.error || new Error("Could not read saved folder."));
      });
    } finally {
      db.close();
    }
  };

  const saveRememberedFolder = async (handle) => {
    const db = await openFolderDb();
    try {
      await new Promise((resolve, reject) => {
        const tx = db.transaction(folderStoreName, "readwrite");
        tx.objectStore(folderStoreName).put(handle, folderHandleKey);
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error || new Error("Could not save folder permission."));
        tx.onabort = () => reject(tx.error || new Error("Saving folder permission was aborted."));
      });
    } finally {
      db.close();
    }
  };

  const chooseAndRememberFolder = async () => {
    if (!window.showDirectoryPicker) return null;
    const handle = await window.showDirectoryPicker({ mode: "readwrite" });
    rememberedFolderHandle = handle;
    rememberedFolderGranted = true;
    if (CFG.rememberFolder) {
      try {
        await saveRememberedFolder(handle);
      } catch (error) {
        console.warn("Folder selected but could not be remembered:", error && error.message);
      }
    }
    return handle;
  };

  const runExport = async (statusEl, btn) => {
    btn.disabled = true;
    try {
      // File pickers require an active user gesture. Open it before any ServiceNow
      // fetch/await work; otherwise Chromium expires the click activation.
      let rootHandle = null;
      if (CFG.rememberFolder && rememberedFolderHandle && rememberedFolderGranted) {
        rootHandle = rememberedFolderHandle;
        setStatus(statusEl, "Using saved package folder: " + rememberedFolderHandle.name);
      } else if (window.showDirectoryPicker) {
        setStatus(statusEl, "Choose a folder for the task packet…");
        try {
          rootHandle = await chooseAndRememberFolder();
        } catch (pickerError) {
          if (pickerError && pickerError.name !== "AbortError") {
            console.warn("Folder picker unavailable; browser downloads will be used:", pickerError.message);
          }
          setStatus(statusEl, "No folder selected. Browser downloads will be used…");
        }
      }

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

      const firstDemand = tickets.find((t) => /^DMND\\d+$/i.test(String(t.number || ""))) || null;
      if (firstDemand) {
        setStatus(statusEl, "Checking linked Third-party due diligence request…");
        const ddrTicket = await fetchDdrFromDemand(firstDemand);
        if (ddrTicket && !tickets.some((t) => String(t.sys_id) === String(ddrTicket.sys_id))) {
          tickets.push(ddrTicket);
          console.log(
            "%cDDR related-list record found: " + String(ddrTicket.number || ddrTicket.sys_id),
            "color:#0f766e;font-weight:bold"
          );
          relationships.push({
            parent: String(firstDemand.number || ""),
            child: String(ddrTicket.number || ""),
            type: "Third-party due diligence request",
            parent_sys_id: String(firstDemand.sys_id || ""),
            child_sys_id: String(ddrTicket.sys_id || ""),
          });
        } else if (!ddrTicket) {
          console.warn(
            "No Third-party due diligence request record could be resolved for " +
              String(firstDemand.number || "the Demand") +
              "."
          );
        }
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

      setStatus(statusEl, "Exporting Demand/Story/Task PDFs…");
      const pdfFiles = [];
      const pdfWarnings = [];
      const seenPdf = new Set();
      for (let i = 0; i < tickets.length; i++) {
        const t = tickets[i];
        const num = String(t.number || "").toUpperCase();
        if (!/^(TASK|STRY|DMND)\\d+$/i.test(num) || seenPdf.has(num)) continue;
        seenPdf.add(num);
        const pdf = await exportTicketPdf(t);
        if (pdf) {
          pdfFiles.push(pdf);
        } else {
          pdfWarnings.push("Could not export PDF for " + num);
        }
      }

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
        exported_pdfs: pdfFiles.map((f) => ({
          ticket_number: f.ticket_number,
          file_name: f.file_name,
          relative_path: f.relative_path,
          size_bytes: f.size_bytes,
        })),
      };

      setStatus(statusEl, rootHandle ? "Writing packet to selected folder…" : "Downloading packet files…");
      const local = await writeLocalFolder(rootHandle, packet, files, pdfFiles, rootNumber);

      setStatus(statusEl, "Importing packet into Ticket Dossier…");
      const imported = await postPacket(packet);
      const projectId = imported.project_id;
      if (!projectId) throw new Error("Import did not return a project_id.");

      for (let i = 0; i < files.length; i++) {
        setStatus(statusEl, "Uploading attachment " + (i + 1) + "/" + files.length + "…");
        await postAttachment(projectId, files[i]);
        await sleep(30);
      }
      for (let i = 0; i < pdfFiles.length; i++) {
        setStatus(statusEl, "Uploading PDF " + (i + 1) + "/" + pdfFiles.length + "…");
        await postAttachment(projectId, pdfFiles[i]);
        await sleep(30);
      }

      setStatus(statusEl, "Finalizing…");
      const done = await postComplete(projectId);
      const msg =
        "Done. Tickets: " + cleanTickets.length +
        ", relationships: " + relationships.length +
        ", attachments: " + files.length +
        ", pdf: " + pdfFiles.length +
        (skipped ? " (skipped " + skipped + ")" : "") +
        (pdfWarnings.length ? "\\n" + pdfWarnings.join("\\n") : "") +
        (local.mode === "folder" ? ". Saved to local folder." : ". Browser downloads used (no folder API).") +
        (done.project_url ? "\\nOpen: " + done.project_url : "");
      setStatus(statusEl, msg);
      console.log("%c✅ RiskRegister ServiceNow packet complete", "color:#047857;font-weight:bold;font-size:14px", done);
      window.postMessage({
        source: "riskregister-servicenow-exporter",
        type: "RR_SN_EXPORT_COMPLETE",
        taskNumber: CFG.taskNumber
      }, window.location.origin);
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
    "</code> · direct Task Relationships + attachments. A saved folder is reused when permitted.</div>" +
    '<button id="rr-sn-export-btn" type="button" style="background:#0d9488;color:#fff;border:0;border-radius:8px;' +
    'padding:8px 12px;font-weight:600;cursor:pointer;margin-right:8px">Export packet</button>' +
    (CFG.rememberFolder
      ? '<button id="rr-sn-folder-btn" type="button" style="background:transparent;color:#99f6e4;border:1px solid #0f766e;' +
        'border-radius:8px;padding:8px 12px;cursor:pointer;margin-right:8px">Change folder</button>'
      : "") +
    '<button id="rr-sn-close-btn" type="button" style="background:transparent;color:#94a3b8;border:1px solid #475569;' +
    'border-radius:8px;padding:8px 12px;cursor:pointer">Close</button>' +
    '<div id="rr-sn-status" style="margin-top:10px;font-size:12px;color:#99f6e4;white-space:pre-wrap"></div>';
  document.documentElement.appendChild(overlay);
  const statusEl = document.getElementById("rr-sn-status");
  const btn = document.getElementById("rr-sn-export-btn");
  const folderBtn = document.getElementById("rr-sn-folder-btn");
  const closeBtn = document.getElementById("rr-sn-close-btn");
  closeBtn.addEventListener("click", () => {
    overlay.remove();
    window.__rrSnPacketRunning = false;
  });
  if (folderBtn) {
    folderBtn.addEventListener("click", async () => {
      folderBtn.disabled = true;
      setStatus(statusEl, "Choose the package folder to remember…");
      try {
        const handle = await chooseAndRememberFolder();
        setStatus(statusEl, handle
          ? "Saved package folder: " + handle.name
          : "Folder selection is unavailable in this browser.");
      } catch (error) {
        if (!error || error.name !== "AbortError") {
          setStatus(statusEl, "Could not save folder: " + (error && error.message ? error.message : error), true);
        } else {
          setStatus(statusEl, "Folder was not changed.");
        }
      } finally {
        folderBtn.disabled = false;
      }
    });
  }
  btn.addEventListener("click", () => runExport(statusEl, btn));
  setStatus(
    statusEl,
    CFG.rememberFolder
      ? "Ready. A permitted saved folder will be reused; otherwise you will be asked to choose one."
      : "Ready. You will be asked to choose a package folder."
  );
  if (CFG.rememberFolder) {
    loadRememberedFolder()
      .then(async (handle) => {
        if (!handle || typeof handle.queryPermission !== "function") return;
        const permission = await handle.queryPermission({ mode: "readwrite" });
        if (permission === "granted") {
          rememberedFolderHandle = handle;
          rememberedFolderGranted = true;
          setStatus(statusEl, "Ready. Saved package folder will be reused: " + handle.name);
        }
      })
      .catch((error) => {
        console.warn("Saved package folder could not be loaded:", error && error.message);
      });
  }
  console.log("%cRiskRegister ServiceNow overlay ready — click Export packet", "color:#0f766e;font-weight:bold;font-size:14px");
})();`;
  }

  window.ServiceNowConsoleSync = { buildConsoleScript };
})();
