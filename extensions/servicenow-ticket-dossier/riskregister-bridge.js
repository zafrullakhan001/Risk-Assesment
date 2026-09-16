/**
 * Page bridge for the local RiskRegister application.
 *
 * The page cannot call chrome.runtime directly. This content script relays only
 * narrowly-scoped prepare messages to the extension service worker.
 */

const PAGE_SOURCE = 'riskregister-servicenow-page';
const EXTENSION_SOURCE = 'riskregister-servicenow-extension';
const SHAREPOINT_PAGE_SOURCE = 'riskregister-sharepoint-page';
const SHAREPOINT_EXTENSION_SOURCE = 'riskregister-sharepoint-extension';

function extensionReadyPayload() {
  try {
    return {
      version: chrome.runtime.getManifest().version,
      capabilities: {
        flexibleTicketNumbers: true,
      },
    };
  } catch (_error) {
    return null;
  }
}

function safeRuntimeSendMessage(message, callback) {
  try {
    chrome.runtime.sendMessage(message, callback);
    return true;
  } catch (_error) {
    return false;
  }
}

function postToPage(type, payload, source = EXTENSION_SOURCE) {
  window.postMessage({
    source,
    type,
    ...(payload || {}),
  }, window.location.origin);
}

window.addEventListener('message', (event) => {
  if (event.source !== window || event.origin !== window.location.origin) return;
  const data = event.data;
  if (!data) return;

  if (data.source === PAGE_SOURCE && data.type === 'RR_SN_EXTENSION_PING') {
    const ready = extensionReadyPayload();
    if (ready) postToPage('RR_SN_EXTENSION_READY', ready);
    return;
  }

  if (data.source === SHAREPOINT_PAGE_SOURCE && data.type === 'RR_SP_EXTENSION_PING') {
    const ready = extensionReadyPayload();
    if (!ready) return;
    postToPage(
      'RR_SP_EXTENSION_READY',
      { version: ready.version },
      SHAREPOINT_EXTENSION_SOURCE
    );
    return;
  }

  const isServiceNowPrepare = data.source === PAGE_SOURCE && data.type === 'RR_SN_PREPARE';
  const isSharePointPrepare =
    data.source === SHAREPOINT_PAGE_SOURCE && data.type === 'RR_SP_PREPARE';
  if (!isServiceNowPrepare && !isSharePointPrepare) return;

  const requestId = String(data.requestId || '');
  const sent = safeRuntimeSendMessage({
    type: isSharePointPrepare ? 'RR_SP_PREPARE' : 'RR_SN_PREPARE',
    config: data.config,
  }, (response) => {
    if (chrome.runtime.lastError) {
      postToPage(
        isSharePointPrepare ? 'RR_SP_EXTENSION_PREPARE_RESULT' : 'RR_SN_EXTENSION_PREPARE_RESULT',
        {
          requestId,
          ok: false,
          error: chrome.runtime.lastError.message,
        },
        isSharePointPrepare ? SHAREPOINT_EXTENSION_SOURCE : EXTENSION_SOURCE
      );
      return;
    }
    postToPage(
      isSharePointPrepare ? 'RR_SP_EXTENSION_PREPARE_RESULT' : 'RR_SN_EXTENSION_PREPARE_RESULT',
      {
        requestId,
        ...(response || { ok: false, error: 'Extension did not respond.' }),
      },
      isSharePointPrepare ? SHAREPOINT_EXTENSION_SOURCE : EXTENSION_SOURCE
    );
  });
  if (!sent) {
    postToPage(
      isSharePointPrepare ? 'RR_SP_EXTENSION_PREPARE_RESULT' : 'RR_SN_EXTENSION_PREPARE_RESULT',
      {
        requestId,
        ok: false,
        error: 'Extension was reloaded. Refresh this RiskRegister page and try again.',
      },
      isSharePointPrepare ? SHAREPOINT_EXTENSION_SOURCE : EXTENSION_SOURCE
    );
  }
});

chrome.runtime.onMessage.addListener((message) => {
  const type = String(message && message.type || '');
  if (type === 'RR_SN_EXTENSION_INJECTED' || type === 'RR_SN_EXTENSION_ERROR') {
    postToPage(type, {
      message: String(message.message || ''),
      taskNumber: String(message.taskNumber || ''),
    });
    return;
  }
  if (type === 'RR_SP_EXTENSION_INJECTED' || type === 'RR_SP_EXTENSION_ERROR') {
    postToPage(type, {
      message: String(message.message || ''),
      sourceKey: String(message.sourceKey || ''),
    }, SHAREPOINT_EXTENSION_SOURCE);
  }
});

const initialReady = extensionReadyPayload();
if (initialReady) {
  postToPage('RR_SN_EXTENSION_READY', initialReady);
  postToPage(
    'RR_SP_EXTENSION_READY',
    { version: initialReady.version },
    SHAREPOINT_EXTENSION_SOURCE
  );
}
