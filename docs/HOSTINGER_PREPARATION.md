# Hosting preparation (Hostinger or similar) — not yet tested on a real host

Tested only on: Apache 2.4 + PHP 8.3 + MariaDB 10.11 (local), with the `.htaccess` rules of this project.

Option 1 (simplest): upload the whole `RP_Mulawin_FMS_V2` folder inside `public_html`. The `.htaccess` files block
`backend`, `config`, `database`, `docs`, `scripts`, `storage`, `tests`, `.env` and `.sql`. Open `https://your-domain/RP_Mulawin_FMS_V2/`.

Option 2 (better): upload the folder OUTSIDE `public_html` and set the domain document root to `RP_Mulawin_FMS_V2/public`
(if the plan allows changing the document root).

Steps: PHP 8.2+ with pdo_mysql, fileinfo, mbstring → create a NEW empty database and a database user → import
`database/schema.sql` then `database/extension.sql` → copy `.env.example` to `.env`, set `APP_ENV=production` and the
database values → create the first Admin (`php scripts/create_user.php admin <username> "<name>"` via SSH, or
`public/install.php` with a 32+ character INSTALL_TOKEN, then clear the token and delete install.php) → use HTTPS →
test every role on a staging copy first → schedule regular backups (Backup page) and copy `storage/private`.
