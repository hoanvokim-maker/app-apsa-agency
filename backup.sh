#!/bin/bash
# Backup source code + database cua app.apsa.agency len GitHub
set -uo pipefail
export SRC="$HOME/app.apsa.agency"
REPO="$HOME/apsa-backup"
export GIT_SSH_COMMAND="ssh -i $HOME/.ssh/apsa_backup_key -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"
cd "$REPO" || exit 1

# 1) Source code - bo qua kho asset nang va file cua khach
rm -rf source && mkdir -p source
tar -C "$SRC" --exclude='./Pharmaceutical Logos' --exclude='./Brand Guidlines' --exclude='./uploads' --exclude='./contract-gen' --exclude='./training' --exclude='./logs' --exclude='./.git' -cf - . | tar -C source -xf -

# 2) Database - moi ban ghi mot dong de git so sanh duoc
CNF="$(mktemp)"; chmod 600 "$CNF"
php -r 'require getenv("SRC")."/api/db-config.php"; printf("[client]\nhost=%s\nuser=%s\npassword=\"%s\"\n", DB_HOST, DB_USER, DB_PASS);' > "$CNF"
DBN="$(php -r 'require getenv("SRC")."/api/db-config.php"; echo DB_NAME;')"
mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --skip-extended-insert --skip-dump-date --routines --events "$DBN" > "database/$DBN.sql"
rm -f "$CNF"

# 3) Commit va day len, khong co thay doi thi thoi
git add -A
if git diff --cached --quiet; then echo "$(date '+%F %T') khong co thay doi"; exit 0; fi
git commit -q -m "Backup $(date '+%F %H:%M')"
git push -q origin main && echo "$(date '+%F %T') da day len GitHub"
