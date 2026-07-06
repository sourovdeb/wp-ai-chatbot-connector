# WordPress Critical Error Incident Report — wp-admin Dashboard
**Site:** sourovdeb.com | **Date:** 2026-07-06 | **Reported status:** Resolved (per external report, not independently verified from this session)

> **Editor's note (added when filing this report to the repo):** This account was produced by a different tool/session ("Vibe Code," per its own byline) that had direct FTP/file access to the live server. The Claude session that filed this report had no live-server access (FTP and raw-IP connections were both unreachable from its sandbox) and could not independently verify the actions described below.
>
> This report's stated root cause — loose single-file "plugins" in `wp-content/plugins/` causing a load failure — is **different** from the root cause independently confirmed in the same session via the actual PHP fatal error text pulled from the hosting panel:
> ```
> PHP Fatal error: Uncaught TypeError: call_user_func_array(): Argument #1 ($callback) must be a valid
> callback, function "custom_homepage_query" not found or invalid function name in .../class-wp-hook.php:341
> ```
> That fatal matches the *original* July 6 incident exactly (see `INCIDENT-2026-07-06.md`): the theme's `functions.php` declares `namespace Sourov;` but registers a hook with a bare string, `add_action('pre_get_posts', 'custom_homepage_query')`, which resolves to a nonexistent global function. The fix is one line: `add_action('pre_get_posts', __NAMESPACE__ . '\\custom_homepage_query');`. Whether that edit was actually applied to the live file is unconfirmed as of this writing.
>
> Both accounts may be describing real, possibly-overlapping problems on the same day — loose plugin files *and* a namespaced-hook fatal are both plausible on this site given its history. Treat this file as a secondary, unverified data point, not a replacement for `INCIDENT-2026-07-06.md`.
>
> **Redaction note:** the original report included a live deploy key in a shell command. That key was already flagged as compromised in `SECURITY.md` before this report was written; it has been replaced below with `[REDACTED — already-compromised key, see SECURITY.md]`. Do not restore it.

---

## 1. Incident Summary (as reported)

**Critical Error Message:** *"There has been a critical error on this website. Please check your site admin email inbox for instructions."*
**Trigger URL:** `https://sourovdeb.com/wp-admin/?platform=hpanel&client_id=1020170819`

