# App Updates (GitHub + PAT)

This install can check GitHub for new commits and apply them with `git fetch` + `git checkout` from **Admin → App updates**.

Open the page from the **Admin** link in the top bar, or go to `public/admin/updates.php`. The old `public/updates.php` URL redirects there.

**Who:** administrators only (local or LDAP accounts with the admin role).

---

## Enable a GitHub Personal Access Token

Private repositories need a **classic PAT** with the **`repo`** scope. A token is also recommended for public repos (higher API rate limits, and git fetch will not hang on credential prompts under Apache).

### Create the token

1. Sign in to GitHub.
2. Open this URL (scope and note are pre-filled):

   [https://github.com/settings/tokens/new?scopes=repo&description=Risk%20Assessment%20Updater](https://github.com/settings/tokens/new?scopes=repo&description=Risk%20Assessment%20Updater)

3. Confirm:
   - **Note:** Risk Assessment Updater
   - **Expiration:** 90 days or longer
   - **Scopes:** `repo` (full control of private repositories)
4. Click **Generate token**.
5. Copy the token (it starts with `ghp_`). GitHub shows it only once.
6. In this app: **Admin → App updates** → paste the token → **Save settings**.

Manage existing tokens: [https://github.com/settings/tokens](https://github.com/settings/tokens)

### Fine-grained token (optional)

If you prefer a fine-grained PAT, grant this repository:

- **Contents:** Read and write
- **Metadata:** Read

---

## Apply an update

1. Sign in as an administrator and open **Admin → App updates**.
2. Confirm **GitHub repo** is `zafrullakhan001/Risk-Assesment` (or your fork) and **Track branch** is `main`.
3. Paste the PAT if the badge says **token needed** → Save settings.
4. Click **Check for updates**.
5. Select a commit (newest is selected by default) → **Update via git**.
6. When it finishes, reload with **Ctrl+F5**.

Nothing is applied until you confirm **Update via git**.

The apply step runs:

```text
git fetch --tags --force origin
git fetch origin <track-branch>
git checkout --force <commit>
```

Local uncommitted files are overwritten. The SQLite database and `uploads/` stay in place (they are not in git).

If `composer.json` / `composer.lock` changed, the updater also runs `composer install --no-dev` when Composer is available.

---

## Settings

| Setting | Default | Notes |
|---------|---------|--------|
| GitHub repo | auto from `git remote origin`, else `zafrullakhan001/Risk-Assesment` | `owner/name` |
| Track branch | `main` | Branch to compare and apply |
| GitHub token | _(empty)_ | Encrypted in `app_settings`. Leave blank on save to keep the current token. |

Optional server environment fallback (used only when no token is saved): `GITHUB_TOKEN` or `GH_TOKEN`.

---

## Security

- The PAT is encrypted at rest (AES-256-GCM) in SQLite `app_settings`.
- The token is injected into git per command (`Authorization: Basic`) and is **not** written to `.git/config`.
- This page is limited to administrators. Forgot the admin password? Run `php bin/reset_admin_password.php admin YourNewPassword!1`.
- Encryption key: `database/.encryption_key` (not in git). Keep it with the database if you copy the install.

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `GitHub returned 404` | Create a `repo`-scoped [classic PAT](https://github.com/settings/tokens/new?scopes=repo&description=Risk%20Assessment%20Updater) and save it |
| GitHub 401 | Token expired or revoked — generate a new one and save it again |
| `git fetch failed` | Confirm Git for Windows is installed and Apache can see `git.exe` |
| Dubious ownership | The updater sets `safe.directory` for this folder |
| Update already in progress | Wait a few seconds and retry; delete `database/updater.lock` only if Apache was killed mid-update |
| Forgot admin password | `php bin/reset_admin_password.php admin YourNewPassword!1` |
