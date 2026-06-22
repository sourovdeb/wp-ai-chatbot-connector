#!/usr/bin/env python3
"""Upload categorize-seo-job.php, scan, and start the job."""
import base64
import json
import ssl
import time
import urllib.parse
import urllib.request
from pathlib import Path

BASE = "https://www.sourovdeb.com"
KEY = "0767044896thevenet_"
DEPLOY = f"{BASE}/deploy.php?key={KEY}"
CTX = ssl.create_default_context()
SCRIPT = Path(__file__).resolve().parent / "categorize-seo-job.php"


def post_form(data: dict) -> dict:
    body = urllib.parse.urlencode(data).encode()
    req = urllib.request.Request(DEPLOY, data=body, method="POST")
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    with urllib.request.urlopen(req, context=CTX, timeout=180) as r:
        return json.loads(r.read().decode())


def get_json(url: str) -> dict:
    with urllib.request.urlopen(url, context=CTX, timeout=120) as r:
        return json.loads(r.read().decode())


def main():
    b64 = base64.b64encode(SCRIPT.read_bytes()).decode("ascii")
    upload = post_form({
        "action": "upload",
        "path": "categorize-seo-job.php",
        "encoded": "true",
        "content": b64,
    })

    scan = get_json(f"{BASE}/categorize-seo-job.php?key={urllib.parse.quote(KEY)}&action=scan")
    result = {"upload": upload, "scan": scan}

    if scan.get("uncategorized_or_no_category", 0) > 0:
        start = get_json(
            f"{BASE}/categorize-seo-job.php?key={urllib.parse.quote(KEY)}&action=start&batch=40&use_ai=1"
        )
        result["start"] = start
        for _ in range(6):
            time.sleep(5)
            status = get_json(f"{BASE}/categorize-seo-job.php?key={urllib.parse.quote(KEY)}&action=status")
            result["latest_status"] = status
            if not status.get("job") or status.get("job", {}).get("status") == "completed":
                break
            if status.get("remaining", 1) == 0:
                break

    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    main()
