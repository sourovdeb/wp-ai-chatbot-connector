---
name: rigorous-execution
description: General verification-first framework for debugging, code changes, and high-stakes analysis — confirm evidence before acting, check structure before logic, label confirmed vs. inferred vs. unknown, and record root cause after resolution. Use for long debugging/troubleshooting sessions, tasks involving code/systems/documents/complex processes, high-stakes situations (critical errors, legal/regulatory, data integrity), or whenever the user has provided logs, code, or files for analysis.
---

# Rigorous Execution Framework

Enforces high-quality, low-error execution across any domain by embedding
verification, structural thinking, and appropriately-scaled aggressiveness.

## When to activate

- Long debugging/troubleshooting sessions
- Tasks involving code, systems, documents, or complex processes
- High-stakes situations (critical errors, legal/regulatory, data integrity)
- The user has provided logs, code, or files for analysis

## Core guardrails

### 1. Verification before action
Never give edit/modification instructions without confirming the target
exists in the provided material. State explicitly: "Verified in the
pasted code/logs" or "Cannot confirm — please share the relevant section."

### 2. Structural analysis first
Before analyzing logic or symptoms, check:
- Is the file/component in the correct location?
- Does it follow the expected structure/format?
- Are there missing headers, dependencies, or declarations?

### 3. Severity-based decision making
- **Critical/high severity:** prioritize fast, high-impact actions over
  prolonged incremental debugging.
- **Medium/low severity:** use step-by-step incremental fixes.

> **Integration note for this repo:** "fast, high-impact action" still means
> going through this repo's existing hard rules, not around them —
> `GUARDRAILS.md` requires destructive or site-wide changes to go through a
> reviewable channel (a GitHub Actions run, a PR) and prefers trash/drafts
> over hard deletes when ambiguous, even under time pressure. Speed here
> means "reach for the effective fix fast," not "skip confirmation or
> logging because it's urgent" — that shortcut is exactly what turned a
> namespaced-hook typo into the multi-hour 2026-07-06 outage
> (`INCIDENT-2026-07-06.md`).

### 4. Explicit evidence tracking
In analysis, clearly label each claim as:
- Confirmed from user-provided material
- Inferred / probable
- Unknown / needs verification

### 5. Anti-assumption protocol
Do not assume a function, file, or behavior exists unless evidence is
shown. When in doubt, ask for the specific file/section rather than
guessing — or, if a tool can check it directly, check it directly instead
of asking (see `.claude/skills/wp-critical-error-triage/SKILL.md`'s rule on
testing claimed limitations empirically rather than asserting them).

### 6. Post-resolution recording
After complex resolutions, document:
- Root cause
- Why previous attempts failed
- Prevention method for future similar cases

For this repo specifically, that record is the pattern established by
`INCIDENT-2026-07-06.md`, `INCIDENT-2026-07-06-DASHBOARD.md`, and
`RETRO-2026-07-06-DASHBOARD-DIAGNOSIS.md` — a technical incident account
plus, when the diagnosis itself was slower than it should have been, a
separate honest process retrospective.

## Recommended output format for complex issues

1. **Evidence summary** — what is confirmed from provided material
2. **Root cause analysis** — structural + logical
3. **Prioritized actions** — strongest effective action first
4. **Prevention recommendations**
5. **Uncertainty declaration** — what remains unconfirmed
