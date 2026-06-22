# WP AI Chatbot Connector (Ollama / Grok / OpenRouter) v0.2

**Multi-turn • Floating toggle widget • Save/Schedule posts from chat • Ties to your publishing pipeline**

- **Floating widget + toggle**: 💬 button always visible (bottom-right). Opens multi-turn chat window. Auto via settings (or shortcode `[ai_chatbot]` for embedded).
- **Multi-turn history**: Full context sent each turn for coherent conversation.
- **Post/Schedule from chat** (enable in settings): After every AI reply, buttons appear — "Save as Draft" or "Schedule +24h". Creates WP post (draft or future). Perfect tie-in to your fixer/publishing scripts, Google Sheets, Logseq, GitHub automation, ELT content flow.
- **Providers** (Settings): Any OpenAI-compatible.
  - Local Ollama: Expose securely (ngrok/Cloudflare) → `http://IP:11434/v1`.
  - OpenRouter / xAI Grok: Their base URL + key (Grok models supported via OpenRouter).
- **Settings** (WP Admin > Settings > AI Chatbot): Endpoint, key, model, system prompt, floating on/off, schedule/post enable.
- **Security & caps**: Key server-side only. create_post requires `edit_posts`. Nonce protected.
- **Upload to your WP Admin (no auto-push)**: 
  1. GitHub repo → green "Code" button → Download ZIP.
  2. WP Admin → Plugins → Add New → Upload Plugin → select the zip → Install & Activate.
  3. (Alternative) Unzip to `wp-content/plugins/wp-ai-chatbot-connector` via FTP/cPanel.
- Future updates: Re-download zip or git pull if cloned to server. No manual push from your side needed after initial.
- **Defaults on activate**: OpenRouter free tier, floating widget on, schedule off (toggle on in settings).
- **Pipeline integration (from your PDF)**: Generate content in chat → one-click draft/schedule → your remote publishing scripts (fixer, Python GUI, GitHub) handle SEO, tags, publish. Or extend further for direct REST triggers.

**Limits (v0.2)**: No streaming, basic post parsing. For prod: Add login gate or rate limiting. Local Ollama needs public tunnel + firewall.

Created/extended via Grok for Sourov Deb (Saint-Denis/Pierrefonds context). Matches community patterns (ai-provider-for-ollama etc.).

## Quick Start
1. Download zip from repo → upload/activate in WP.
2. Settings > AI Chatbot → configure provider (test OpenRouter free first).
3. Enable floating + schedule options.
4. 💬 button appears everywhere. Chat multi-turn. Use post buttons to feed pipeline.
5. Add shortcode to specific pages if preferred.

Repo: https://github.com/sourovdeb/wp-ai-chatbot-connector (updated & pushed).

**Execution done.** Next task? (e.g. VS Code extension tie-in, custom pipeline commands, Ollama local-only mode, or full REST publishing endpoint). Provide details.