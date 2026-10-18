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
exec apache2-foreground
