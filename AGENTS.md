# Remote WordPress Management — Agent Playbook (sourovdeb.com)

This document tells an AI agent (Claude Code, or any other) how to create,
edit, schedule, and delete content and how to deploy plugin/theme/CSS/HTML
changes on sourovdeb.com **securely**, without a human running scripts by hand.

> **Read `SECURITY.md` first.** The old static-key + `deploy.php` pattern is a
> remote-code-execution backdoor and its key was leaked in this public repo.
> Do not use it, do not extend it, and do not paste secrets into chat, code,
> commit messages, or this file. All credentials come from environment
> variables / GitHub Actions Secrets / `wp-config.php` constants — never from
> the repo.

---

## 1. Where credentials live (never hardcode)

| Secret | Stored in | Referenced as |
| --- | --- | --- |
| WP Application Password | GitHub Actions Secret `WP_APP_PASSWORD` | `${{ secrets.WP_APP_PASSWORD }}` / `getenv('WP_APP_PASSWORD')` |
| WP username for the app password | Secret `WP_APP_USER` | `getenv('WP_APP_USER')` |
| SSH private key (deploys) | Secret `SSH_PRIVATE_KEY` | ssh-agent in the workflow |
| Provider API keys (xAI, OpenRouter, Anthropic…) | `wp-config.php` constants **or** server env | `getenv()` inside the plugin |
| Hostinger API token | Secret `HOSTINGER_API_TOKEN` | `${{ secrets.HOSTINGER_API_TOKEN }}` |

If a secret ever appears in a diff, treat it as compromised and rotate it.

---

## 2. Content operations — use the WordPress core REST API

WordPress core already exposes full CRUD for posts, pages, and media at
`/wp-json/wp/v2/*`. Authenticate with an **Application Password**
(Users → Profile → Application Passwords in wp-admin) over HTTPS using HTTP
Basic auth. This is per-user, revocable, and needs no custom code.

```
AUTH='-u "$WP_APP_USER:$WP_APP_PASSWORD"'   # HTTP Basic, HTTPS only
BASE='https://www.sourovdeb.com/wp-json/wp/v2'
```

A ready-made, secrets-free wrapper for all of this lives at **`tools/wp.sh`** —
it reads `WP_APP_USER` / `WP_APP_PASSWORD` from the environment and exposes
`status | list | get | create | edit | trash | delete` for posts and pages
(`tools/wp.sh help`). Prefer it (or the raw `curl` below) over any custom
file-write endpoint.

**Create a draft**
```bash
curl -sS -u "$WP_APP_USER:$WP_APP_PASSWORD" -X POST "$BASE/posts" \
  -H 'Content-Type: application/json' \
  -d '{"title":"...","content":"<p>HTML</p>","status":"draft",
       "categories":[9],"tags":[12,15],"excerpt":"..."}'
```

**Publish / schedule** — `status:"publish"` to publish now; to schedule, use
`status:"future"` plus a `date` (site timezone) or `date_gmt`:
```bash
-d '{"title":"...","content":"...","status":"future","date":"2026-07-10T09:00:00"}'
```

**Edit an existing post/page** — `POST $BASE/posts/{id}` (or `/pages/{id}`) with
only the fields to change.

**Delete** — `DELETE $BASE/posts/{id}?force=true` (omit `force` to send to trash,
which is safer and reversible).

**Media / images** — `POST $BASE/media` with the binary body and a
`Content-Disposition: attachment; filename="x.jpg"` header, then set the returned
id as `featured_media` on the post.

**SEO fields** — `meta_description` / `seo_title` are plugin meta (Yoast/RankMath).
Either register them with `show_in_rest` and set via the `meta` object, or use the
project's custom endpoint (below), which already maps them.

### Project custom endpoints (`sourov/v1`)
The `Sourov AI Controller` plugin adds convenience routes that wrap the above and
add SEO mapping, batch scheduling, and status. Auth is the `X-Sourov-Key` header,
whose value is read from the `sourov_ai_secret_key` wp-option (not hardcoded):

