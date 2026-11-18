#!/bin/sh
set -eu
umask 077
export ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php
cd /srv/adelpos-license/app
stamp=$(date -u +%Y%m%dT%H%M%SZ)
destination=/srv/adelpos-license/backup/archives
php8.3 bin/cleanup.php
# Cambios de plan programados cuyo instante ya llegó (reducciones al vencer lo pagado).
php8.3 bin/apply-scheduled-changes.php
# Exclusive filenames; no retention deletion or remote transfer is performed here.
set -C
php8.3 bin/audit-verify.php > "$destination/$stamp.anchor.json"
php8.3 bin/backup.php --action=create --key-file=/srv/adelpos-license/backup/recovery.key --output="$destination/$stamp.adelposbackup" > "$destination/$stamp.receipt.json"
