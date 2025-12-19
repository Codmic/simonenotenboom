simonenotenboom/admin/README.md#L1-240
# Admin Editor — README

This admin tool provides a simple web-based editor to let non-HTML users edit the visible content of `index.html` and manage timestamped backups. It is intentionally lightweight and designed to be placed inside your project at `simonenotenboom/admin/`.

Overview
- UI: `simonenotenboom/admin/index.php` — login page, WYSIWYG editor, backup list and restore controls.
- Edits: Only the content between `<body>` and `</body>` of `simonenotenboom/index.html` is replaced on save.
- Backups: Stored in `simonenotenboom/admin/backups/` as `index_YYYYmmdd_HHMMSS.html`.
- Password: Stored as a password hash at `simonenotenboom/admin/pass.hash`. On first run a default password is created.

Quick start (local or server)
1. Ensure PHP is available on the host (PHP 7.2+ recommended).
2. Place the `admin` folder in the site root (already created at `simonenotenboom/admin/`).
3. Make sure `index.html` exists at `simonenotenboom/index.html`.
4. Ensure the webserver user can write to:
   - `simonenotenboom/index.html` (for saves)
   - `simonenotenboom/admin/backups/` (for backups)
   - `simonenotenboom/admin/pass.hash` (for password updates)
5. Open the admin UI in your browser:
   - Example: `https://your-host/path-to-project/admin/index.php`
6. Login:
   - First run default password: `admin`
   - After login, immediately change the password from the "Change password" form.

How the editor works
- Visual editor: It uses a browser `iframe` set to design mode so you can edit text and basic formatting using toolbar buttons (bold, italic, lists, headings, links).
- Save flow:
  1. When you click save the current `index.html` is copied into the backups folder as `index_YYYYmmdd_HHMMSS.html`.
  2. The tool replaces the contents of the `<body>` element in `index.html` with the content of the editor's body and writes the file back to disk.
- Restore flow:
  1. Selecting a backup and clicking restore will first create a new backup of the current `index.html`.
  2. The selected backup is copied over `index.html`.
- Note: The tool preserves `<head>` and other structural parts of the page — only `<body>` is replaced.

Files and locations
- Admin UI: `simonenotenboom/admin/index.php`
- Backups directory: `simonenotenboom/admin/backups/`
- Password hash: `simonenotenboom/admin/pass.hash`
- Site file edited: `simonenotenboom/index.html`

Security recommendations (must-do for production)
1. Serve the admin interface only over HTTPS.
2. Restrict access to the `admin` directory:
   - Use webserver access control to limit to specific IPs if appropriate.
   - Or protect via additional HTTP auth (Basic auth) at the webserver level.
3. Strong password:
   - Change the default `admin` password immediately.
   - Use a long, random password.
4. File permissions:
   - Ensure `pass.hash` and backups are not world-readable if you can avoid it.
   - The webserver user should be the only user able to write to `index.html` and the backups folder.
5. Audit & backups:
   - Keep off-site copies of backups if you need long-term archival.
   - Consider adding logging/audit trails for saves and restores.
6. Rate-limiting & lockout:
   - For public-facing servers, add rate-limiting or lockout on repeated failed login attempts.
7. Harden PHP:
   - Disable dangerous PHP functions if not needed (based on your security policy).
   - Keep PHP up-to-date.

Limitations and caveats
- This tool is not a full CMS. It focuses on editing the visible `<body>` content and simple formatting.
- If your `index.html` contains inline scripts or dynamic fragments inside `<body>` that must remain unchanged, be cautious — the editor will replace the entire `<body>` content.
- The editor uses `execCommand`/`designMode` features that vary in behavior between browsers. Test edits in the browsers your users will use.
- There is no multi-user audit history embedded. Backups are the primary way to recover previous versions.

Troubleshooting
- Blank page after save:
  - Check file permissions on `index.html`. The webserver user must be able to write to it.
  - Verify a backup was created in `admin/backups/` — if not, check write permissions there.
- Logins failing:
  - If you forgot the password, you can replace `pass.hash` with a new bcrypt hash (generate locally with `password_hash()` in PHP or use a script). Alternatively, remove `pass.hash` and the system will recreate a default hashed password only if code supports that — but be careful and follow your security policy.
- PHP errors:
  - Enable display or check server logs for details. Ensure `session_start()` can create session files and the session directory is writable.

Suggested improvements (future)
- Add username support and an audit log (who changed what and when).
- Add role-based access control and stronger authentication (2FA or integration with existing user management).
- Add a preview staging area: write to a preview file first and allow review before replacing live `index.html`.
- Offer a “diff” viewer between versions for easier restores.
- Add file integrity checks (signatures or checksums) to backups.

Contact / next steps
If you want, I can:
- add a simple audit log,
- add HTTP Basic wrapper instructions for `nginx` / `apache`,
- convert the editor to also allow editing of small header snippets safely,
- or implement an admin user management list.

Remember: before using the tool on a production site, follow the security recommendations above.
