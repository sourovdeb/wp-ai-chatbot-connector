#!/usr/bin/env python3
"""Schedule job lead automation blog post for 2026-06-25 12:00 Réunion (08:00 UTC)."""
import json
import urllib.request

API_URL = "https://sourovdeb.com/wp-json/sourov/v1/ai-post"
API_KEY = "0767044896thevenet_"

CONTENT = """
<p><strong>Published: 25 June 2026</strong> · By Sourov Deb</p>

<p>For the past few weeks, I've been building a system to automatically find English teaching and training opportunities in France using the official France Travail API. As someone based in La Réunion managing ADHD, bipolar, and a full CELTA qualification process, I needed something stable, low-maintenance, and repeatable — not another manual job board hunt that drains my energy.</p>

<h2>The Tools I Used</h2>

<p>I built everything inside Google Apps Script (JavaScript in the cloud). The core tools were:</p>

<ul>
<li><strong>France Travail Offres d'emploi v2 API</strong> — the official source for active job offers across France (including DOM-TOM).</li>
<li><strong>OpenRouter</strong> — to enrich leads with AI. I used Claude 3.5 Haiku as the main model and added several free fallback models when the primary one was slow or unavailable.</li>
<li><strong>Google Sheets</strong> — as my main database to store leads, contact names, outreach drafts, and status.</li>
<li><strong>Google Apps Script</strong> — to connect everything: pull data, enrich it with AI, write to Sheet, and (soon) send emails.</li>
</ul>

<p>I also kept an optional GitHub backup layer for summaries, though I'm keeping most sensitive contact data only in my private Sheet.</p>

<h2>The Real Obstacles</h2>

<p>The biggest difficulties weren't the API itself — it was the friction in the development process.</p>

<p>Apps Script's editor sometimes refuses to show functions in the dropdown even when the code is correct. I spent hours fighting with a <code>setup_OnceOnly_()</code> function that simply wouldn't appear. After multiple failed attempts and reloads, I eventually hardcoded the credentials directly into the script just to move forward. It's not ideal for security, but it let me actually test and get results.</p>

<p>Another challenge was data quality. France Travail gives good job titles and descriptions, but direct emails and contact persons are often missing. Many leads only have generic "apply through France Travail" links. This forced me to focus more on generating strong outreach drafts that candidates can use manually or via email when contacts become available.</p>

<p>Balancing speed with safety was also constant. I kept a strict <strong>DRY_RUN</strong> mode for a long time so I wouldn't accidentally spam anyone while testing.</p>

<h2>What I Achieved So Far</h2>

<p>In one run I pulled and enriched <strong>159 leads</strong>. The system now:</p>

<ul>
<li>Searches multiple keywords across regions</li>
<li>Uses AI (with free fallback models) to score fit and write personalized French outreach messages</li>
<li>Stores everything cleanly in my Google Sheet with proper columns for follow-up</li>
</ul>

<p>The outreach drafts already use the correct CELTA phrasing I need: <em>"Formation Cambridge CELTA complétée — 120 heures supervisées, 4 travaux écrits validés au standard requis, qualification en appel."</em></p>

<h2>Next Steps</h2>

<p>I'm now turning off dry-run mode so the leads actually save into my Sheet. After that I plan to add controlled email sending (maximum 20 per run) with attachments. The long-term goal is a stable, low-energy system that surfaces real opportunities without me having to stare at job boards every day.</p>

<p>Building this reminded me that automation is rarely "set and forget" — especially when you're working with official APIs, quirky editors, and incomplete public data. But once the foundation is solid, it becomes a genuine quality-of-life tool.</p>
"""

payload = {
    "title": "Building My Own Job Lead Automation System (and Why It Was Harder Than I Expected)",
    "content": CONTENT.strip(),
    "status": "future",
    "date": "2026-06-25T08:00:00",
    "categories": [56],
    "tags": "job automation, France Travail API, Google Apps Script, OpenRouter, CELTA, La Réunion, ADHD, English teaching, career development, job search",
    "meta_description": "How I built a Google Apps Script + France Travail API + OpenRouter system to automate English teaching job leads in France — real obstacles, 159 enriched leads, and next steps.",
    "seo_title": "Building My Own Job Lead Automation System (France Travail API) | Sourov Deb",
}

req = urllib.request.Request(
    API_URL,
    json.dumps(payload).encode(),
    method="POST",
    headers={"X-Sourov-Key": API_KEY, "Content-Type": "application/json"},
)
with urllib.request.urlopen(req, timeout=120) as resp:
    result = json.loads(resp.read().decode())

# Set slug via deploy.php runner
if result.get("success") and result.get("post_id"):
    import base64
    import urllib.parse

    post_id = result["post_id"]
    slug = "building-job-lead-automation-system-france-travail"
    php = f"""<?php
header('Content-Type: application/json');
if (($_GET['key'] ?? '') !== '0767044896thevenet_') {{ http_response_code(403); exit('{{"error":"forbidden"}}'); }}
require_once('/home/u839078121/domains/sourovdeb.com/public_html/wp-load.php');
$r = wp_update_post(['ID'=>{post_id},'post_name'=>'{slug}'], true);
$out = ['post_id'=>{post_id},'slug'=>'{slug}','success'=>!is_wp_error($r),'link'=>get_permalink({post_id})];
$out['self_deleted']=@unlink(__FILE__);
echo json_encode($out);
"""
    data = urllib.parse.urlencode({
        "action": "upload",
        "path": f"slug-job-auto-{post_id}.php",
        "encoded": "true",
        "content": base64.b64encode(php.encode()).decode(),
    }).encode()
    urllib.request.urlopen(
        urllib.request.Request(
            f"https://www.sourovdeb.com/deploy.php?key={API_KEY}",
            data, method="POST",
            headers={"Content-Type": "application/x-www-form-urlencoded"},
        ),
        timeout=60,
    ).read()
    import time
    time.sleep(1)
    with urllib.request.urlopen(
        f"https://www.sourovdeb.com/slug-job-auto-{post_id}.php?key={API_KEY}", timeout=60
    ) as r:
        result["slug_set"] = json.loads(r.read().decode())

print(json.dumps(result, indent=2, ensure_ascii=False))
