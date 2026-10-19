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

# Migration from a local XAMPP install, sent as one or more deploy uploads (never
# committed): copy each part's files without overwriting, import the database once.
MIGRATE=$APP/deploy/migrate
if [ -d "$MIGRATE/uploads" ]; then
    cp -rn "$MIGRATE/uploads/." "$APP/Applicants/uploads/" || echo "Migration: copying uploads failed."
fi
if [ -d "$MIGRATE/private" ]; then
    cp -rn "$MIGRATE/private/." "$DATA/" || echo "Migration: copying private files failed."
fi
if [ -f "$MIGRATE/aicsdb.sql" ]; then
    SUM=$(sha256sum "$MIGRATE/aicsdb.sql" | cut -d' ' -f1)
    if grep -qx "$SUM" "$DATA/migrations.done" 2>/dev/null; then
        echo "Migration $SUM already imported; skipped."
    else
        php "$APP/deploy/import-schema.php" "$MIGRATE/aicsdb.sql" && echo "$SUM" >> "$DATA/migrations.done"
    fi
fi
# One-time maintenance script sent with a deploy upload (never committed): runs
# once per content, like the database import above.
if [ -f "$MIGRATE/maintenance.php" ]; then
    SUM=$(sha256sum "$MIGRATE/maintenance.php" | cut -d' ' -f1)
    if grep -qx "$SUM" "$DATA/migrations.done" 2>/dev/null; then
        echo "Maintenance $SUM already ran; skipped."
    else
        php "$MIGRATE/maintenance.php" && echo "$SUM" >> "$DATA/migrations.done"
    fi
fi
if [ -d "$MIGRATE" ]; then
    echo "Migration part copied: $(find "$MIGRATE" -type f | wc -l) files."
fi
chown -R www-data:www-data "$APP/Applicants/uploads"

# AICS AI gate: add its columns, log AI health, then start, retry and decide AI
# checks every minute (as www-data, like the web server and its AI workers).
php "$APP/tools/ai-gate-sync.php" --schema-only || echo "AICS AI gate schema setup failed."
runuser -u www-data -- /usr/local/bin/php "$APP/tools/ai-gate-sync.php" --health || echo "AICS AI health check failed."
(
    set +e
    while true; do
        runuser -u www-data -- /usr/local/bin/php "$APP/tools/ai-gate-sync.php" --quiet
        sleep 60
    done
) &
# Smart Priority (AICS AI medical urgency) runs here, in the background, so PSWDO
# pages never wait for it.
(
    set +e
    while true; do
        sleep 120
        runuser -u www-data -- /usr/local/bin/php "$APP/tools/smart-priority-refresh.php" --quiet
    done
) &

# mod_php needs the prefork MPM only; remove any other MPM before Apache starts.
echo "MPMs found: $(ls /etc/apache2/mods-enabled | grep '^mpm_' | tr '\n' ' ')"
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
exec apache2-foreground
