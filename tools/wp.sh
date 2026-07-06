#!/usr/bin/env bash
#
# wp.sh — secure remote content operations for sourovdeb.com
#
# Uses the WordPress *core* REST API (/wp-json/wp/v2) with an Application
# Password over HTTPS Basic auth. No custom backdoor, no key in a URL, no
# secret in this file. An agent (Claude Code, or any CI job) can drive it
# without a human hand-running anything — the credentials come from env.
#
# Setup (once):
#   1. In wp-admin → Users → Profile → Application Passwords, create one
#      named e.g. "automation". Copy the generated password.
#   2. Export the two env vars (locally, or as GitHub Actions Secrets):
#        export WP_APP_USER="your-wp-username"
#        export WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx"   # from step 1
#   3. Optionally: export WP_BASE="https://www.sourovdeb.com"     # default
#
# App Passwords are per-user, revocable, and scoped to that user's caps —
# revoke it in wp-admin to instantly cut off access. Never paste it into
# code, commits, or chat.
#
# Usage:
#   tools/wp.sh status
#   tools/wp.sh list [posts|pages] [--status draft|publish|future] [--per-page N]
#   tools/wp.sh get   <type> <id>
#   tools/wp.sh create <type> --title "T" --content "<p>HTML</p>" \
#                 [--status draft|publish|future] [--date 2026-07-10T09:00:00] \
#                 [--categories 9,12] [--tags 3,4] [--excerpt "..."]
#   tools/wp.sh edit  <type> <id> --title "New" [--content ...] [--status ...]
#   tools/wp.sh trash <type> <id>            # reversible (moves to Trash)
#   tools/wp.sh delete <type> <id>           # PERMANENT (force=true)
#
# <type> is "posts" or "pages". All flags map straight to REST fields, so any
# field the core API accepts (slug, author, featured_media, meta, …) works.

set -euo pipefail

WP_BASE="${WP_BASE:-https://www.sourovdeb.com}"
API="${WP_BASE}/wp-json/wp/v2"

die() { echo "error: $*" >&2; exit 1; }

need_auth() {
  [ -n "${WP_APP_USER:-}" ]     || die "WP_APP_USER not set (see header of this script)"
  [ -n "${WP_APP_PASSWORD:-}" ] || die "WP_APP_PASSWORD not set (create one in wp-admin → Application Passwords)"
}

# Build a JSON body from --flag value pairs. Numbers/arrays for known fields
# are emitted unquoted/as arrays; everything else is a JSON string.
build_json() {
  python3 - "$@" <<'PY'
import json, sys
args = sys.argv[1:]
obj = {}
i = 0
list_fields = {"categories", "tags"}
while i < len(args):
    flag = args[i].lstrip("-")
    val = args[i + 1]
    if flag in list_fields:
        obj[flag] = [int(x) for x in val.split(",") if x.strip()]
    else:
        obj[flag] = val
    i += 2
print(json.dumps(obj))
PY
}

req() {
  # req METHOD PATH [JSON_BODY]
  local method="$1" path="$2" body="${3:-}"
  local -a curl=(curl -sS --fail-with-body -u "${WP_APP_USER}:${WP_APP_PASSWORD}" -X "$method" "${API}${path}")
  if [ -n "$body" ]; then
    curl+=(-H "Content-Type: application/json" -d "$body")
  fi
  "${curl[@]}"
}

cmd="${1:-}"; shift || true
case "$cmd" in
  status)
    # Public health endpoint from the project's sourov/v1 plugin (no secrets).
    curl -sS "${WP_BASE}/wp-json/sourov/v1/status" || die "status endpoint unreachable"
    echo
    ;;
  list)
    need_auth
    type="${1:-posts}"; [ $# -gt 0 ] && shift || true
    # remaining flags become query params
    q=""
    while [ $# -gt 0 ]; do q="${q}&${1#--}=${2}"; shift 2; done
    req GET "/${type}?_fields=id,status,date,title.rendered${q:+&${q#&}}"
    echo
    ;;
  get)
    need_auth
    [ $# -ge 2 ] || die "usage: get <type> <id>"
    req GET "/$1/$2"
    echo
    ;;
  create)
    need_auth
    [ $# -ge 1 ] || die "usage: create <type> --title ... --content ..."
    type="$1"; shift
    req POST "/${type}" "$(build_json "$@")"
    echo
    ;;
  edit)
    need_auth
    [ $# -ge 2 ] || die "usage: edit <type> <id> --field value ..."
    type="$1"; id="$2"; shift 2
    req POST "/${type}/${id}" "$(build_json "$@")"
    echo
    ;;
  trash)
    need_auth
    [ $# -ge 2 ] || die "usage: trash <type> <id>"
    req DELETE "/$1/$2"           # no force → moves to Trash (reversible)
    echo
    ;;
  delete)
    need_auth
    [ $# -ge 2 ] || die "usage: delete <type> <id>  (PERMANENT)"
    req DELETE "/$1/$2?force=true"
    echo
    ;;
  ""|-h|--help|help)
    sed -n '2,45p' "$0"
    ;;
  *)
    die "unknown command '$cmd' (try: status list get create edit trash delete)"
    ;;
esac
