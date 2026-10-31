#!/usr/bin/env bash
# Guarded recovery of exactly the six existing runtime files from the verified backup.
set -Eeuo pipefail
umask 077
DOCROOT='/home/u618446170/domains/mrandmrsprintflow.com/public_html'
BACKUP='/home/u618446170/.printflow-smart-consumption-backups/20261030T015542Z-1813591'
LOCK_DIR='/home/u618446170/.printflow-smart-consumption-staging'
LOCK_FILE="$LOCK_DIR/.release-rollback.lock"
CHANGED='none'
TEMP_PATH=''
fail() { printf 'ROLLBACK_STOP: %s\n' "$*" >&2; exit 1; }
sha256_file() { sha256sum -- "$1" | awk '{print tolower($1)}'; }
baseline_hash() {
  case "$1" in
    admin/job_orders_api.php) echo 8cbed8a03629f450f15da48a0839d26d718fa2eeead8a34228352cce60255051 ;;
    includes/JobOrderService.php) echo c243fe25011907b5c7e13448946780a62008a2807c886cb5ee31fab1f0e374d9 ;;
    includes/production_material_compatibility.php) echo 07582118761a559b69b5a0893154a7b7f588d69fdbc69782892766ac7ed4fedc ;;
    public/assets/js/material_consumption_estimator.js) echo 9a9316eb5d85dbb8bad10729fbee06d7bfb4b5fce0d99a1004fdbb0a7696f03c ;;
    public/assets/js/production_material_picker.js) echo e0efe76194847562cef23020f63687f65aa2f3962c269a16e0da50f846d77f5f ;;
    staff/customizations.php) echo 96d126c4f1d41d13bc618714deeddc712e3cd7e1e4248b61c067948a00f06330 ;;
    *) return 2 ;;
  esac
}
candidate_hash() {
  case "$1" in
    admin/job_orders_api.php) echo 11fbf6c63c1ff7ebb6fa836c593a4bdbc5c383ef91a22aa975b28d69c3367a61 ;;
    includes/JobOrderService.php) echo 01a08ba004b5f419731254fa93dfc8d3b5b10943de398e819a45a03f0c4e3b6b ;;
    includes/production_material_compatibility.php) echo b5e8ce0a8e0c34befa5e1e03e9bfc25b0f8113d260aad78fd49977c87d910d69 ;;
    includes/inventory_material_usage_confirmation.php) echo b60629c67afb3d18445fd9a151a783bb2a957ead03efe5cc1928e93616d71608 ;;
    public/assets/js/material_consumption_estimator.js) echo 34c260dda931e86a1bd27d97e252ff4079af11fe521480ceac49bd5e4c170794 ;;
    public/assets/js/production_material_picker.js) echo 6dac788ff08fa42f7e082cbc1d91fb1b627a021385d0e0d323cf9a9591c42365 ;;
    staff/customizations.php) echo 621a422002c18353b0f2a7b4c39a152e3c324f32f02bf58bef13deb0b58e6a7c ;;
    *) return 2 ;;
  esac
}
approved_six='admin/job_orders_api.php
includes/JobOrderService.php
includes/production_material_compatibility.php
public/assets/js/material_consumption_estimator.js
public/assets/js/production_material_picker.js
staff/customizations.php'

