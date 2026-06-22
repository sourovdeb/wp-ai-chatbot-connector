#!/usr/bin/env python3
"""Fix sourov-automation-agent.php: move helper to global scope + patch all status rows."""
import base64
import json
import re
import ssl
import urllib.parse
import urllib.request
from pathlib import Path

BASE = "https://www.sourovdeb.com"
KEY = "0767044896thevenet_"
DEPLOY = f"{BASE}/deploy.php?key={KEY}"
CTX = ssl.create_default_context()
OUT = Path(__file__).resolve().parent / "sourov-automation-agent-fixed.php"

HELPER = '''
if (!function_exists('sourov_automation_engine_status')) {
    function sourov_automation_engine_status($engine, $settings) {
        $homepage = '';
        $response = wp_remote_get(home_url('/'), ['timeout' => 15, 'sslverify' => true]);
        if (!is_wp_error($response)) {
            $homepage = wp_remote_retrieve_body($response);
        }
        switch ($engine) {
            case 'google':
                if (!empty($settings['gsc_meta_tag'])) {
                    return ['label' => 'Configured (meta tag)', 'meta' => $settings['gsc_meta_tag'], 'ok' => true];
                }
                if (!empty($settings['gsc_dns_verified'])) {
                    return ['label' => 'Verified (DNS TXT)', 'meta' => $settings['gsc_dns_note'] ?? 'Namecheap DNS', 'ok' => true];
                }
                if (function_exists('is_plugin_active') && is_plugin_active('google-site-kit/google-site-kit.php')) {
                    return ['label' => 'Verified (Site Kit — reconnect if URL changed)', 'meta' => 'google-site-kit active', 'ok' => true];
                }
                if ($homepage && stripos($homepage, 'google-site-verification') !== false) {
                    return ['label' => 'Detected on homepage', 'meta' => 'google-site-verification meta present', 'ok' => true];
                }
                return ['label' => 'Not Set', 'meta' => '', 'ok' => false];
            case 'bing':
                if (!empty($settings['bing_meta_tag'])) {
                    $ok = $homepage && stripos($homepage, 'msvalidate.01') !== false;
                    return ['label' => $ok ? 'Configured' : 'Saved (not on homepage)', 'meta' => $settings['bing_meta_tag'], 'ok' => $ok];
                }
                return ['label' => 'Not Set', 'meta' => '', 'ok' => false];
            case 'yandex':
                if (!empty($settings['yandex_meta_tag'])) {
                    $ok = $homepage && stripos($homepage, 'yandex-verification') !== false;
                    return ['label' => $ok ? 'Configured' : 'Saved (not on homepage)', 'meta' => $settings['yandex_meta_tag'], 'ok' => $ok];
                }
                return ['label' => 'Not Set', 'meta' => '', 'ok' => false];
        }
        return ['label' => 'Unknown', 'meta' => '', 'ok' => false];
    }
}
'''


def download() -> str:
    url = f"{BASE}/deploy.php?action=download&key={urllib.parse.quote(KEY)}&path=wp-content/plugins/sourov-automation-agent.php"
    with urllib.request.urlopen(url, context=CTX, timeout=120) as r:
        data = json.loads(r.read().decode())
    return base64.b64decode(data['content']).decode('utf-8')


def fix(text: str) -> str:
    text = re.sub(
        r'\nfunction sourov_automation_engine_status\([\s\S]*?\n\}\n\n\s+public function inject_verification_tags',
        '\n    public function inject_verification_tags',
        text,
        count=1,
    )

    if 'function sourov_automation_engine_status' not in text:
        text = text.replace(
            'class Sourov_Automation_Agent {',
            HELPER + '\nclass Sourov_Automation_Agent {',
            1,
        )

    for engine, var in [('Google Search Console', 'g'), ('Bing Webmaster', 'b'), ('Yandex', 'y')]:
        block = re.search(
            rf'(<td><strong>{re.escape(engine)}</strong></td>)([\s\S]*?)(</tr>)',
            text,
        )
        if not block:
            continue
        page = 'sourov-automation-gsc' if engine == 'Google Search Console' else 'sourov-automation-other'
        eng_key = {'Google Search Console': 'google', 'Bing Webmaster': 'bing', 'Yandex': 'yandex'}[engine]
        replacement = (
            block.group(1) + '\n'
            f"                                        <?php ${var}_status = sourov_automation_engine_status('{eng_key}', $settings); ?>\n"
            f"                                        <td style=\"color: <?php echo ${var}_status['ok'] ? 'green' : 'orange'; ?>;\">\n"
            f"                                            <?php echo esc_html(${var}_status['label']); ?>\n"
            '                                        </td>\n'
            f"                                        <td><code><?php echo esc_html(substr(${var}_status['meta'] ?? '', 0, 50)); ?><?php echo !empty(${var}_status['meta']) ? '...' : ''; ?></code></td>\n"
            f"                                        <td><a href=\"<?php echo admin_url( 'admin.php?page={page}' ); ?>\">Edit</a></td>\n"
            + block.group(3)
        )
        text = text[:block.start()] + replacement + text[block.end():]

    return text


def upload(content: str) -> dict:
    body = urllib.parse.urlencode({
        'action': 'upload',
        'path': 'wp-content/plugins/sourov-automation-agent.php',
        'encoded': 'true',
        'content': base64.b64encode(content.encode('utf-8')).decode('ascii'),
    }).encode()
    req = urllib.request.Request(DEPLOY, data=body, method='POST')
    req.add_header('Content-Type', 'application/x-www-form-urlencoded')
    with urllib.request.urlopen(req, context=CTX, timeout=180) as r:
        return json.loads(r.read().decode())


def upload_file(remote_path: str, local_path: Path) -> dict:
    content = base64.b64encode(local_path.read_bytes()).decode('ascii')
    body = urllib.parse.urlencode({
        'action': 'upload',
        'path': remote_path,
        'encoded': 'true',
        'content': content,
    }).encode()
    req = urllib.request.Request(DEPLOY, data=body, method='POST')
    req.add_header('Content-Type', 'application/x-www-form-urlencoded')
    with urllib.request.urlopen(req, context=CTX, timeout=180) as r:
        return json.loads(r.read().decode())


def main():
    raw = download()
    fixed = fix(raw)
    OUT.write_text(fixed, encoding='utf-8')
    upload_result = upload(fixed)
    diag_upload = upload_file('site-diagnostic-runner.php', Path(__file__).resolve().parent / 'site-diagnostic-runner.php')

    diag_url = f"{BASE}/site-diagnostic-runner.php?key={urllib.parse.quote(KEY)}"
    with urllib.request.urlopen(diag_url, context=CTX, timeout=120) as r:
        diagnostic = json.loads(r.read().decode())

    print(json.dumps({
        'upload': upload_result,
        'has_global_helper': "if (!function_exists('sourov_automation_engine_status'))" in fixed,
        'helper_inside_class': bool(re.search(r'class Sourov_Automation_Agent[\s\S]*?function sourov_automation_engine_status', fixed)),
        'diagnostic_upload': diag_upload,
        'diagnostic': diagnostic,
    }, indent=2))


if __name__ == '__main__':
    main()
