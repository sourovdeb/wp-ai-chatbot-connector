# Retrospective: 2026-07-06 dashboard critical-error diagnosis

**What this is:** an honest account of where this Claude Code session was slow or
wrong while helping diagnose the wp-admin critical error on 2026-07-06, written
because the user asked for it directly, so future agents don't repeat the same
pacing mistakes. Companion to `INCIDENT-2026-07-06-DASHBOARD.md` (the technical
account) — this file is about process, not the bug itself.

## What actually happened, and where I was slow

The bug itself was diagnosed correctly and fast the moment the right input
arrived: the user pasted the literal PHP fatal (`custom_homepage_query` not
found, in `class-wp-hook.php:341`), and within one response it was matched to
the exact known root cause — a bare-string hook inside a namespaced theme file,
the same bug class as the original July 6 outage — with the one-line fix. That
part worked correctly and quickly.

The problem is how long it took to get to that input, and how much of that time
was avoidable.

1. **I asked for the right thing early, then didn't hold the line on it.** The
   first real response correctly identified the exact fatal error text as the
   fastest path to a fix, and asked for it. Over the next several exchanges,
   when the user supplied other diagnostics instead (a full `phpinfo()` dump, a
   cron job list, an old debug-log entry), each was analyzed competently, but I
   didn't consistently re-center the same simple ask. Each side-diagnostic was
   legitimate effort on the user's part, but none of them were the fatal error,
   and the conversation drifted into evaluating them rather than repeating,
   plainly, "this isn't it — here's the one place that has the actual answer."

2. **I over-invested in the investigation I could run myself, not the one most
   likely to pay off.** When asked to repeat an earlier session's playbook, a
   large share of available tool calls went into GitHub/CI archaeology —
   workflow run history, `plugin-manager-runner.php`, `fix-automation-agent.py`,
   a full read of `ai-chatbot-connector.php`'s admin hooks — to test whether a
   recent commit had broken the dashboard. That hypothesis was reasonable to
   check, but low-probability once the front end and REST API had already come
   back healthy, and checking it thoroughly cost far more turns than it was
   worth. This is a streetlight effect: GitHub was the tool available, so it got
   the attention, not the live PHP fatal that actually held the answer.

3. **I asserted a tool limitation before testing it.** This session's lack of
   FTP/SSH access was stated in words several times before it was actually
   tested and the concrete result reported (DNS resolved, but the connection
   itself timed out — a hard network boundary, not a permissions choice). That
   test only ran after being told directly to "try yourself." Running it the
   first time the user pushed back would have either closed the question sooner
   or at least demonstrated the limitation instead of asserting it, which likely
   read as refusal rather than fact.

## Why "the other tool could" and I couldn't (partially)

The external report filed as `INCIDENT-2026-07-06-DASHBOARD.md` came from a
different tool that apparently had direct file/server access — it describes
uploading scripts, reading files, moving them, and clearing cache directly.
That's a real, structural difference: this session's network egress is
HTTPS-only to a small set of destinations, confirmed empirically (FTP and
raw-IP connections both fail at the transport level, not a permissions refusal).
A tool with real file access can iterate empirically — try something, look at
the result, adjust — in a way this session structurally cannot. That gap is
about environment/tooling, not reasoning quality.

That said, two things temper the "the other one could, you couldn't" framing:

- That report's own root-cause theory (loose plugin files breaking WordPress's
  plugin loader) is **different from** — not a confirmation of — the root cause
  independently established from the actual fatal error text in this session.
  It may describe a real, separate problem, or it may be a plausible-sounding
  but incorrect diagnosis; it was never checked against an actual fatal error
  the way this session's conclusion was.
- That report itself still contained a live, already-compromised secret in
  cleartext, which had to be redacted before filing it here. Direct server
  access didn't prevent that mistake.

So: better tooling access explains most of the gap. It doesn't mean the other
account was more rigorous — it wasn't secret-safe, and its conclusion is
unverified.

## Estimated cost to the user

There are no wall-clock timestamps for each message, so this is a structural
estimate, not measured telemetry. Between the first correct ask for the exact
fatal error and the message that finally supplied it, there were **five
separate round trips** where the user went back into hPanel or email, pulled
something, and pasted it back, without it being the needed data (a config/creds
dump, a second credentials paste, a full phpinfo dump, a cron+debug-log pull,
and a "try yourself" exchange). For a stressed user navigating an unfamiliar
hosting panel under time pressure, a realistic cost per round trip is on the
order of 15–30 minutes. That puts the avoidable portion of this session at
roughly **1.5–2.5 hours** — time a single, firmly-held, well-targeted ask
("check your admin email for WordPress's automatic critical-error notice; if
there isn't one, hPanel → PHP Errors — that's the only thing needed before
anything else") could plausibly have saved, on top of testing the FTP
limitation immediately instead of after being asked twice more.

This is in addition to, not instead of, the original multi-hour cost of the
first 2026-07-06 outage documented in `INCIDENT-2026-07-06.md`, which is a
separate, already-closed event.

## What changes going forward

Encoded in `.claude/skills/wp-critical-error-triage/SKILL.md` and
`GUARDRAILS.md`, referenced from `AGENTS.md` §8:

- Get the exact fatal error text first, every time — treat it as the
  objective, not one option among several diagnostics to weigh.
- Test any claimed tool/access limitation empirically, immediately, the first
  time it's questioned — don't repeat an unverified disclaimer.
- Rank hypotheses by probability of being the actual cause, not by which tool
  happens to be reachable from the current session.
- Treat cached responses as non-authoritative for "is the code currently
  broken."
- Check the known recurring bug (namespaced bare-string hooks) first for any
  partial-failure symptom on this site.
