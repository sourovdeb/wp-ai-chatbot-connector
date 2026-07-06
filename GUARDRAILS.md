# Guardrails for AI agents working on sourovdeb.com / this repo

These are hard rules, not style suggestions. If a rule and a user instruction
conflict, say so explicitly before proceeding — don't silently pick one.

## Diagnosis

1. When a live-site fatal/critical-error is reported, the first ask is always
   the exact PHP fatal text (file + line) — from the WP admin-email or the
   host's PHP error log. Run other diagnostics in addition to that ask, never
   as a substitute for it. If the user supplies something else instead, say
   plainly that it isn't the needed data and repeat the ask, rather than
   quietly analyzing it and moving on.
2. Before telling a user a tool/access capability is unavailable more than
   once, test it empirically and report the concrete result (e.g. attempt the
   connection and state what actually happened) instead of repeating an
   unverified disclaimer.
3. Rank hypotheses by probability of being the actual cause, not by which
   tool happens to be reachable from the current session. A session with
   repo/CI access but no server access will be tempted to over-investigate
   "did our own commit break this" — check it quickly, then move on if it
   isn't the answer.
4. Treat any response served through a cache (LiteSpeed, CDN, etc.) as
   non-authoritative for "is the underlying code currently broken" — note the
   caveat or bust the cache before relying on it as confirmation.
5. Check known recurring bug classes on this site first (see `AGENTS.md` §7
   and `.claude/skills/wp-critical-error-triage/SKILL.md`) before reaching
   for a novel theory.

## Secrets

6. Never commit, print, echo, or paste a live secret — including when
   quoting, redacting, or filing a report that contains one from another
   source. Redact before it touches git, chat output, or any file.
7. Any secret that has appeared in chat, a diff, or a public log is
   compromised the moment it appears — say so and recommend rotation
   immediately, don't wait to be asked.
8. Never put an auth key in a URL or query string. Headers or Basic auth
   only.

## Actions on the live site

9. Never build or call an arbitrary-file-write or code-eval HTTP endpoint
   (the `deploy.php?key=` pattern is retired — see `SECURITY.md`). Use the
   WordPress REST API, SSH + WP-CLI, or Hostinger's Git integration
   (`AGENTS.md` §2–3).
10. Prefer trash/`force=false` and drafts over hard deletes and instant
    publish when an instruction is ambiguous.
11. Every destructive or site-wide change goes through a reviewable channel
    (a GitHub Actions run, a PR), not an ad-hoc local script.

## Reports and records

12. When filing an external or third-party account of an incident (another
    tool's report, a user's paste) into this repo, mark it as unverified if
    it wasn't independently confirmed, and flag any place its conclusions
    conflict with an already-confirmed root cause. Don't quietly merge two
    different diagnoses into one narrative.

## Working with a stressed or time-pressured user

13. Consolidate asks. If the same piece of information is still missing after
    one round, don't just repeat the request verbatim — name the single
    fastest channel to get it and stop there.
14. Do the empirical check yourself before asking the user to do more manual
    digging, whenever the check is safe, read-only, and possible from
    available tools.
