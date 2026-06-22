# WP AI Chatbot Connector (Ollama / Grok / OpenRouter)

Simple, self-contained WordPress plugin for a frontend AI chatbot.

- **Shortcode**: `[ai_chatbot]` – drops in a clean chat interface.
- **Providers**: Any OpenAI-compatible API.
  - **Local Ollama**: Set endpoint to your publicly exposed Ollama (e.g. via ngrok/Cloudflare Tunnel: `http://YOUR-IP:11434/v1`). Run `ollama serve --host 0.0.0.0`. Use model like `llama3.2`. **Security warning**: Expose responsibly with firewall/auth.
  - **OpenRouter**: `https://openrouter.ai/api/v1` + your key. Free/paid models available (e.g. `meta-llama/llama-3.2-3b-instruct:free`).
  - **xAI Grok**: `https://api.x.ai/v1` (if OpenAI-compatible endpoint works) or via OpenRouter (Grok models available). Use your xAI API key.
- **Settings**: WP Admin > Settings > AI Chatbot. Configure endpoint, key, model, system prompt.
- **How it works**: Frontend JS → WP AJAX (hides key) → cURL to provider → streams reply.
- **Integration with your pipeline**: Use alongside your automated publishing scripts. Chatbot can help generate ideas, summarize, or answer visitor questions about published content.
- **Install**: Upload folder to `/wp-content/plugins/`, activate, or clone repo and `composer install` if extending. Or zip the folder.
- **Activation defaults**: OpenRouter free model + helpful prompt.
- **Limits/Notes**: Basic single-turn for v0.1. Extend for history, streaming, or RAG. Test API first. For local Ollama from hosted WP, tunneling required.

Created via Grok for Sourov Deb's WP setup (Reunion context, automated pipeline). Push updates via GitHub.

Source: Built on standard WP REST/AJAX + OpenAI chat completions format (verified common pattern in community plugins like ai-provider-for-ollama, WP OpenRouter Provider).

## Quick Start
1. Activate plugin.
2. Go to Settings > AI Chatbot. Enter your endpoint/key/model.
3. Add `[ai_chatbot]` to a page.
4. Chat!

For full autonomous like OpenClaw: Pair with your existing scripts or extend this with WP REST API calls.

Repo created and pushed for execution.