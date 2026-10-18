#!/bin/sh
# Railway mounts its volume at Applicants/uploads. Private files, admin backups and
# simulated emails are kept on the same volume so they survive redeploys.
set -e
APP=/var/www/html/AICS/AICS
DATA=$APP/Applicants/uploads/.private
mkdir -p "$DATA/backups" "$DATA/emails" "$APP/storage"
ln -sfn "$DATA/backups" "$APP/ADMIN/backups"
ln -sfn "$DATA/emails" "$APP/storage/emails"
chown www-data:www-data "$APP/Applicants/uploads" "$DATA" "$DATA/backups" "$DATA/emails"

# First start only: create the AICS tables in an empty database.
php "$APP/deploy/import-schema.php" --if-empty || echo "Database setup failed (see error above); starting the website anyway."

# mod_php needs the prefork MPM only; remove any other MPM before Apache starts.
echo "MPMs found: $(ls /etc/apache2/mods-enabled | grep '^mpm_' | tr '\n' ' ')"
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
exec apache2-foreground
