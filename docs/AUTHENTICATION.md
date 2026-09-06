# Authentication (local + LDAP)

This install uses the same sign-in model as LinkNest: **PHP sessions**, **local password accounts**, and optional **LDAP / Active Directory**.

Open **Admin** from the top bar after you sign in as an administrator.

---

## First-time setup

A default local administrator is created automatically:

- Username: `admin`
- Password: `admin123`

Change that password under **Admin → Overview** after the first sign-in. If no users exist yet, the first visitor can also create an administrator on `login.php`.

New local passwords (after the starter account) need at least 8 characters, one uppercase letter, one number, and one special character.

---

## Local accounts

Enabled by default in **Admin → Authentication**.

| Path | Behavior |
|------|----------|
| Admin creates a user | Approved immediately |
| Self-registration (optional) | Stays pending until an admin approves |
| Sign-in | `password_verify()` against `users.password_hash` |

Local users change their password in **Admin → Overview**. Admins can reset other local passwords on **Users**.

---

## LDAP / Active Directory

1. Enable **PHP LDAP** (`extension=ldap` in `php.ini`) and restart Apache.
2. Open **Admin → Authentication**.
3. Turn on **Enable LDAP / Active Directory**.
4. Enter the DC host, port, bind DN, bind password, user search base, and filter.
5. **Test connection**, then **Save LDAP server**.

Login flow (same as LinkNest):

1. Service account bind
2. Search (or optional user DN template)
3. Bind as the user to check the password
4. Optional required / denied group check
5. Auto-create or update the local `users` row (`auth_source = ldap`)

User passwords from the directory are **never stored**. The bind password is encrypted at rest (AES-256-GCM) in `app_settings`.

Default user filter: `(sAMAccountName={username})`.

**Auto** on the sign-in form tries LDAP first, then local. Usernames that contain `@localhost` skip LDAP.

On **Admin → Users**, administrators can search the directory and open **LDAP details** for a live profile: account status (enabled, disabled, locked, password flags), groups (`memberOf`), org/contact fields, timestamps, and every other attribute the service bind account can read. Passwords and credential secrets are never retrieved or stored. Inspection is audited as `user.ldap_inspected` (username/DN only).

---

## Admin section

| Page | Tasks |
|------|--------|
| `admin/index.php` | Overview, change your local password |
| `admin/users.php` | Create, approve, disable, promote, reset password, LDAP search/add/details, audit log |
| `admin/authentication.php` | Local / LDAP toggles and LDAP server |
| `admin/updates.php` | GitHub PAT and git updates |

The old `updates.php` URL redirects here. Only administrators can open these pages.

---

## Recovery

If you are locked out of the last admin account:

```text
php bin/reset_admin_password.php admin YourNewPassword!1
```

The script creates the user if needed, approves it, and grants admin.

---

## Security notes

- Isolated session cookie name and path for this install
- `HttpOnly` + `SameSite=Lax` cookies (`Secure` on HTTPS)
- Session ID regenerated on login
- CSRF tokens on every form
- Prepared statements for all user queries
- LDAP filter/DN values are escaped
- Five failed sign-ins lock the session for 60 seconds
- The last administrator cannot be demoted, disabled, or deleted
