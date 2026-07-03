# WP AI Chatbot Connector — v0.6

A WordPress plugin for sourovdeb.com that adds an AI chat/assistant widget and a
publishing assistant, wired to multiple AI providers behind one interface.

## Two widgets

- **Visitor search** (green 🔍, public): searches posts, pages, and categories. No
  external AI call, no login.
- **Admin AI** (blue 💬, logged-in editors): full assistant — draft, schedule, and
  ask about the site. Available on the front end and inside wp-admin.

Capabilities are gated: post creation requires `edit_posts`; AJAX handlers are
nonce-protected; API keys stay server-side.

## Providers

Set in **Settings → AI Chatbot → Provider**:

| Provider | How it's called | Key field |
| --- | --- | --- |
| OpenRouter (+ free-model fallback chain → Gemini) | OpenAI-compatible | API Key |
| xAI Grok | OpenAI-compatible | API Key |
| **Anthropic (Claude)** | native Messages API | Anthropic API key |
| **OpenClaw** | OpenAI-compatible | API Key |
| Shared Ollama (AI Engine) | native Ollama | — |
| Custom (any OpenAI-compatible host) | OpenAI-compatible | API Key |

Claude uses current model ids — `claude-sonnet-5` (default), `claude-opus-4-8`,
`claude-fable-5`, `claude-haiku-4-5`. Enter the model in the **Primary model**
field and the key in **Anthropic (Claude) API key**.

## Install / update

1. In WordPress: **Plugins → Add New → Upload Plugin**, choose a ZIP of this repo,
   Install & Activate. Or clone/deploy to
   `wp-content/plugins/wp-ai-chatbot-connector/`.
2. **Settings → AI Chatbot** → pick a provider, paste its key, save.
3. Use **Run test ping** to confirm the connection.

For automated deploys (no manual upload), use the SSH + WP-CLI / Hostinger Git
flow in **`AGENTS.md` §3** — not an HTTP file-upload endpoint.

## Remote content management

Programmatic create / edit / schedule / delete of posts and pages is handled by
the WordPress core REST API (`/wp-json/wp/v2/*`) with per-user Application
Passwords, and by the `Sourov AI Controller` plugin's `sourov/v1` convenience
routes (SEO mapping, batch scheduling). See **`AGENTS.md`** for the full agent
playbook and **`SECURITY.md`** for the credential-hygiene rules.

## Security

- No API key is ever committed — keys live in WordPress options (ideally seeded
  from `wp-config.php` constants / server env). If a secret appears in a diff,
  rotate it. See `SECURITY.md`.
- Auth keys go in headers, never query strings.
- No HTTP endpoint writes arbitrary files or evaluates arbitrary code.

Repo: https://github.com/sourovdeb/wp-ai-chatbot-connector
