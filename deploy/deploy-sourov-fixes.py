#!/usr/bin/env python3
"""Deploy sourov-ai-controller v1.2 + automation status fix to sourovdeb.com"""
import base64
import json
import re
import ssl
import urllib.parse
import urllib.request
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
BASE = "https://www.sourovdeb.com"
KEY = "0767044896thevenet_"
DEPLOY = f"{BASE}/deploy.php?key={KEY}"
CTX = ssl.create_default_context()

HELPER_PHP = r'''
function sourov_automation_engine_status($engine, $settings) {
    $homepage = '';
    $response = wp_remote_get(home_url('/'), ['timeout' => 15, 'sslverify' => true]);
    if (!is_wp_error($response)) {
        $homepage = wp_remote_retrieve_body($response);
    }
    switch ($engine) {
        case 'google':
            if (!empty($settings['gsc_meta_tag'])) {
                return ['label' => '✅ Configured (meta tag)', 'meta' => $settings['gsc_meta_tag'], 'ok' => true];
            }
            if (!empty($settings['gsc_dns_verified'])) {
                return ['label' => '✅ Verified (DNS TXT)', 'meta' => $settings['gsc_dns_note'] ?? 'Namecheap DNS', 'ok' => true];
            }
            if (function_exists('is_plugin_active') && is_plugin_active('google-site-kit/google-site-kit.php')) {
                return ['label' => '✅ Verified (Site Kit)', 'meta' => 'google-site-kit active', 'ok' => true];
            }
            if ($homepage && stripos($homepage, 'google-site-verification') !== false) {
                return ['label' => '✅ Detected on homepage', 'meta' => 'google-site-verification meta present', 'ok' => true];
            }
            return ['label' => '⚠️ Not Set', 'meta' => '', 'ok' => false];
        case 'bing':
            if (!empty($settings['bing_meta_tag'])) {
                $ok = $homepage && stripos($homepage, 'msvalidate.01') !== false;
                return ['label' => $ok ? '✅ Configured' : '⚠️ Saved (not on homepage)', 'meta' => $settings['bing_meta_tag'], 'ok' => $ok];
            }
            return ['label' => '⚠️ Not Set', 'meta' => '', 'ok' => false];
        case 'yandex':
            if (!empty($settings['yandex_meta_tag'])) {
                $ok = $homepage && stripos($homepage, 'yandex-verification') !== false;
                return ['label' => $ok ? '✅ Configured' : '⚠️ Saved (not on homepage)', 'meta' => $settings['yandex_meta_tag'], 'ok' => $ok];
            }
            return ['label' => '⚠️ Not Set', 'meta' => '', 'ok' => false];
    }
    return ['label' => '⚠️ Unknown', 'meta' => '', 'ok' => false];
}
'''


def post_form(data: dict) -> dict:
    body = urllib.parse.urlencode(data).encode('utf-8')
    req = urllib.request.Request(DEPLOY, data=body, method='POST')
    req.add_header('Content-Type', 'application/x-www-form-urlencoded')
    with urllib.request.urlopen(req, context=CTX, timeout=180) as resp:
        return json.loads(resp.read().decode('utf-8'))


def get_json(url: str) -> dict:
    with urllib.request.urlopen(url, context=CTX, timeout=120) as resp:
        return json.loads(resp.read().decode('utf-8'))


def upload_file(remote_path: str, local_path: Path) -> dict:
    content = base64.b64encode(local_path.read_bytes()).decode('ascii')
    return post_form({
        'action': 'upload',
        'path': remote_path,
        'encoded': 'true',
        'content': content,
    })


def download_file(remote_path: str) -> bytes:
    url = f"{BASE}/deploy.php?action=download&key={urllib.parse.quote(KEY)}&path={urllib.parse.quote(remote_path)}"
    data = get_json(url)
    if data.get('status') != 'ok':
        raise RuntimeError(data)
    return base64.b64decode(data['content'])