# Shared exclusive lock prevents the release and rollback scripts from running concurrently.
# SSH session markers replace unavailable process ancestry; process scan remains separate.
read_ps_record() { ps -p "$1" -o pid=,ppid=,uid=,args= 2>/dev/null; }
verify_interactive_ssh() {
  local client_addr client_port server_addr server_port extra=''
  [[ -t 0 && -r /dev/tty && -n "${SSH_TTY:-}" && -n "${SSH_CONNECTION:-}" ]] || fail 'interactive SSH TTY markers are missing'
  read -r client_addr client_port server_addr server_port extra <<< "$SSH_CONNECTION" || fail 'malformed SSH_CONNECTION'
  [[ -n "$client_addr" && "$client_port" =~ ^[0-9]+$ && -n "$server_addr" && "$server_port" == 65002 && -z "$extra" ]] || fail 'SSH session does not match the approved port/session format'
}
acquire_release_lock() {
  command -v flock >/dev/null 2>&1 || fail 'flock is unavailable; refusing concurrent release/rollback'
  [[ -d "$LOCK_DIR" && ! -L "$LOCK_DIR" ]] || fail 'private lock directory missing or symlinked'
  [[ "$(stat -c '%a|%u' -- "$LOCK_DIR")" == "700|$EUID" ]] || fail 'private lock directory mode/ownership mismatch'
  [[ ! -L "$LOCK_FILE" ]] || fail 'release/rollback lock file is symlinked'
  exec 9>> "$LOCK_FILE" || fail 'cannot open release/rollback lock file'
  [[ -f "$LOCK_FILE" && ! -L "$LOCK_FILE" ]] || fail 'release/rollback lock is not a regular file'
  [[ "$(stat -c '%a|%u|%h' -- "$LOCK_FILE")" == "600|$EUID|1" ]] || fail 'release/rollback lock mode/ownership/link-count mismatch'
  flock -n 9 || fail 'another Smart Consumption release or rollback is already running'
  printf 'RELEASE_LOCK_PASS path=%s\n' "$LOCK_FILE"
}
scan_competing_processes() {
  local pid line record rp rpp ru rc proc uid cmd arg low exe visible_count=0 self_seen=0
  for proc in /proc/[0-9]*; do
    [[ -d "$proc" ]] || continue
    ((visible_count+=1))
    pid="${proc##*/}"
    [[ "$pid" =~ ^[0-9]+$ ]] || { echo 'Cannot identify a visible process.' >&2; return 2; }
    if [[ "$pid" == "$BASHPID" ]]; then self_seen=1; continue; fi
    uid=''
    if [[ -r "$proc/status" ]]; then
      while IFS= read -r line; do if [[ "$line" == Uid:* ]]; then read -r _ uid _ _ <<< "$line"; break; fi; done < "$proc/status"
    fi
    [[ "$uid" =~ ^[0-9]+$ && "$uid" != "$EUID" ]] && continue
    cmd=''
    if [[ -r "$proc/cmdline" ]]; then while IFS= read -r -d '' arg; do cmd="$cmd $arg"; done < "$proc/cmdline" || :; fi
    if [[ ! "$uid" =~ ^[0-9]+$ || -z "$cmd" ]]; then
      record="$(read_ps_record "$pid")" || { [[ -d "$proc" ]] || continue; echo 'Cannot inspect visible process.' >&2; return 2; }
      rp=''; rpp=''; ru=''; rc=''; read -r rp rpp ru rc <<< "$record" || :
      [[ "$rp" == "$pid" && "$ru" =~ ^[0-9]+$ ]] || { [[ -d "$proc" ]] || continue; echo 'Cannot inspect visible process.' >&2; return 2; }
      uid="$ru"; [[ -n "$cmd" ]] || cmd="$rc"
    fi
    [[ "$uid" == "$EUID" ]] || continue
    [[ -n "$cmd" ]] || { echo 'Cannot inspect same-account process.' >&2; return 2; }
    low="$(printf '%s' "$cmd" | tr '[:upper:]' '[:lower:]')"
    exe="$(printf '%s\n' "$low" | awk '{print $1}')"; exe="${exe##*/}"
    case "$exe" in
      git|git-*|composer|rsync|scp|sftp|sftp-server) echo "Competing process detected: $exe" >&2; return 1 ;;
      bash|sh|dash|php)
        case "$low" in *composer*install*|*composer*update*|*deploy*.sh*|*deployment*.sh*|*hostinger*.sh*|*deploy*.php*) echo "Competing process detected: $exe script" >&2; return 1 ;; esac ;;
    esac
  done
  (( visible_count > 0 && self_seen == 1 )) || { echo 'Cannot confirm this shell is visible in the process view.' >&2; return 2; }
  return 0
}

