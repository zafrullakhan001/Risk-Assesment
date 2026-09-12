# App Updates (GitHub Releases)

This install checks GitHub for a newer **Release** and applies it by downloading a zip. **Git is not required** on the server. Database files, `uploads/`, and custom branding stay in place.

Open **Admin → App updates**, or `public/admin/updates.php`.

**Who:** administrators only.

---

## Publish a release that the updater can apply

GitHub’s automatic “Source code (zip)” is not a git checkout and does not include `vendor/`. Package the app first:

```text
php bin/package_release.php v1.1.0
```

That writes `dist/RiskRegister-v1.1.0.zip` (application files + `vendor/` when present, plus `VERSION.json` and `README.txt`). Upload that file as a GitHub Release asset.

If GitHub Actions is enabled, creating a Release also runs `.github/workflows/release.yml`, which builds the same zip and attaches it to the Release.

---

## Enable a GitHub Personal Access Token

Private repositories need a **classic PAT** with the **`repo`** scope. A token is also recommended for public repos (higher API rate limits).

1. Sign in to GitHub.
2. Open [Create a classic PAT](https://github.com/settings/tokens/new?scopes=repo&description=Risk%20Assessment%20Updater).
3. Confirm **Note:** Risk Assessment Updater, **Expiration:** 90 days or longer, **Scopes:** `repo`.
4. Generate the token, copy it (`ghp_…`), then paste it under **Admin → App updates** → **Save settings**.

Fine-grained tokens need **Contents: Read** and **Metadata: Read** on this repository.

---

## Apply an update

1. Sign in as an administrator and open **Admin → App updates**.
2. Confirm **GitHub repo** is `zafrullakhan001/Risk-Assesment` (or your fork).
3. Paste the PAT if the badge says **token needed** → Save settings.
4. Click **Check for updates**.
5. Select a Release (newest is selected by default) → **Download and apply**.
6. Reload with **Ctrl+F5**.

The apply step downloads `RiskRegister-*.zip` from the Release when that asset exists. Otherwise it uses GitHub’s source zipball. PHP `curl` and `zip` must be enabled. Composer is only needed when `vendor/` is missing after extract.

If the repository has no newer Release, the page also lists commits on the track branch (and the current git branch, when this folder is a git checkout) that are after the installed version. Those apply via GitHub’s source zipball.

### Developer systems (already current via git)

On a local checkout where the code is already present, use **Mark as already installed** on Admin → App updates, or **Mark complete** on the header update bell. That updates `VERSION.json` without downloading a zip and clears the update badge until newer commits appear on GitHub.

---

## Settings

| Setting | Default | Notes |
|---------|---------|--------|
| GitHub repo | auto from `git remote origin` when Git exists, else `zafrullakhan001/Risk-Assesment` | `owner/name` |
| Track branch | `main` | Commits on this branch after the installed Release are offered as updates. On a git checkout, the current branch is also checked. |
| GitHub token | _(empty)_ | Encrypted in `app_settings`. Leave blank on save to keep the current token. |

Optional server environment fallback (used only when no token is saved): `GITHUB_TOKEN` or `GH_TOKEN`.

Installed version is stored in `VERSION.json` and in updater settings.

---

## Security

- The PAT is encrypted at rest (AES-256-GCM) in SQLite `app_settings`.
- This page is limited to administrators. Forgot the superadmin password? Run `php bin/reset_admin_password.php "YourNewPassword!1"` on the server (see Help → Forgot superadmin password).
- Encryption key: `database/.encryption_key` (not in git). Keep it with the database if you copy the install.
- Zip entries containing `..` are rejected.

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `GitHub returned 404` | Create a `repo`-scoped [classic PAT](https://github.com/settings/tokens/new?scopes=repo&description=Risk%20Assessment%20Updater) and save it |
| GitHub 401 | Token expired or revoked — generate a new one and save it again |
| PHP zip / curl missing | Enable `extension=zip` and `extension=curl` in `php.ini`, restart Apache |
| Browser shows garbled characters during apply | The download was leaking into the page; keep this updater build and retry. Keep the tab open until it redirects. |
| Update already in progress | Wait a few seconds and retry; delete `database/updater.lock` only if Apache was killed mid-update |
| Forgot superadmin password | `php bin/reset_admin_password.php "YourNewPassword!1"` (CLI on the server, not a web page) |
