#!/usr/bin/env bash
set -Eeuo pipefail

fail() { printf 'PROCESS_CHECK_STOP: %s\n' "$*" >&2; exit 2; }
read_ps_record() { ps -p "$1" -o pid=,ppid=,uid=,args= 2>/dev/null; }

verify_interactive_ssh() {
  local client_addr client_port server_addr server_port extra=''
  [[ -t 0 && -r /dev/tty && -n "${SSH_TTY:-}" && -n "${SSH_CONNECTION:-}" ]] || fail 'interactive SSH TTY markers are missing'
  read -r client_addr client_port server_addr server_port extra <<< "$SSH_CONNECTION" || fail 'malformed SSH_CONNECTION'
  [[ -n "$client_addr" && "$client_port" =~ ^[0-9]+$ && -n "$server_addr" && "$server_port" == 65002 && -z "$extra" ]] || fail 'SSH session does not match the approved port/session format'
  printf 'SSH_TTY_CHECK=PASS port=%s\n' "$server_port"
}

verify_flock_exclusion() {
  command -v flock >/dev/null 2>&1 || fail 'flock command is unavailable'
  [[ -f "$0" && ! -L "$0" && -w "$0" ]] || fail 'cannot use this private diagnostic file for a no-write flock probe'
  exec 9<> "$0" || fail 'cannot open diagnostic file for lock probe'
  flock -n 9 || fail 'cannot acquire an exclusive flock on the private diagnostic file'
  local rc=0
  if flock -n "$0" -c true >/dev/null 2>&1; then
    fail 'a second independent flock unexpectedly acquired the held lock'
  else
    rc=$?
  fi
  [[ "$rc" -eq 1 ]] || fail "flock contention returned unexpected status $rc"
  exec 9>&-
  printf 'FLOCK_EXCLUSION_PASS\n'
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

verify_interactive_ssh
verify_flock_exclusion
if scan_competing_processes; then
  printf 'VISIBLE_PROCESS_SCAN=PASS\nPROCESS_CHECK_RESULT=PASS\n'
else
  rc=$?
  printf 'PROCESS_CHECK_RESULT=FAIL exit=%s\n' "$rc" >&2
  exit "$rc"
fi
