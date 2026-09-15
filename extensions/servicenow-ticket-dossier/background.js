/**
 * RiskRegister Browser Sync — MV3 service worker.
 *
 * Stores short-lived prepared scripts and evaluates them only in the matching
 * ServiceNow or SharePoint tab, then immediately detaches the debugger.
 */

const STORAGE_KEY = 'riskregisterServiceNowPendingV1';
const SHAREPOINT_STORAGE_KEY = 'riskregisterSharePointPendingV1';
const MAX_SCRIPT_BYTES = 250000;
const MAX_TTL_SECONDS = 35 * 60;
const DEBUGGER_VERSION = '1.3';

function storageGet(key) {
  return new Promise((resolve) => {
    chrome.storage.local.get(key, (result) => {
      resolve(chrome.runtime.lastError ? {} : (result || {}));
    });
  });
}

function storageSet(values) {
  return new Promise((resolve, reject) => {
    chrome.storage.local.set(values, () => {
      if (chrome.runtime.lastError) {
        reject(new Error(chrome.runtime.lastError.message));
        return;
      }
      resolve();
    });
  });
}

function storageRemove(key) {
  return new Promise((resolve) => chrome.storage.local.remove(key, resolve));
}

function debuggerAttach(target) {
  return new Promise((resolve, reject) => {
    chrome.debugger.attach(target, DEBUGGER_VERSION, () => {
      if (chrome.runtime.lastError) {
        reject(new Error(chrome.runtime.lastError.message));
        return;
      }
      resolve();
    });
  });
}

function debuggerCommand(target, method, params) {
  return new Promise((resolve, reject) => {
    chrome.debugger.sendCommand(target, method, params, (result) => {
      if (chrome.runtime.lastError) {
        reject(new Error(chrome.runtime.lastError.message));
        return;
      }
      resolve(result || {});
    });
  });
}

function debuggerDetach(target) {
  return new Promise((resolve) => {
    chrome.debugger.detach(target, () => resolve());
  });
}

function parseUrl(raw) {
  try {
    return new URL(String(raw || ''));
  } catch (_error) {
    return null;
  }
}

function isRiskRegisterPage(url) {
  const parsed = parseUrl(url);
  if (!parsed) return false;
  const host = parsed.hostname.toLowerCase();
  if (host !== 'localhost' && host !== '127.0.0.1') return false;
  return parsed.pathname.toLowerCase().includes('/riskregister/');
}

function isServiceNowOrigin(origin) {
  const parsed = parseUrl(origin);
  const hostname = parsed ? parsed.hostname.toLowerCase() : '';
  return !!(
    parsed
    && parsed.protocol === 'https:'
    && (
      hostname === 'servicenow.adventhealth.com'
      || hostname === 'service-now.com'
      || hostname.endsWith('.service-now.com')
    )
    && parsed.origin === String(origin || '').replace(/\/$/, '')
  );
}

function isSharePointOrigin(origin) {
  const parsed = parseUrl(origin);
  const hostname = parsed ? parsed.hostname.toLowerCase() : '';
  return !!(
    parsed
    && parsed.protocol === 'https:'
    && hostname.endsWith('.sharepoint.com')
    && parsed.origin === String(origin || '').replace(/\/$/, '')
  );
}

