# Sourov AI Gateway

A secure, multi-provider AI gateway for WordPress, exposed over the REST API.
Providers: **Mistral, xAI/Grok, OpenRouter, OpenClaw, and Anthropic (Claude)**.

This plugin is the clean re-implementation of the `sourov-ai-gateway` sketch in
the task brief. It deliberately differs from that sketch in two security-critical
ways — read [`../SECURITY.md`](../SECURITY.md) and [`../AGENTS.md`](../AGENTS.md)
for the full incident context:

1. **No secrets in code or database.** Every provider key is read at call time
   from a `wp-config.php` constant or a server environment variable. Nothing is
   hardcoded and nothing is written to `wp_options` except a hash-compared
   gateway key you set yourself.
2. **No `node/` execution layer, no file-write / code-eval endpoints.** The
   original sketch included a "Node.js execution layer" and the repo previously
   shipped a `deploy.php` that could upload, delete, and run arbitrary files via
   a static key in the URL. That is a remote-code-execution backdoor and it is
   what led to the credential leak documented in `SECURITY.md`. This plugin only
   proxies chat requests to vetted provider APIs. To deploy code, use the
   SSH + WP-CLI + GitHub Actions path described in `AGENTS.md §3`.

## Configure keys (never commit these)

Add to `wp-config.php` (above `/* That's all, stop editing */`) or export as
server env vars:

```php
define( 'SOUROV_AI_GATEWAY_KEY',       getenv('SOUROV_AI_GATEWAY_KEY') );   // gateway auth
define( 'SOUROV_MISTRAL_API_KEY',      getenv('SOUROV_MISTRAL_API_KEY') );
define( 'SOUROV_XAI_API_KEY',          getenv('SOUROV_XAI_API_KEY') );       // Grok
define( 'SOUROV_OPENROUTER_API_KEY',   getenv('SOUROV_OPENROUTER_API_KEY') );
define( 'SOUROV_OPENCLAW_API_KEY',     getenv('SOUROV_OPENCLAW_API_KEY') );
define( 'SOUROV_OPENCLAW_BASE_URL',    getenv('SOUROV_OPENCLAW_BASE_URL') ); // optional, self-hosted
define( 'SOUROV_ANTHROPIC_API_KEY',    getenv('SOUROV_ANTHROPIC_API_KEY') ); // Claude
```

The gateway key can also be seeded from the `SOUROV_AI_GATEWAY_KEY` env var on
plugin activation. If no gateway key is set, only logged-in admins
(`manage_options`) can call the authenticated routes.

## REST API

Namespace `sourov-ai/v1`. Auth is the **`X-Sourov-Key` header** (never a query
string) or a logged-in admin session.

| Method | Route | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/status` | public | health + which providers are configured (booleans only) |
| GET | `/providers` | yes | list providers |
| POST | `/{provider}/chat` | yes | chat completion (rate-limited) |
| GET | `/{provider}/models` | yes | provider label/default |

`{provider}` is one of `mistral`, `xai`, `openrouter`, `openclaw`, `anthropic`.

### Example — Claude

```bash
curl -sS -X POST "https://www.sourovdeb.com/wp-json/sourov-ai/v1/anthropic/chat" \
  -H "X-Sourov-Key: $SOUROV_AI_GATEWAY_KEY" \
  -H "Content-Type: application/json" \
  -d '{"prompt":"Write a 2-line SEO meta description for a micro-entreprise guide.",
       "model":"claude-sonnet-5","max_tokens":256}'
```

Response: `{ "text": "...", "model": "...", "raw": { ... } }`.

`messages` (OpenAI-style array of `{role, content}`) is also accepted instead of
`prompt`. For Claude, a `role:"system"` message is lifted into the Anthropic
`system` field automatically.

## Adding another provider

Create `includes/class-yourprovider.php` extending `Sourov_AI_Provider`
(or overriding `chat()` for a non-OpenAI shape like Anthropic), then add one line
to `sourov_ai_gateway_providers()` in the main plugin file.

## Rate limiting

Per-caller sliding window via transients — default 60 requests / 60s, tunable
with the `sourov_ai_rate_limit` / `sourov_ai_rate_window` filters.
