#!/bin/sh
set -eu
umask 077
export ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php
cd /srv/adelpos-license/current
stamp=$(date -u +%Y%m%dT%H%M%SZ)
destination=/srv/adelpos-license/backup/archives
php bin/cleanup.php
# Exclusive filenames; no retention deletion or remote transfer is performed here.
set -C
php bin/audit-verify.php > "$destination/$stamp.anchor.json"
php bin/backup.php --action=create --key-file=/srv/adelpos-license/backup/recovery.key --output="$destination/$stamp.adelposbackup" > "$destination/$stamp.receipt.json"