function validatePrepared(message, sender) {
  if (!isRiskRegisterPage(sender && sender.url)) {
    throw new Error('Prepare messages are accepted only from the local RiskRegister application.');
  }

  const config = message && message.config;
  if (!config || typeof config !== 'object') {
    throw new Error('Missing prepared sync configuration.');
  }

  const script = String(config.script || '');
  const instanceOrigin = String(config.instanceOrigin || '').replace(/\/$/, '');
  const taskNumber = String(config.taskNumber || '').trim().toUpperCase();
  const expiresAt = Number(config.expiresAt || 0);

  if (
    script.length < 1000
    || script.length > MAX_SCRIPT_BYTES
    || !script.startsWith('void (async function () {')
    || !script.includes('RiskRegister ServiceNow')
  ) {
    throw new Error('Prepared exporter script failed validation.');
  }
  if (!isServiceNowOrigin(instanceOrigin)) {
    throw new Error('Prepared ServiceNow origin is not allowed.');
  }
  if (!/^TASK\d+$/.test(taskNumber) || !script.includes(taskNumber)) {
    throw new Error('Prepared TASK number failed validation.');
  }

  const now = Math.floor(Date.now() / 1000);
  if (expiresAt <= now || expiresAt > now + MAX_TTL_SECONDS) {
    throw new Error('Prepared sync token is expired or has an invalid lifetime.');
  }

  const senderOrigin = parseUrl(sender.url).origin;
  const endpointMarker = senderOrigin.toLowerCase() + '/riskregister/public/ticket-dossier/browser-sync.php';
  if (!script.toLowerCase().includes(endpointMarker)) {
    throw new Error('Exporter return endpoint does not match this RiskRegister origin.');
  }

  return {
    script,
    instanceOrigin,
    taskNumber,
    expiresAt,
    preparedAt: Date.now(),
    riskRegisterTabId: sender.tab && Number.isInteger(sender.tab.id) ? sender.tab.id : null,
    lastDocumentId: '',
    lastStatus: 'Prepared; waiting for matching ServiceNow tab.',
    lastError: '',
  };
}

function validateSharePointPrepared(message, sender) {
  if (!isRiskRegisterPage(sender && sender.url)) {
    throw new Error('Prepare messages are accepted only from the local RiskRegister application.');
  }

  const config = message && message.config;
  if (!config || typeof config !== 'object') {
    throw new Error('Missing prepared SharePoint sync configuration.');
  }

  const script = String(config.script || '');
  const sharePointOrigin = String(config.sharePointOrigin || '').replace(/\/$/, '');
  const sourceKey = String(config.sourceKey || '').trim();
  const expiresAt = Number(config.expiresAt || 0);

  if (
    script.length < 1000
    || script.length > MAX_SCRIPT_BYTES
    || !script.startsWith('void (async function () {')
    || !script.includes('RiskRegister · SharePoint sync')
  ) {
    throw new Error('Prepared SharePoint script failed validation.');
  }
  if (!isSharePointOrigin(sharePointOrigin)) {
    throw new Error('Prepared SharePoint origin is not allowed.');
  }
  if (!sourceKey || sourceKey.length > 190 || !script.includes(sourceKey)) {
    throw new Error('Prepared SharePoint source key failed validation.');
  }

  const now = Math.floor(Date.now() / 1000);
  if (expiresAt <= now || expiresAt > now + MAX_TTL_SECONDS) {
    throw new Error('Prepared sync token is expired or has an invalid lifetime.');
  }

  const senderOrigin = parseUrl(sender.url).origin;
  const endpointMarker =
    senderOrigin.toLowerCase() + '/riskregister/public/sharepoint.php?action=browser_sync_import';
  if (!script.toLowerCase().includes(endpointMarker)) {
    throw new Error('SharePoint sync return endpoint does not match this RiskRegister origin.');
  }

  return {
    script,
    sharePointOrigin,
    sourceKey,
    expiresAt,
    preparedAt: Date.now(),
    riskRegisterTabId: sender.tab && Number.isInteger(sender.tab.id) ? sender.tab.id : null,
    lastDocumentId: '',
    lastStatus: 'Prepared; waiting for matching SharePoint tab.',
    lastError: '',
  };
}

async function notifyRiskRegister(pending, type, message) {
  if (!pending || !Number.isInteger(pending.riskRegisterTabId)) return;
  try {
    await chrome.tabs.sendMessage(pending.riskRegisterTabId, {
      type,
      message,
      taskNumber: pending.taskNumber,
    });
  } catch (_error) {
    // The preparing tab may have refreshed or closed.
  }
}