[[ "$(pwd -P)" == '/home/u618446170' ]] || fail 'run from the verified Hostinger account home'
[[ -d "$DOCROOT" && "$(realpath -e -- "$DOCROOT")" == "$DOCROOT" ]] || fail 'document root mismatch'
[[ -d "$BACKUP" && ! -L "$BACKUP" && "$(stat -c '%a' -- "$BACKUP")" == 700 && "$(stat -c '%u' -- "$BACKUP")" == "$(id -u)" ]] || fail 'verified backup missing or not private/account-owned'
actual_backup_paths="$(find "$BACKUP" -type f -printf '%P\n' | sort)"
expected_backup_paths="$(printf '%s\n' "$approved_six" | sort)"
[[ "$actual_backup_paths" == "$expected_backup_paths" ]] || fail 'recovery backup does not contain exactly the six approved files'
[[ -z "$(find "$BACKUP" -type l -print -quit)" ]] || fail 'symlink found in recovery backup'
current_owner="$(id -u):$(id -g)"
helper="$DOCROOT/includes/inventory_material_usage_confirmation.php"
helper_hash="$(candidate_hash includes/inventory_material_usage_confirmation.php)"

# Preflight every target before any restore. Only baseline/candidate states are accepted.
while IFS= read -r path; do
  target="$DOCROOT/$path"; saved="$BACKUP/$path"
  [[ -f "$target" && ! -L "$target" && -f "$saved" && ! -L "$saved" ]] || fail "live or backup file missing/symlinked: $path"
  base="$(baseline_hash "$path")"; candidate="$(candidate_hash "$path")"
  saved_hash="$(sha256_file "$saved")"; target_hash="$(sha256_file "$target")"
  [[ "$saved_hash" == "$base" ]] || fail "backup hash mismatch: $path"
  [[ "$target_hash" == "$base" || "$target_hash" == "$candidate" ]] || fail "unexpected live bytes; refusing rollback: $path"
  saved_meta="$(stat -c '%a|%u:%g|%Y' -- "$saved")"
  [[ "$saved_meta" == "644|$current_owner|"* ]] || fail "backup metadata/owner mismatch: $path"
  target_mode_owner="$(stat -c '%a|%u:%g' -- "$target")"
  [[ "$target_mode_owner" == "644|$current_owner" ]] || fail "live target mode/owner mismatch: $path"
  [[ -d "$(dirname "$target")" && ! -L "$(dirname "$target")" ]] || fail "target directory missing/symlinked: $path"
  printf 'ROLLBACK_PREFLIGHT_PASS %s live_sha256=%s backup_sha256=%s metadata=%s\n' "$path" "$target_hash" "$saved_hash" "$saved_meta"
done <<< "$approved_six"
if [[ -e "$helper" || -L "$helper" ]]; then
  [[ -f "$helper" && ! -L "$helper" && "$(sha256_file "$helper")" == "$helper_hash" ]] || fail 'helper differs from approved deployed hash; refusing removal'
  printf 'ROLLBACK_HELPER_PASS sha256=%s\n' "$helper_hash"
else
  echo 'ROLLBACK_HELPER_ALREADY_ABSENT'
fi

verify_interactive_ssh
acquire_release_lock
echo 'Rollback also requires a protected window: auto-deploy held and queue drained.'
read -r -p 'After supported confirmation, type HOSTINGER_HOLD_AND_QUEUE_DRAINED: ' hold_ack </dev/tty
[[ "$hold_ack" == HOSTINGER_HOLD_AND_QUEUE_DRAINED ]] || fail 'deployment hold/queue confirmation missing'
scan_competing_processes || fail 'visible competing-process scan failed or found a conflict'
read -r -p 'This restores six backed-up files and removes the approved helper only. Type ROLLBACK_SMART_CONSUMPTION_7_FILES: ' ack </dev/tty
[[ "$ack" == ROLLBACK_SMART_CONSUMPTION_7_FILES ]] || fail 'rollback approval missing'

