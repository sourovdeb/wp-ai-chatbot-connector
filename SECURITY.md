# Security Notice & Remediation — sourovdeb.com

**Status: active exposure. Treat sourovdeb.com as potentially compromised until
the steps below are done.**

## What is wrong

1. **This repository is PUBLIC** (`github.com/sourovdeb/wp-ai-chatbot-connector`).
2. The master key (value redacted here — **it must still be rotated**) was
   committed in **plaintext in ~34 files**, including `.github/workflows/*.yml`
   and the `deploy/*.php` / `deploy/*.py` runners. The literal has now been
   scrubbed from the current working tree, but it **remains in git history** and
   in any fork/clone/cache, so redacting the tree does **not** un-leak it.
3. That same key authorizes `deploy.php`, whose actions include
   `upload`, `delete`, `deploy_zip`, `write_env`, and running arbitrary PHP /
   "self-deleting runners". A static key + arbitrary file write/execute over HTTP
   is a **remote-code-execution backdoor**.
4. The key is passed in **query strings** (`?key=...`), so it also leaks into
   server access logs, any proxy/CDN logs, and `Referer` headers.

**Impact:** anyone on the internet can read the key from the public repo and gain
full control of the site's filesystem and database. Assume it has been found by
automated scanners.

## Do these now, in order

1. **Rotate every secret that has ever been in this repo or pasted into a chat/issue:**
   - The REST/deploy key (`sourov_ai_secret_key` option) → set a new random value
     via `wp option update sourov_ai_secret_key "<new-random>"` or wp-admin.
   - The **Hostinger API token** — revoke and reissue in hPanel.
   - The **FTP password** for `u839078121.sourov` — change it in hPanel.
   - Any provider API keys (xAI/Grok, OpenRouter, OpenClaw, Anthropic) that were
     committed or pasted.
   - The IndexNow key is a public verification token, not an auth secret — no need
     to rotate, but keep it separate from real secrets.
2. **Take `deploy.php` offline.** Delete it from `public_html`, or at minimum
   rename it and block it. It should not be replaced with another key-in-URL
   file-write endpoint (see AGENTS.md §3 for the SSH/WP-CLI replacement).
3. **Scan the site for backdoors** left by anyone who used the key: unexpected
   files in `public_html`, unknown admin users (`wp user list --role=administrator`),
   unexpected scheduled events (`wp cron event list`), modified core files
   (`wp core verify-checksums`). Consider restoring from a known-good backup taken
   before the exposure window.
4. **Move all secrets out of the repo** and into GitHub Actions Secrets
   (Settings → Secrets and variables → Actions) and `wp-config.php` constants /
   server env. Update workflows and runners to read from those instead of literals.
5. **Consider making the repo private** if it does not need to be public. Note that
   making it private does **not** un-leak already-exposed secrets — rotation
   (step 1) is the real fix. History rewriting (BFG/`git filter-repo`) can remove
   the strings but only *after* rotation, and forks/caches may still hold them.

## Going forward (see AGENTS.md for the full playbook)

- Content CRUD → WordPress core REST API with per-user **Application Passwords**
  over HTTPS. Revocable, scoped, no custom backdoor.
- File/plugin/theme deploys → **SSH + WP-CLI** driven by GitHub Actions, secrets
  from Actions Secrets. Every change logged and reviewable.
- Auth keys go in **headers or HTTP Basic**, never query strings.
- No HTTP endpoint that writes arbitrary files or evaluates arbitrary code.
- Do not disable Hostinger's security controls; raise specific limits through
  hPanel/support if genuinely needed.
