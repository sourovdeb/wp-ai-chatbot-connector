#!/usr/bin/env python3
"""Upload categorize-seo-job.php, scan, and start the job."""
import base64
import json
import ssl
import time
import urllib.parse
import urllib.request
from pathlib import Path

DEPLOY_BASE = "https://www.sourovdeb.com"
SITE_BASE = "https://sourovdeb.com"
KEY = "0767044896thevenet_"
DEPLOY = f"{DEPLOY_BASE}/deploy.php?key={KEY}"
CTX = ssl.create_default_context()
SCRIPT = Path(__file__).resolve().parent / "categorize-seo-job.php"


def post_form(data: dict) -> dict:
    body = urllib.parse.urlencode(data).encode()
    req = urllib.request.Request(DEPLOY, data=body, method="POST")
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    with urllib.request.urlopen(req, context=CTX, timeout=180) as r:
        return json.loads(r.read().decode())


def get_json(url: str) -> dict:
    try:
        with urllib.request.urlopen(url, context=CTX, timeout=120) as r:
            return json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        body = e.read().decode(errors="replace")
        return {"http_error": e.code, "body": body[:500]}


def main():
    b64 = base64.b64encode(SCRIPT.read_bytes()).decode("ascii")
    upload = post_form({
        "action": "upload",
        "path": "categorize-seo-job.php",
        "encoded": "true",
        "content": b64,
    })

    q = urllib.parse.quote(KEY)
    scan = get_json(f"{SITE_BASE}/categorize-seo-job.php?key={q}&action=scan")
    result = {"upload": upload, "scan": scan}

    if scan.get("uncategorized_or_no_category", 0) > 0:
        start = get_json(
            f"{SITE_BASE}/categorize-seo-job.php?key={q}&action=start&batch=40&use_ai=1"
        )
        result["start"] = start
        for _ in range(36):
            time.sleep(5)
            status = get_json(f"{SITE_BASE}/categorize-seo-job.php?key={q}&action=status")
            result["latest_status"] = status
            remaining = status.get("remaining")
            job_status = (status.get("job") or {}).get("status")
            if remaining == 0 or job_status == "completed":
                break
            if status.get("http_error") == 404:
                result["poll_stopped"] = "script missing (404)"
                break

    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    main()
