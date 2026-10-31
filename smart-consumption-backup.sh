#!/usr/bin/env bash
# Create and verify a private backup of the six existing Smart Consumption files.
# This script never replaces or edits production runtime files.
set -u
umask 077

ROOT='/home/u618446170/domains/mrandmrsprintflow.com/public_html'
EXPECTED_HOME='/home/u618446170'
paths=(
  'admin/job_orders_api.php'
  'includes/JobOrderService.php'
  'includes/production_material_compatibility.php'
  'public/assets/js/material_consumption_estimator.js'
  'public/assets/js/production_material_picker.js'
  'staff/customizations.php'
)
expected=(
  '8cbed8a03629f450f15da48a0839d26d718fa2eeead8a34228352cce60255051'
  'c243fe25011907b5c7e13448946780a62008a2807c886cb5ee31fab1f0e374d9'
  '07582118761a559b69b5a0893154a7b7f588d69fdbc69782892766ac7ed4fedc'
  '9a9316eb5d85dbb8bad10729fbee06d7bfb4b5fce0d99a1004fdbb0a7696f03c'
  'e0efe76194847562cef23020f63687f65aa2f3962c269a16e0da50f846d77f5f'
  '96d126c4f1d41d13bc618714deeddc712e3cd7e1e4248b61c067948a00f06330'
)

live_status=()
live_hashes=()
live_meta=()
backup_status=()
failed=0

home_real=$(cd -- "$HOME" 2>/dev/null && pwd -P) || home_real=''
if [[ "$home_real" != "$EXPECTED_HOME" ]]; then
  printf 'BACKUP_RESULT=FAIL reason=unexpected_home resolved=%s\n' "${home_real:-unavailable}"
  exit 20
fi
if ! cd -- "$ROOT"; then
  printf 'BACKUP_RESULT=FAIL reason=document_root_unavailable\n'
  exit 21
fi
if [[ "$(pwd -P)" != "$ROOT" ]]; then
  printf 'BACKUP_RESULT=FAIL reason=document_root_mismatch actual=%s\n' "$(pwd -P)"
  exit 22
fi

# Verify all six live hashes and 0644 modes before creating the backup.
for i in "${!paths[@]}"; do
  p=${paths[$i]}
  if [[ ! -f "$p" || -L "$p" ]]; then
    live_status[$i]='FAIL'
    live_hashes[$i]='MISSING_OR_NOT_REGULAR'
    live_meta[$i]='unavailable'
    failed=1
  else
    h=$(sha256sum -- "$p" | awk '{print tolower($1)}') || h='HASH_ERROR'
    m=$(stat -c '%a|%u|%g|%x|%y' -- "$p" 2>/dev/null) || m='STAT_ERROR'
    live_hashes[$i]=$h
    live_meta[$i]=$m
    if [[ "$h" == "${expected[$i]}" && "$m" == 644\|* ]]; then
      live_status[$i]='PASS'
    else
      live_status[$i]='FAIL'
      failed=1
    fi
  fi
  printf 'LIVE_BASELINE path=%s sha256=%s mode_uid_gid_atime_mtime=%s result=%s\n' \
    "$p" "${live_hashes[$i]}" "${live_meta[$i]}" "${live_status[$i]}"
done

if (( failed )); then
  for i in "${!paths[@]}"; do
    printf 'BACKUP_VERIFY path=%s result=NOT_RUN\n' "${paths[$i]}"
  done
  printf 'BACKUP_RESULT=FAIL reason=live_baseline_hash_or_mode_mismatch\n'
  exit 23
fi

BACKUP_PARENT="$HOME/.printflow-smart-consumption-backups"
if [[ -L "$BACKUP_PARENT" ]]; then
  printf 'BACKUP_RESULT=FAIL reason=backup_parent_is_symlink\n'
  exit 24
fi
if [[ ! -e "$BACKUP_PARENT" ]]; then
  if ! mkdir -m 700 -- "$BACKUP_PARENT"; then
    printf 'BACKUP_RESULT=FAIL reason=cannot_create_private_backup_parent\n'
    exit 25
  fi
