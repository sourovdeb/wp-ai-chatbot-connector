---
name: wp-critical-error-triage
description: Diagnose a WordPress "critical error" / partial-site-outage on sourovdeb.com fast, without live server access — get the exact fatal error first instead of investigating blind. Use whenever wp-admin, a specific page, or the front end reports a critical error, 500, or fatal on this site.
---

# WordPress critical-error triage (sourovdeb.com)

## The one rule that matters

Get the **exact PHP fatal error text (file + line)** before doing anything
else. Every other diagnostic step is a fallback for when that's not
immediately available — not a parallel track to run at the same time as
independent investigation.

Two sources need no FTP/SSH, no file access, nothing from the agent:

1. **The WP admin-email inbox.** Since WP 5.2, any fatal auto-emails the site
   admin the exact file + line, *regardless* of `WP_DEBUG` / `WP_DEBUG_LOG`
   settings. This is the fastest path and should be the first thing asked
   for.
2. **hPanel → Advanced → PHP Errors, or File Manager → `wp-content/debug.log`.**
   Browser-based, no FTP client needed.

If the user offers other diagnostics first (a `phpinfo()` dump, a cron job
list, an old log entry) — accept them, look at them, but don't let them
substitute for the ask above. State plainly that it isn't the needed data and
repeat the ask, rather than quietly analyzing it and drifting on to the next
thing.

## Before claiming a tool/access limitation, test it

Don't just assert "I don't have FTP/SSH access." Attempt the lowest-risk
version of it once (a read-only FTP directory listing, a raw-IP HTTPS probe)
and report the concrete result — "DNS resolved, but the connection itself
timed out" is far more credible, and closes the question faster, than a
repeated verbal disclaimer. This matters especially when the user remembers a
different session having that access — the gap is usually real, but it needs
demonstrating, not just stating.

## Rank hypotheses by probability, not by tool availability

This repo and its GitHub Actions are reachable from a Claude Code session
even when the live server isn't. That makes "did our own recent commit break
this?" tempting to investigate at length — check it quickly (has the
relevant deploy workflow even run since the commit landed? `deploy-to-wordpress.yml`
only triggers on push to `main` for specific paths — a commit sitting on an
unmerged branch/PR never touched the live site) and move on if the answer is
no. Don't let "the thing I can search" substitute for "the thing most likely
to be the cause."

## Known recurring bug class on this site

Bare-string hook registration inside namespaced PHP:

```php
namespace Sourov;
// ...
add_action('pre_get_posts', 'custom_homepage_query');   // wrong — resolves to a global function that doesn't exist
add_action('pre_get_posts', __NAMESPACE__ . '\\custom_homepage_query');  // correct
```

This exact bug has caused **two** separate incidents on the same day
(`INCIDENT-2026-07-06.md` and `INCIDENT-2026-07-06-DASHBOARD.md`). It fatals
on *every* page load that fires the affected hook — which, for a hook like
`pre_get_posts`, includes admin dashboard widgets and list tables, not just
the public-facing main query. Check this class of bug FIRST for any "some
pages/contexts work, others don't" symptom, before theories about plugin
folder structure, cache corruption, or a fresh deploy.

## Cache is not verification

LiteSpeed (or any page cache) can mask both the presence and the absence of a
live bug — a cached page returning 200 proves nothing about whether the
underlying PHP is currently broken. Any "it works" read against a
potentially-cached URL needs a cache-busting query string or an explicit
purge before it counts as confirmation. This is also why "the front end is
fine" does not imply "the admin dashboard is fine" — they're rarely cached
the same way.

## Read the incident history first

`INCIDENT-2026-07-06.md` and `INCIDENT-2026-07-06-DASHBOARD.md` are full
incident accounts for this exact site. `RETRO-2026-07-06-DASHBOARD-DIAGNOSIS.md`
covers process mistakes worth not repeating. `GUARDRAILS.md` has the hard
rules. Don't re-derive root causes that are already documented — check these
first.