| Method | Route | Purpose |
| --- | --- | --- |
| POST | `/sourov/v1/ai-post` | create/schedule one post (+ SEO meta) |
| GET | `/sourov/v1/drafts` | list drafts (paginated) |
| POST | `/sourov/v1/schedule-drafts` | batch-schedule drafts (interval/offset/limit) |
| GET | `/sourov/v1/scheduled` | list future posts |
| POST | `/sourov/v1/bulk` | create many posts from a JSON array |
| DELETE | `/sourov/v1/post/{id}` | delete one post |
| GET | `/sourov/v1/status` | health + counts (public, no secrets) |

Prefer core `wp/v2` for anything it already covers; use `sourov/v1` only for the
SEO/batch conveniences. Send the key in the `X-Sourov-Key` **header**, never in a
query string (query strings land in access logs, proxies, and Referer headers).

---

## 3. Code / file deploys — SSH + WP-CLI, driven by GitHub Actions

For editing plugin PHP, theme files, CSS, or the chatbot connector, do **not**
use an HTTP file-write endpoint. Use SSH (Hostinger provides it on Premium/
Business plans) with WP-CLI, orchestrated from a GitHub Actions workflow so no
human runs a script locally.

Pattern (workflow uses `SSH_PRIVATE_KEY` secret, key added to Hostinger under
hPanel → Advanced → SSH Access):

```yaml
- name: Deploy plugin over SSH
  run: |
    rsync -az --delete ./sourov-ai-chatbot/ \
      "$SSH_USER@$SSH_HOST:/public_html/wp-content/plugins/sourov-ai-chatbot/"
    ssh "$SSH_USER@$SSH_HOST" \
      'wp plugin activate sourov-ai-chatbot --path=/public_html'
```

WP-CLI over that same SSH session covers the rest of "manage anything":
`wp plugin install/activate/update`, `wp theme ...`, `wp option update`,
`wp post ...`, `wp media import`, `wp search-replace`, `wp cron event run --all`.
This replaces every `deploy.php?action=upload|delete|deploy_zip|write_env` call
with an audited, key-authenticated, logged channel.

Alternative if SSH is unavailable on the plan: Hostinger's **Git integration**
(hPanel → Git) — push to a deploy branch and Hostinger pulls it into
`public_html`. Configure it in hPanel; the repo just holds the code.

---

## 4. Search-engine indexing (IndexNow)
After publishing, ping IndexNow with the URL. The IndexNow key is a public
verification token (served at a `.txt` on the site) — that one is fine to expose;
it is not an auth secret. Do not confuse it with the REST/deploy/API secrets.

---

## 5. Adding AI providers (Grok / OpenRouter / OpenClaw / Claude)
The chatbot connector can call multiple providers behind one interface. Each
provider's API key is read via `getenv()` from `wp-config.php` constants or server
env — never committed. For Anthropic (Claude), use the latest model ids
(e.g. `claude-fable-5`, `claude-opus-4-8`, `claude-sonnet-5`) and the standard
Messages API. Keep provider selection and keys in options/env, code in the repo.

---

## 6. Hard rules for the agent
- **Never** commit, print, or paste a secret. Reference env/secrets only.
- **Never** put an auth key in a URL/query string. Use headers or Basic auth.
- **Never** build or call an arbitrary-file-write or code-eval HTTP endpoint.
- **Prefer** trash/`force=false` and drafts over hard deletes and instant publish
  when the instruction is ambiguous.
- **Do not** attempt to disable or bypass Hostinger's security controls (WAF,
  ModSecurity, exec limits). If a legitimate limit (PHP `memory_limit`,
  `max_execution_time`) is too low, raise it through hPanel or Hostinger support —
  weakening security controls is what caused the incident in `SECURITY.md`.
- Every destructive or site-wide change goes through a GitHub Actions run so it is
  logged and reviewable, not an ad-hoc local script.