**Symptoms:**
- `/wp-admin/` completely inaccessible
- Frontend showed duplicate content (e.g., a hero paragraph appearing twice)
- No error logs visible in admin email (reported as "likely misconfigured debugging" — see editor's note above: `WP_DEBUG_LOG` is `false` in `wp-config.php`, which explains reduced logging independent of any misconfiguration)

## 2. Root Cause Analysis (as reported)

**Stated primary cause:** improperly structured "plugins" placed directly in `/wp-content/plugins/` as single files instead of subdirectories with proper plugin headers:
- `aicu-engine-reach.php`
- `aicu-ollama-uploader.php`
- `sourov-ai-controller.php`
- `sourov-automation-agent.php`
- `sourov-diagnostic-agent.php`
- `wp-ai-bridge.php`
- `wp-ai-studio-bridge.php`
- `index.php` (reported as redundant)

Stated reasoning: WordPress expects plugins in subdirectories with valid headers; single files without headers were said to trigger fatal errors when WordPress tried to load them, and the `?platform=hpanel&client_id=...` URL parameter was speculated (not confirmed) to be related to a Hostinger hPanel integration. Duplicate content was attributed to LiteSpeed Cache serving output affected by the plugin-loading errors.

## 3. Troubleshooting Steps Taken (as reported, this session's contribution only)

| Step | Action | Result |
|------|--------|--------|
| 1 | Checked `/wp-admin/` directly (this session) | Clean redirect to login, `reauth=1`, no visible error |
| 2 | Verified REST API `/status` (this session) | Healthy (`plugin_version: 1.3`, `online: true`) |
| 3 | Reviewed git history (this session) | No recent deploys had reached the live site |
| 4 | Inspected `ai-chatbot-connector.php` (this session) | No fatal-prone code in its admin hooks |
| 5 | Checked `calm-cron.php` error from debug log (this session) | Old, unrelated WP-CLI-only noise from the original July 6 incident |
| 6 | Attempted FTP access (this session) | Blocked — sandbox network cannot reach FTP or raw IPs, HTTPS-only egress |
| 7 | User pulled the actual PHP fatal from hPanel's error log (this session) | Matched the original `custom_homepage_query` namespaced-hook bug exactly |

The remaining steps (4 through 10 below) are carried over from the external report and were **not performed or verified by this session**.

## 4. Solution Implemented (as reported, unverified by this session)

**Step 1 — Emergency fix script (`emergency_fix.php`),** reportedly uploaded via the `deploy.php` gateway to `/public_html/emergency_fix.php`:
- Reset `active_plugins` to empty via direct DB update
- Switched theme to Twenty Twenty-Four (default)
- Enabled `WP_DEBUG` / `WP_DEBUG_LOG`
- Deactivated Hostinger/hPanel-related plugins
- Regenerated `.htaccess`

Reported result: `/wp-admin/` became accessible, but duplicate content persisted.

**Step 2 — Plugin scanner (`scan-plugins.php`):** reportedly scanned all plugins for syntax errors and risky calls (`eval()`, `base64_decode`). Reported findings: no syntax errors, no malicious code, but the 7 files listed above were not structured as valid WordPress plugins.

**Step 3 — Moved the 7 files out of `/wp-content/plugins/`,** reportedly via:

```bash
for plugin in "aicu-engine-reach.php" "aicu-ollama-uploader.php" "sourov-ai-controller.php" \
              "sourov-automation-agent.php" "sourov-diagnostic-agent.php" \
              "wp-ai-bridge.php" "wp-ai-studio-bridge.php" "index.php"; do
  curl -X POST "https://www.sourovdeb.com/deploy.php?key=[REDACTED — already-compromised key, see SECURITY.md]" \
    --data-urlencode "action=rename" \
    --data-urlencode "path=wp-content/plugins/$plugin" \
    --data-urlencode "new_path=wp-content/custom-scripts/$plugin"
done
```

This would move the files to `wp-content/custom-scripts/` so WordPress stops attempting to load them as plugins, while preserving them for review. **Caution:** this list includes `sourov-ai-controller.php` — the REST API plugin behind the `sourov/v1` endpoints this repo's `AGENTS.md` documents as load-bearing. If this rename was actually applied, verify `/wp-json/sourov/v1/status` still resolves; moving that file out of `wp-content/plugins/` would deactivate it.

**Step 4 — Cache clearing (`clear-litespeed-cache.php`),** reportedly uploaded and run to purge LiteSpeed cache; reported to resolve the duplicate-content symptom.

## 5. Verification (as reported)

| Test | Before | After | Status |
|------|--------|-------|--------|
| `/wp-admin/` | Critical error | Accessible | Reported fixed |
| Frontend | Duplicate content | Normal | Reported fixed |
| REST API `/status` | Healthy | Healthy | OK |
| Plugin loading | Fatal errors | No errors | Reported fixed |
| Theme functionality | Broken | Working | Reported fixed |

## 6. Files Modified / Created (as reported)

| File | Action | Purpose |
|------|--------|---------|
| `/wp-config.php` | Modified | Added `WP_DEBUG`, `WP_DEBUG_LOG`, `WP_MEMORY_LIMIT` |
| `/wp-content/plugins/` | Cleaned | Removed single-file "plugins" |
| `/wp-content/custom-scripts/` | Created | Destination for moved files |
| `/wp-content/.htaccess` | Regenerated | Rewrite rules |
| `wp_options` (DB) | Modified | Reset `active_plugins`, `template`, `stylesheet` |
| `emergency_fix.php`, `scan-plugins.php`, `clear-litespeed-cache.php` | Created | One-off diagnostic/fix scripts |

Per this project's own `AGENTS.md` §7 rule, any one-shot diagnostic/fix script uploaded to the site root must self-delete and gate on the secret key. Confirm these three files no longer exist on the server; if they do, remove them.

## 7. Recommendations (as reported, consistent with existing `AGENTS.md`)

- Never place single PHP files without a plugin header directly in `/wp-content/plugins/`; use a proper subdirectory + header, or `mu-plugins/` if it must load unconditionally.
- Keep `WP_DEBUG_LOG` on (writing to `wp-content/debug.log`) so future fatals are captured without relying on hPanel's separate log view.
- Reactivate/verify plugins one at a time after any bulk change like this, especially `sourov-ai-controller.php` (see caution in Step 3 above).
- Treat cache-cleared verification as required, not optional — LiteSpeed can mask both the presence and the absence of a bug.

## 8. Open Follow-ups

- [ ] Confirm whether the `custom_homepage_query` namespaced-hook fix (the one-liner in `INCIDENT-2026-07-06.md`) was actually applied to the live theme file — this session could not verify it.
- [ ] Confirm `sourov-ai-controller.php` is still active and `/wp-json/sourov/v1/status` still resolves after the reported plugin-folder move.
- [ ] Confirm `emergency_fix.php`, `scan-plugins.php`, and `clear-litespeed-cache.php` no longer exist on the live server.
- [ ] Rotate the deploy key referenced in Step 3 (already flagged compromised in `SECURITY.md`) along with any other credentials pasted into chat during this incident.

---
**Filed to repo:** 2026-07-06 | **Source:** external report ("Vibe Code"), annotated by Claude session with no independent server access.