def patch_automation_agent(raw: bytes) -> bytes:
    text = raw.decode('utf-8')
    if 'sourov_automation_engine_status' not in text:
        text = text.replace(
            "    public function inject_verification_tags() {",
            HELPER_PHP + "\n    public function inject_verification_tags() {",
            1,
        )

    google_row = """<td style="color: <?php echo ! empty( $settings['gsc_meta_tag'] ) ? 'green' : 'orange'; ?>;">
                                            <?php echo ! empty( $settings['gsc_meta_tag'] ) ? '✅ Configured' : '⚠️ Not Set'; ?>
                                        </td>
                                        <td><code><?php echo substr( $settings['gsc_meta_tag'] ?? '', 0, 50 ) . '...'; ?></code></td>"""

    google_new = """<?php $g_status = sourov_automation_engine_status('google', $settings); ?>
                                        <td style="color: <?php echo $g_status['ok'] ? 'green' : 'orange'; ?>;">
                                            <?php echo esc_html($g_status['label']); ?>
                                        </td>
                                        <td><code><?php echo esc_html(substr($g_status['meta'] ?? '', 0, 50)); ?><?php echo !empty($g_status['meta']) ? '...' : ''; ?></code></td>"""

    if google_row in text:
        text = text.replace(google_row, google_new)
    else:
        text = re.sub(
            r"<td><strong>Google Search Console</strong></td>[\s\S]*?<td><a href=.*?sourov-automation-gsc.*?>Edit</a></td>",
            """<td><strong>Google Search Console</strong></td>
                                        <?php $g_status = sourov_automation_engine_status('google', $settings); ?>
                                        <td style="color: <?php echo $g_status['ok'] ? 'green' : 'orange'; ?>;">
                                            <?php echo esc_html($g_status['label']); ?>
                                        </td>
                                        <td><code><?php echo esc_html(substr($g_status['meta'] ?? '', 0, 50)); ?><?php echo !empty($g_status['meta']) ? '...' : ''; ?></code></td>
                                        <td><a href="<?php echo admin_url( 'admin.php?page=sourov-automation-gsc' ); ?>">Edit</a></td>""",
            text,
            count=1,
        )

    bing_old = """<td style="color: <?php echo ! empty( $settings['bing_meta_tag'] ) ? 'green' : 'orange'; ?>;">
                                            <?php echo ! empty( $settings['bing_meta_tag'] ) ? '✅ Configured' : '⚠️ Not Set'; ?>
                                        </td>
                                        <td><code><?php echo substr( $settings['bing_meta_tag'] ?? '', 0, 50 ) . '...'; ?></code></td>"""

    bing_new = """<?php $b_status = sourov_automation_engine_status('bing', $settings); ?>
                                        <td style="color: <?php echo $b_status['ok'] ? 'green' : 'orange'; ?>;">
                                            <?php echo esc_html($b_status['label']); ?>
                                        </td>
                                        <td><code><?php echo esc_html(substr($b_status['meta'] ?? '', 0, 50)); ?><?php echo !empty($b_status['meta']) ? '...' : ''; ?></code></td>"""

    if bing_old in text:
        text = text.replace(bing_old, bing_new)

    yandex_old = """<td style="color: <?php echo ! empty( $settings['yandex_meta_tag'] ) ? 'green' : 'orange'; ?>;">
                                            <?php echo ! empty( $settings['yandex_meta_tag'] ) ? '✅ Configured' : '⚠️ Not Set'; ?>
                                        </td>
                                        <td><code><?php echo substr( $settings['yandex_meta_tag'] ?? '', 0, 50 ) . '...'; ?></code></td>"""

    yandex_new = """<?php $y_status = sourov_automation_engine_status('yandex', $settings); ?>
                                        <td style="color: <?php echo $y_status['ok'] ? 'green' : 'orange'; ?>;">
                                            <?php echo esc_html($y_status['label']); ?>
                                        </td>
                                        <td><code><?php echo esc_html(substr($y_status['meta'] ?? '', 0, 50)); ?><?php echo !empty($y_status['meta']) ? '...' : ''; ?></code></td>"""

    if yandex_old in text:
        text = text.replace(yandex_old, yandex_new)

    return text.encode('utf-8')


def set_automation_options_runner() -> str:
    return """<?php
require_once dirname(__FILE__) . '/wp-load.php';
header('Content-Type: application/json');
$settings = get_option('sourov_automation_settings', []);
if (!is_array($settings)) $settings = [];
$settings['gsc_dns_verified'] = true;
$settings['gsc_dns_note'] = 'Namecheap TXT google-site-verification (DNS)';
if (empty($settings['bing_meta_tag'])) {
    $settings['bing_meta_tag'] = 'BF2B5489CAEF5D3D7598D5FD07DF0755';
}
update_option('sourov_automation_settings', $settings);
@unlink(__FILE__);
echo json_encode(['ok' => true, 'settings' => $settings]);
"""


def main():
    results = {}

    controller = SCRIPT_DIR / 'sourov-ai-controller-v1.2.php'
    results['controller'] = upload_file('wp-content/plugins/sourov-ai-controller.php', controller)

    automation_raw = download_file('wp-content/plugins/sourov-automation-agent.php')
    patched = patch_automation_agent(automation_raw)
    tmp = SCRIPT_DIR / 'sourov-automation-agent-patched.php'
    tmp.write_bytes(patched)
    results['automation'] = upload_file('wp-content/plugins/sourov-automation-agent.php', tmp)

    runner_path = SCRIPT_DIR / '_tmp_runner.php'
    runner_path.write_text(set_automation_options_runner(), encoding='utf-8')
    results['options_runner'] = upload_file('sourov-fix-automation-options.php', runner_path)

    run_url = f"{BASE}/sourov-fix-automation-options.php"
    with urllib.request.urlopen(run_url, context=CTX, timeout=60) as resp:
        results['options_run'] = json.loads(resp.read().decode('utf-8'))

    status = get_json(f"{BASE}/wp-json/sourov/v1/status")
    results['status'] = status

    print(json.dumps(results, indent=2))


if __name__ == '__main__':
    main()