async function injectSharePointPrepared(sender) {
  const stored = await storageGet(SHAREPOINT_STORAGE_KEY);
  const pending = stored[SHAREPOINT_STORAGE_KEY];
  if (!pending) {
    return { ok: false, waiting: true, error: 'No prepared RiskRegister SharePoint sync is waiting.' };
  }

  const now = Math.floor(Date.now() / 1000);
  if (Number(pending.expiresAt || 0) <= now) {
    await storageRemove(SHAREPOINT_STORAGE_KEY);
    return { ok: false, error: 'The prepared RiskRegister token expired. Prepare again.' };
  }

  const tabId = sender && sender.tab && sender.tab.id;
  const tabUrl = sender && sender.tab && sender.tab.url;
  const parsed = parseUrl(tabUrl);
  if (!Number.isInteger(tabId) || !parsed || parsed.origin !== pending.sharePointOrigin) {
    return { ok: false, waiting: true, error: 'This SharePoint tab does not match the prepared source.' };
  }

  const documentId = String(sender.documentId || '');
  if (documentId !== '' && pending.lastDocumentId === documentId) {
    return { ok: true, alreadyInjected: true };
  }

  const target = { tabId };
  let attached = false;
  try {
    pending.lastStatus = 'Starting read-only sync in SharePoint…';
    pending.lastError = '';
    await storageSet({ [SHAREPOINT_STORAGE_KEY]: pending });

    await debuggerAttach(target);
    attached = true;
    const result = await debuggerCommand(target, 'Runtime.evaluate', {
      expression: pending.script,
      awaitPromise: false,
      returnByValue: true,
      userGesture: true,
    });
    if (result && result.exceptionDetails) {
      const detail = result.exceptionDetails.exception
        && result.exceptionDetails.exception.description;
      throw new Error(detail || result.exceptionDetails.text || 'SharePoint script evaluation failed.');
    }

    pending.lastDocumentId = documentId;
    pending.lastStatus = 'SharePoint sync prompt opened; waiting for Start sync.';
    pending.lastError = '';
    await storageSet({ [SHAREPOINT_STORAGE_KEY]: pending });
    await notifyRiskRegister(pending, 'RR_SP_EXTENSION_INJECTED', pending.lastStatus);

    return { ok: true, injected: true, sourceKey: pending.sourceKey };
  } catch (error) {
    const message = error && error.message ? error.message : String(error);
    pending.lastStatus = 'Automatic SharePoint injection failed.';
    pending.lastError = message;
    await storageSet({ [SHAREPOINT_STORAGE_KEY]: pending });
    await notifyRiskRegister(
      pending,
      'RR_SP_EXTENSION_ERROR',
      'Automatic SharePoint start failed: ' + message + ' Use the copied console script as fallback.'
    );
    return { ok: false, error: message };
  } finally {
    if (attached) {
      await debuggerDetach(target);
    }
  }
}