fi
if [[ ! -d "$BACKUP_PARENT" ]] ||
   [[ "$(stat -c '%a' -- "$BACKUP_PARENT" 2>/dev/null)" != '700' ]] ||
   [[ "$(stat -c '%u' -- "$BACKUP_PARENT" 2>/dev/null)" != "$(id -u)" ]]; then
  printf 'BACKUP_RESULT=FAIL reason=backup_parent_not_owned_private_mode_0700\n'
  exit 26
fi

stamp=$(date -u '+%Y%m%dT%H%M%SZ')
BACKUP_DIR="$BACKUP_PARENT/${stamp}-$$"
if ! mkdir -m 700 -- "$BACKUP_DIR"; then
  printf 'BACKUP_RESULT=FAIL reason=cannot_create_unique_backup_directory\n'
  exit 27
fi
printf 'BACKUP_DIR=%s\n' "$BACKUP_DIR"

# Recheck each live file immediately before copying. cp -p preserves mode,
# ownership, and timestamps. Restore backup timestamps after hashing the copy.
for i in "${!paths[@]}"; do
  p=${paths[$i]}
  src="$ROOT/$p"
  dst="$BACKUP_DIR/$p"

  live_now=$(sha256sum -- "$src" | awk '{print tolower($1)}') || live_now='HASH_ERROR'
  live_now_meta=$(stat -c '%a|%u|%g|%x|%y' -- "$src" 2>/dev/null) || live_now_meta='STAT_ERROR'
  if [[ "$live_now" != "${expected[$i]}" || "$live_now_meta" != "${live_meta[$i]}" ]]; then
    backup_status[$i]='FAIL'
    printf 'BACKUP_VERIFY path=%s live_sha256=%s live_metadata=%s result=FAIL reason=live_file_changed_before_copy\n' \
      "$p" "$live_now" "$live_now_meta"
    break
  fi

  if ! mkdir -m 700 -p -- "$(dirname -- "$dst")"; then
    backup_status[$i]='FAIL'
    printf 'BACKUP_VERIFY path=%s result=FAIL reason=cannot_create_backup_subdirectory\n' "$p"
    break
  fi
  if ! cp -p -- "$src" "$dst"; then
    backup_status[$i]='FAIL'
    printf 'BACKUP_VERIFY path=%s result=FAIL reason=copy_failed\n' "$p"
    break
  fi

  backup_hash=$(sha256sum -- "$dst" | awk '{print tolower($1)}') || backup_hash='HASH_ERROR'
  if ! touch -r "$src" -- "$dst"; then
    backup_status[$i]='FAIL'
    printf 'BACKUP_VERIFY path=%s backup_sha256=%s result=FAIL reason=cannot_preserve_timestamps\n' \
      "$p" "$backup_hash"
    break
  fi
  backup_meta=$(stat -c '%a|%u|%g|%x|%y' -- "$dst" 2>/dev/null) || backup_meta='STAT_ERROR'

  if [[ "$backup_hash" == "${expected[$i]}" && "$backup_meta" == "${live_now_meta}" ]]; then
    backup_status[$i]='PASS'
    printf 'BACKUP_VERIFY path=%s live_sha256=%s backup_sha256=%s live_metadata=%s backup_metadata=%s result=PASS\n' \
      "$p" "$live_now" "$backup_hash" "$live_now_meta" "$backup_meta"
  else
    backup_status[$i]='FAIL'
    printf 'BACKUP_VERIFY path=%s live_sha256=%s backup_sha256=%s live_metadata=%s backup_metadata=%s result=FAIL\n' \
      "$p" "$live_now" "$backup_hash" "$live_now_meta" "$backup_meta"
    break
  fi
done

for i in "${!paths[@]}"; do
  result=${backup_status[$i]:-NOT_RUN}
  [[ "$result" == 'PASS' ]] || failed=1
  if [[ "$result" == 'NOT_RUN' ]]; then
    printf 'BACKUP_VERIFY path=%s result=NOT_RUN\n' "${paths[$i]}"
  fi
done

if (( failed )); then
  printf 'BACKUP_RESULT=FAIL reason=backup_incomplete_or_verification_failed\n'
  exit 28
fi

printf 'BACKUP_RESULT=PASS files=6 directory_mode=0700\n'
