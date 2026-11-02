#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
RELEASE_ID='38b423f263f17df8a150418c323b2421644aa961'
EXPECTED_HEAD='ae7544cddefcae1f0a01208cf60d4e9786fd9b78'
DOCROOT='/home/u618446170/domains/mrandmrsprintflow.com/public_html'
BACKUP='/home/u618446170/.printflow-smart-consumption-backups/20261030T015542Z-1813591'
STAGE_ROOT='/home/u618446170/.printflow-smart-consumption-staging'
LOCK_DIR='/home/u618446170/.printflow-smart-consumption-staging'
LOCK_FILE="$LOCK_DIR/.release-rollback.lock"
STATUS_NAME='release-status-38b423f.txt'
CHANGED='none'
TEMP_PATH=''
PREFLIGHT_ONLY=0
fail() { printf 'RELEASE_STOP: %s\n' "$*" >&2; exit 1; }
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
approved_seven="$approved_six
includes/inventory_material_usage_confirmation.php"

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

[[ $# -ge 1 && $# -le 2 ]] || fail 'pass the exact STAGE_DIR printed by smart-consumption-stage.ps1, optionally followed by --preflight-only'
STAGE_INPUT="$1"
if [[ $# -eq 2 ]]; then
  [[ "$2" == --preflight-only ]] || fail 'the only supported optional argument is --preflight-only'
  PREFLIGHT_ONLY=1
fi
case "$STAGE_INPUT" in "$STAGE_ROOT"/candidate-38b423f263f17df8a150418c323b2421644aa961-*) ;; *) fail 'stage path outside approved private namespace';; esac
[[ -d "$STAGE_INPUT" && ! -L "$STAGE_INPUT" ]] || fail 'stage directory missing or symlinked'
STAGE="$(realpath -e -- "$STAGE_INPUT")"
[[ "$STAGE" == "$STAGE_INPUT" && "$(stat -c '%a' -- "$STAGE")" == 700 && "$(stat -c '%u' -- "$STAGE")" == "$(id -u)" ]] || fail 'stage directory not canonical/private/account-owned'
DOCROOT='/home/u618446170/domains/mrandmrsprintflow.com/public_html'
[[ -d "$DOCROOT" && "$(realpath -e -- "$DOCROOT")" == "$DOCROOT" ]] || fail 'document root mismatch'
[[ "$(git -C "$DOCROOT" rev-parse HEAD)" == "$EXPECTED_HEAD" && "$(git -C "$DOCROOT" branch --show-current)" == main ]] || fail 'production Git baseline mismatch'
actual_paths="$(find "$STAGE" -type f -printf '%P\n' | sort)"
expected_paths="$(printf '%s\n' "$approved_seven" | sort)"
[[ "$actual_paths" == "$expected_paths" ]] || fail 'stage does not contain exactly seven approved files'
[[ -z "$(find "$STAGE" -type l -print -quit)" ]] || fail 'symlink in candidate stage'
while IFS= read -r path; do
  got="$(sha256sum -- "$STAGE/$path" | awk '{print tolower($1)}')"; want="$(candidate_hash "$path")" || fail "unapproved path: $path"
  [[ "$got" == "$want" ]] || fail "staged SHA-256 mismatch: $path"
  printf 'STAGED_CANDIDATE_PASS %s sha256=%s\n' "$path" "$got"
done <<< "$approved_seven"

[[ -d "$BACKUP" && ! -L "$BACKUP" && "$(stat -c '%a' -- "$BACKUP")" == 700 && "$(stat -c '%u' -- "$BACKUP")" == "$(id -u)" ]] || fail 'fresh backup missing or not private/account-owned'
actual_backup_paths="$(find "$BACKUP" -type f -printf '%P\n' | sort)"
expected_backup_paths="$(printf '%s\n' "$approved_six" | sort)"
[[ "$actual_backup_paths" == "$expected_backup_paths" ]] || fail 'recovery backup does not contain exactly the six approved files'
[[ -z "$(find "$BACKUP" -type l -print -quit)" ]] || fail 'symlink found in recovery backup'
current_owner="$(id -u):$(id -g)"
while IFS= read -r path; do
  target="$DOCROOT/$path"; saved="$BACKUP/$path"; base="$(baseline_hash "$path")" || fail "unapproved path: $path"
  [[ -f "$target" && ! -L "$target" && -f "$saved" && ! -L "$saved" ]] || fail "live or backup file missing: $path"
  [[ "$(sha256sum -- "$target" | awk '{print tolower($1)}')" == "$base" ]] || fail "live baseline hash mismatch: $path"
  [[ "$(sha256sum -- "$saved" | awk '{print tolower($1)}')" == "$base" ]] || fail "fresh backup hash mismatch: $path"
  live_meta="$(stat -c '%a|%u:%g|%Y' -- "$target")"; backup_meta="$(stat -c '%a|%u:%g|%Y' -- "$saved")"
  [[ "$live_meta" == "$backup_meta" && "$live_meta" == "644|$current_owner|"* ]] || fail "backup metadata/ownership mismatch: $path"
  [[ -d "$(dirname "$target")" && ! -L "$(dirname "$target")" ]] || fail "target directory missing/symlinked: $path"
  [[ -z "$(git -C "$DOCROOT" status --short -- "$path")" ]] || fail "approved live path dirty in Git: $path"
  printf 'LIVE_AND_BACKUP_PASS %s sha256=%s metadata=%s\n' "$path" "$base" "$live_meta"
done <<< "$approved_six"
helper="$DOCROOT/includes/inventory_material_usage_confirmation.php"
[[ ! -e "$helper" && ! -L "$helper" ]] || fail 'approved helper already exists'
[[ "$(stat -c '%a' -- "$DOCROOT/includes")" == 755 ]] || fail 'includes directory mode mismatch'
composer_lock="$DOCROOT/composer.lock"
[[ -f "$composer_lock" && ! -L "$composer_lock" ]] || fail 'composer.lock missing/symlinked'
composer_before="$(sha256sum -- "$composer_lock" | awk '{print tolower($1)}')"
current_head="$(git -C "$DOCROOT" rev-parse HEAD)"

verify_interactive_ssh
acquire_release_lock
scan_competing_processes || fail 'visible competing-process scan failed or found a conflict'
if (( PREFLIGHT_ONLY )); then
  printf 'READ_ONLY_PREFLIGHT_RESULT=PASS files=7 backup_files=6 live_baseline=PASS helper_absent=PASS stage=%s backup=%s queue_status=NOT_VERIFIED production_writes=0\n' "$STAGE" "$BACKUP"
  exit 0
fi
echo 'Hostinger must independently hold auto-deployment and confirm queued/running work is drained.'
echo 'A setup URL or process scan is not proof. Stop if supported confirmation is unavailable.'
read -r -p 'After supported confirmation, type HOSTINGER_HOLD_AND_QUEUE_DRAINED: ' hold_ack </dev/tty
[[ "$hold_ack" == HOSTINGER_HOLD_AND_QUEUE_DRAINED ]] || fail 'deployment hold/queue confirmation missing'
read -r -p 'Final production-write approval: type DEPLOY_EXACTLY_7_APPROVED_FILES: ' approval </dev/tty
[[ "$approval" == DEPLOY_EXACTLY_7_APPROVED_FILES ]] || fail 'final production-write approval missing'

write_status() {
  state="$1"; detail="$2"
  printf 'release=%s\nstatus=%s\ngit_head=%s\ndetail=%s\nchanged_paths=%s\ntimestamp_utc=%s\n' "$RELEASE_ID" "$state" "$current_head" "$detail" "$CHANGED" "$(date -u +%FT%TZ)" > "$STAGE/$STATUS_NAME.tmp"
  chmod 600 "$STAGE/$STATUS_NAME.tmp"
  mv -f -- "$STAGE/$STATUS_NAME.tmp" "$STAGE/$STATUS_NAME"
}
on_exit() {
  rc=$?
  [[ -n "$TEMP_PATH" && -e "$TEMP_PATH" ]] && rm -f -- "$TEMP_PATH"
  if (( rc != 0 )); then
    if [[ -d "$STAGE" && -w "$STAGE" ]]; then write_status FAILED_OR_PARTIAL "exit=$rc; inspect hashes; guarded rollback if needed" || :; fi
    printf 'RELEASE_FAILED_OR_PARTIAL changed_paths=%s\n' "$CHANGED" >&2
  fi
}
trap on_exit EXIT
replace_one() {
  path="$1"; target="$DOCROOT/$path"; src="$STAGE/$path"; dir="$(dirname -- "$target")"; base="$(basename -- "$target")"
  [[ "$(stat -c '%u:%g' -- "$dir")" == "$current_owner" ]] || fail "directory owner mismatch: $dir"
  if [[ "$path" == includes/inventory_material_usage_confirmation.php ]]; then
    [[ ! -e "$target" && ! -L "$target" ]] || fail 'helper appeared before replacement'
    expected_before=ABSENT
  else
    expected_before="$(baseline_hash "$path")" || fail "unapproved path: $path"
    [[ "$(sha256sum -- "$target" | awk '{print tolower($1)}')" == "$expected_before" ]] || fail "immediate pre-replacement hash mismatch: $path"
  fi
  TEMP_PATH="$(mktemp "$dir/.smc-${base}.XXXXXX")"
  install -m 0644 -- "$src" "$TEMP_PATH"
  [[ "$(sha256sum -- "$TEMP_PATH" | awk '{print tolower($1)}')" == "$(candidate_hash "$path")" ]] || fail "temporary payload hash mismatch: $path"
  [[ "$(stat -c '%a|%u:%g' -- "$TEMP_PATH")" == "644|$current_owner" ]] || fail "temporary mode/owner mismatch: $path"
  scan_competing_processes || fail 'visible competing-process scan failed or a conflict appeared'
  if [[ "$expected_before" == ABSENT ]]; then
    [[ ! -e "$target" && ! -L "$target" ]] || fail 'helper appeared at final pre-rename guard'
    mv -n -- "$TEMP_PATH" "$target"
    [[ ! -e "$TEMP_PATH" ]] || fail 'helper creation refused because destination appeared'
  else
    [[ "$(sha256sum -- "$target" | awk '{print tolower($1)}')" == "$expected_before" ]] || fail "final pre-rename hash mismatch: $path"
    mv -f -- "$TEMP_PATH" "$target"
  fi
  TEMP_PATH=''
  if [[ "$CHANGED" == none ]]; then CHANGED="$path"; else CHANGED="$CHANGED $path"; fi
  [[ "$(sha256sum -- "$target" | awk '{print tolower($1)}')" == "$(candidate_hash "$path")" ]] || fail "read-back hash mismatch: $path"
  [[ "$(stat -c '%a|%u:%g' -- "$target")" == "644|$current_owner" ]] || fail "live metadata mismatch: $path"
  printf 'DEPLOY_FILE_PASS %s sha256=%s mode=644 owner=%s\n' "$path" "$(candidate_hash "$path")" "$current_owner"
}
write_status IN_PROGRESS 'guarded targeted replacement started'
while IFS= read -r path; do replace_one "$path"; done <<< "$approved_seven"
while IFS= read -r path; do
  [[ "$(sha256sum -- "$DOCROOT/$path" | awk '{print tolower($1)}')" == "$(candidate_hash "$path")" ]] || fail "final hash mismatch: $path"
done <<< "$approved_seven"
[[ "$(sha256sum -- "$composer_lock" | awk '{print tolower($1)}')" == "$composer_before" ]] || fail 'composer.lock changed during release'
[[ "$(git -C "$DOCROOT" rev-parse HEAD)" == "$current_head" ]] || fail 'Git HEAD changed during release'
write_status SUCCESS 'exact seven candidate hashes verified; no Git, Composer, or SQL'
printf 'RELEASE_RESULT=PASS files=7 changed_paths=%s\n' "$CHANGED"
printf 'Per-file renames are not one atomic seven-file transaction. Status: %s/%s\n' "$STAGE" "$STATUS_NAME"