async function injectPrepared(sender) {
  const stored = await storageGet(STORAGE_KEY);
  const pending = stored[STORAGE_KEY];
  if (!pending) {
    return { ok: false, waiting: true, error: 'No prepared RiskRegister export is waiting.' };
  }

  const now = Math.floor(Date.now() / 1000);
  if (Number(pending.expiresAt || 0) <= now) {
    await storageRemove(STORAGE_KEY);
    return { ok: false, error: 'The prepared RiskRegister token expired. Prepare again.' };
  }

  const tabId = sender && sender.tab && sender.tab.id;
  const tabUrl = sender && sender.tab && sender.tab.url;
  const parsed = parseUrl(tabUrl);
  if (!Number.isInteger(tabId) || !parsed || parsed.origin !== pending.instanceOrigin) {
    return { ok: false, waiting: true, error: 'This ServiceNow tab does not match the prepared instance.' };
  }

  const documentId = String(sender.documentId || '');
  if (documentId !== '' && pending.lastDocumentId === documentId) {
    return { ok: true, alreadyInjected: true };
  }

  const target = { tabId };
  let attached = false;
  try {
    pending.lastStatus = 'Starting exporter in ServiceNow…';
    pending.lastError = '';
    await storageSet({ [STORAGE_KEY]: pending });

    await debuggerAttach(target);
    attached = true;
    const result = await debuggerCommand(target, 'Runtime.evaluate', {
      expression: pending.script,
      awaitPromise: false,
      returnByValue: true,
      userGesture: true,
    });
    if (result && result.exceptionDetails) {
      const detail = result.exceptionDetails.exception
        && result.exceptionDetails.exception.description;
      throw new Error(detail || result.exceptionDetails.text || 'ServiceNow script evaluation failed.');
    }

    pending.lastDocumentId = documentId;
    pending.lastStatus = 'Exporter started in ServiceNow. Click Export packet on the overlay.';
    pending.lastError = '';
    await storageSet({ [STORAGE_KEY]: pending });
    await notifyRiskRegister(pending, 'RR_SN_EXTENSION_INJECTED', pending.lastStatus);

    return { ok: true, injected: true, taskNumber: pending.taskNumber };
  } catch (error) {
    const message = error && error.message ? error.message : String(error);
    pending.lastStatus = 'Automatic injection failed.';
    pending.lastError = message;
    await storageSet({ [STORAGE_KEY]: pending });
    await notifyRiskRegister(
      pending,
      'RR_SN_EXTENSION_ERROR',
      'Automatic injection failed: ' + message + ' Use the copied console script as fallback.'
    );
    return { ok: false, error: message };
  } finally {
    if (attached) {
      await debuggerDetach(target);
    }
  }
}

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  const type = String(message && message.type || '');

  if (type === 'RR_SN_PREPARE') {
    (async () => {
      try {
        const pending = validatePrepared(message, sender);
        await storageSet({ [STORAGE_KEY]: pending });
        sendResponse({ ok: true, taskNumber: pending.taskNumber });
      } catch (error) {
        sendResponse({ ok: false, error: error && error.message ? error.message : String(error) });
      }
    })();
    return true;
  }

  if (type === 'RR_SP_PREPARE') {
    (async () => {
      try {
        const pending = validateSharePointPrepared(message, sender);
        await storageSet({ [SHAREPOINT_STORAGE_KEY]: pending });
        sendResponse({ ok: true, sourceKey: pending.sourceKey });
      } catch (error) {
        sendResponse({ ok: false, error: error && error.message ? error.message : String(error) });
      }
    })();
    return true;
  }

  if (type === 'RR_SN_TAB_READY') {
    injectPrepared(sender).then(sendResponse);
    return true;
  }

  if (type === 'RR_SP_TAB_READY') {
    injectSharePointPrepared(sender).then(sendResponse);
    return true;
  }

  if (type === 'RR_SN_EXPORT_COMPLETE') {
    (async () => {
      const stored = await storageGet(STORAGE_KEY);
      const pending = stored[STORAGE_KEY];
      const senderOrigin = parseUrl(sender && sender.url);
      const taskNumber = String(message.taskNumber || '').trim().toUpperCase();
      if (
        pending
        && senderOrigin
        && senderOrigin.origin === pending.instanceOrigin
        && taskNumber === pending.taskNumber
      ) {
        await notifyRiskRegister(
          pending,
          'RR_SN_EXTENSION_INJECTED',
          'ServiceNow export completed and the pending extension state was cleared.'
        );
        await storageRemove(STORAGE_KEY);
      }
      sendResponse({ ok: true });
    })();
    return true;
  }

  if (type === 'RR_SP_SYNC_COMPLETE') {
    (async () => {
      const stored = await storageGet(SHAREPOINT_STORAGE_KEY);
      const pending = stored[SHAREPOINT_STORAGE_KEY];
      const senderOrigin = parseUrl(sender && sender.url);
      const sourceKey = String(message.sourceKey || '').trim();
      if (
        pending
        && senderOrigin
        && senderOrigin.origin === pending.sharePointOrigin
        && sourceKey === pending.sourceKey
      ) {
        await notifyRiskRegister(
          pending,
          'RR_SP_EXTENSION_INJECTED',
          'SharePoint sync completed and the pending extension state was cleared.'
        );
        await storageRemove(SHAREPOINT_STORAGE_KEY);
      }
      sendResponse({ ok: true });
    })();
    return true;
  }

  if (type === 'RR_SN_GET_STATUS') {
    storageGet(STORAGE_KEY).then((stored) => {
      const pending = stored[STORAGE_KEY] || null;
      sendResponse({ ok: true, pending });
    });
    return true;
  }

  if (type === 'RR_SN_CLEAR') {
    storageRemove(STORAGE_KEY).then(() => sendResponse({ ok: true }));
    return true;
  }

  if (type === 'RR_SP_GET_STATUS') {
    storageGet(SHAREPOINT_STORAGE_KEY).then((stored) => {
      const pending = stored[SHAREPOINT_STORAGE_KEY] || null;
      sendResponse({ ok: true, pending });
    });
    return true;
  }

  if (type === 'RR_SP_CLEAR') {
    storageRemove(SHAREPOINT_STORAGE_KEY).then(() => sendResponse({ ok: true }));
    return true;
  }

  return false;
});