on_exit() {
  rc=$?
  [[ -n "$TEMP_PATH" && -e "$TEMP_PATH" ]] && rm -f -- "$TEMP_PATH"
  if (( rc != 0 )); then printf 'ROLLBACK_FAILED_OR_PARTIAL changed_paths=%s\n' "$CHANGED" >&2; fi
}
trap on_exit EXIT
restore_one() {
  path="$1"; target="$DOCROOT/$path"; saved="$BACKUP/$path"
  base="$(baseline_hash "$path")"; candidate="$(candidate_hash "$path")"
  current="$(sha256_file "$target")"
  if [[ "$current" == "$base" ]]; then
    printf 'ROLLBACK_FILE_ALREADY_BASELINE %s sha256=%s\n' "$path" "$base"
    return
  fi
  [[ "$current" == "$candidate" ]] || fail "immediate rollback hash guard failed: $path"
  dir="$(dirname -- "$target")"; TEMP_PATH="$(mktemp "$dir/.smc-restore-$(basename -- "$path").XXXXXX")"
  cp -p -- "$saved" "$TEMP_PATH"
  [[ "$(sha256_file "$TEMP_PATH")" == "$base" ]] || fail "temporary restore hash mismatch: $path"
  [[ "$(stat -c '%a|%u:%g|%Y' -- "$TEMP_PATH")" == "$(stat -c '%a|%u:%g|%Y' -- "$saved")" ]] || fail "temporary restore metadata mismatch: $path"
  scan_competing_processes || fail 'visible competing-process scan failed or a conflict appeared'
  [[ "$(sha256_file "$target")" == "$candidate" ]] || fail "final pre-restore hash guard failed: $path"
  mv -f -- "$TEMP_PATH" "$target"; TEMP_PATH=''
  if [[ "$CHANGED" == none ]]; then CHANGED="$path"; else CHANGED="$CHANGED $path"; fi
  [[ "$(sha256_file "$target")" == "$base" ]] || fail "restored hash mismatch: $path"
  [[ "$(stat -c '%a|%u:%g|%Y' -- "$target")" == "$(stat -c '%a|%u:%g|%Y' -- "$saved")" ]] || fail "restored metadata mismatch: $path"
  printf 'ROLLBACK_FILE_PASS %s sha256=%s metadata=%s\n' "$path" "$base" "$(stat -c '%a|%u:%g|%Y' -- "$target")"
}
while IFS= read -r path; do restore_one "$path"; done <<< "$approved_six"

if [[ -e "$helper" || -L "$helper" ]]; then
  [[ -f "$helper" && ! -L "$helper" && "$(sha256_file "$helper")" == "$helper_hash" ]] || fail 'helper changed before guarded removal'
  scan_competing_processes || fail 'visible competing-process scan failed or a conflict appeared'
  rm -- "$helper"
  if [[ "$CHANGED" == none ]]; then CHANGED='includes/inventory_material_usage_confirmation.php'; else CHANGED="$CHANGED includes/inventory_material_usage_confirmation.php"; fi
  echo 'ROLLBACK_HELPER_REMOVED approved_hash_matched'
fi
while IFS= read -r path; do
  [[ "$(sha256_file "$DOCROOT/$path")" == "$(baseline_hash "$path")" ]] || fail "final baseline hash mismatch: $path"
  [[ "$(stat -c '%a|%u:%g|%Y' -- "$DOCROOT/$path")" == "$(stat -c '%a|%u:%g|%Y' -- "$BACKUP/$path")" ]] || fail "final metadata mismatch: $path"
done <<< "$approved_six"
[[ ! -e "$helper" && ! -L "$helper" ]] || fail 'approved helper remains after rollback'
printf 'ROLLBACK_RESULT=PASS restored_six_files helper_absent changed_paths=%s\n' "$CHANGED"

