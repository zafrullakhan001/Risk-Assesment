/**
 * One-click SharePoint sync via Microsoft login (MSAL popup) + Graph crawl on the server.
 * No SharePoint console paste — MFA happens in the Microsoft sign-in popup.
 */
(() => {
  const copyFromTarget = async (btn) => {
    const id = btn.getAttribute('data-copy-target') || '';
    const el = id ? document.getElementById(id) : null;
    if (!el) return;
    const text = 'value' in el ? String(el.value || '') : String(el.textContent || '');
    try {
      await navigator.clipboard.writeText(text.trim());
      const prev = btn.textContent;
      btn.textContent = 'Copied';
      window.setTimeout(() => {
        btn.textContent = prev;
      }, 1400);
    } catch {
      if ('select' in el && typeof el.select === 'function') {
        el.select();
      }
      btn.textContent = 'Select & Ctrl+C';
    }
  };

  document.querySelectorAll('[data-copy-target]').forEach((btn) => {
    btn.addEventListener('click', () => {
      copyFromTarget(btn);
    });
  });

  const root = document.getElementById('sharepoint-msal-sync');
  if (!root) return;

  const statusEl = document.getElementById('sharepoint-msal-status');
  const csrf = root.getAttribute('data-csrf') || '';
  const GRAPH_SCOPES = ['https://graph.microsoft.com/Sites.Read.All', 'openid', 'profile'];

  const setStatus = (text, ok) => {
    if (!statusEl) return;
    statusEl.textContent = text;
    statusEl.classList.toggle('is-ok', ok === true);
    statusEl.classList.toggle('is-error', ok === false);
  };

  const readConfig = () => {
    const tenantId = (root.getAttribute('data-tenant-id') || '').trim();
    const clientId = (root.getAttribute('data-client-id') || '').trim();
    return { tenantId, clientId };
  };

  let msalInstance = null;

  const ensureMsal = () => {
    if (!window.msal || !window.msal.PublicClientApplication) {
      throw new Error(
        'Microsoft sign-in library failed to load (assets/vendor/msal-browser.min.js). Hard-refresh the page; if it still fails, use Console sync.'
      );
    }
    const { tenantId, clientId } = readConfig();
    if (!tenantId || !clientId) {
      throw new Error(
        'Save Tenant ID and Client ID in SharePoint sync settings first (client secret not required for one-click Sync).'
      );
    }
    if (!msalInstance) {
      msalInstance = new window.msal.PublicClientApplication({
        auth: {
          clientId,
          authority: `https://login.microsoftonline.com/${tenantId}`,
          redirectUri: window.location.origin + window.location.pathname,
        },
        cache: {
          cacheLocation: 'sessionStorage',
          storeAuthStateInCookie: false,
        },
      });
    }
    return msalInstance;
  };

  const acquireToken = async () => {
    const pca = ensureMsal();
    if (typeof pca.initialize === 'function') {
      await pca.initialize();
    }
    const accounts = pca.getAllAccounts();
    const request = { scopes: GRAPH_SCOPES, prompt: accounts.length ? undefined : 'select_account' };
    if (accounts.length) {
      try {
        const silent = await pca.acquireTokenSilent({ ...request, account: accounts[0] });
        if (silent?.accessToken) return silent.accessToken;
      } catch {
        /* fall through to popup */
      }
    }
    const result = await pca.loginPopup(request);
    if (!result?.accessToken) {
      throw new Error('Microsoft sign-in did not return an access token.');
    }
    return result.accessToken;
  };

  const runSync = async (sourceKey, triggerBtn) => {
    const key = String(sourceKey || root.getAttribute('data-source-key') || '').trim();
    if (!key) {
      setStatus('Missing source_key for sync.', false);
      return;
    }

    const buttons = document.querySelectorAll('.sharepoint-msal-sync-btn');
    buttons.forEach((btn) => {
      btn.disabled = true;
    });
    setStatus(`Signing in to Microsoft for “${key}”…`, null);

    try {
      const accessToken = await acquireToken();
      setStatus(`Signed in. Deep-crawling SharePoint for “${key}” (may take a few minutes)…`, null);

      const body = new FormData();
      body.set('action', 'delegated_sync');
      body.set('csrf_token', csrf);
      body.set('source_key', key);
      body.set('source', key);
      body.set('access_token', accessToken);

      const response = await fetch('sharepoint.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        body,
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload.ok) {
        throw new Error(payload.error || `Sync failed (HTTP ${response.status}).`);
      }

      setStatus(`✅ ${payload.message || 'Sync complete.'} Refreshing…`, true);
      window.setTimeout(() => {
        const url = new URL(window.location.href);
        url.searchParams.set('source', key);
        url.hash = 'sharepoint-search';
        window.location.assign(url.toString());
      }, 900);
    } catch (error) {
      const message = error?.message || String(error);
      let hint = message;
      if (/AADSTS65001|consent|interaction_required/i.test(message)) {
        hint +=
          ' — Ask IT to grant admin consent for delegated Sites.Read.All on this Entra app, and add an SPA redirect URI for this page.';
      } else if (/AADSTS700016|invalid_client|client_id/i.test(message)) {
        hint += ' — Check Client ID / Tenant ID and that the app has a Single-page application redirect URI.';
      } else if (/popup/i.test(message)) {
        hint += ' — Allow popups for this site, then try Sync again.';
      }
      setStatus(hint, false);
    } finally {
      buttons.forEach((btn) => {
        btn.disabled = false;
      });
      if (triggerBtn) triggerBtn.disabled = false;
    }
  };

  document.querySelectorAll('.sharepoint-msal-sync-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      runSync(btn.getAttribute('data-source-key') || '', btn);
    });
  });

  const { tenantId, clientId } = readConfig();
  if (!tenantId || !clientId) {
    setStatus('Save Tenant ID and Client ID below, then use Sync. (No client secret needed for one-click.)', null);
  } else {
    setStatus('Ready — click Sync on a folder card (or below). Microsoft login popup handles MFA.', true);
  }
})();
