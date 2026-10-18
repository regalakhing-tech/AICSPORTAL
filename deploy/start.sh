#!/bin/sh
# Railway mounts its volume at Applicants/uploads. Private files, admin backups and
# simulated emails are kept on the same volume so they survive redeploys.
set -e
APP=/var/www/html/AICS/AICS
DATA=$APP/Applicants/uploads/.private
mkdir -p "$DATA/backups" "$DATA/emails" "$APP/storage"
ln -sfn "$DATA/backups" "$APP/ADMIN/backups"
ln -sfn "$DATA/emails" "$APP/storage/emails"

# First start only: create the AICS tables in an empty database.
php "$APP/deploy/import-schema.php" --if-empty || echo "Database setup failed (see error above); starting the website anyway."

# One-time migration from a local XAMPP install, bundled into a single deploy upload
# (never committed): copy files without overwriting, then import the database once.
MIGRATE=$APP/deploy/migrate
if [ -f "$MIGRATE/aicsdb.sql" ]; then
    SUM=$(sha256sum "$MIGRATE/aicsdb.sql" | cut -d' ' -f1)
    if grep -qx "$SUM" "$DATA/migrations.done" 2>/dev/null; then
        echo "Migration $SUM already imported; skipped."
    else
        cp -rn "$MIGRATE/uploads/." "$APP/Applicants/uploads/" || echo "Migration: copying uploads failed."
        cp -rn "$MIGRATE/private/." "$DATA/" || echo "Migration: copying private files failed."
        php "$APP/deploy/import-schema.php" "$MIGRATE/aicsdb.sql" && echo "$SUM" >> "$DATA/migrations.done"
    fi
fi
chown -R www-data:www-data "$APP/Applicants/uploads"

# mod_php needs the prefork MPM only; remove any other MPM before Apache starts.
echo "MPMs found: $(ls /etc/apache2/mods-enabled | grep '^mpm_' | tr '\n' ' ')"
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
exec apache2-foreground
