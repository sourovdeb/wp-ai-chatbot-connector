<?php
/**
 * crawl-page.php
 * Simple, safe function to crawl one webpage and extract clean content.
 * Upload via deploy.php. No external libraries needed.
 * Usage: include this file, then $result = crawl_page('https://example.com');
 */

// Prevent direct access if needed
if (!defined('ABSPATH') && php_sapi_name() !== 'cli') {
    // Allow direct run for testing
}

function crawl_page($url, $max_content_length = 8000) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['error' => 'Invalid URL'];
    }

    // Fetch with proper headers (polite crawler)
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'WP-Copilot-Crawler/1.0 (+https://www.sourovdeb.com)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$html) {
        return ['error' => "Failed to fetch page. HTTP $http_code"];
    }

    // Parse with DOMDocument (built-in, no extra deps)
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Extract title
    $titleNode = $xpath->query('//title')->item(0);
    $title = $titleNode ? trim($titleNode->textContent) : 'Untitled';

    // Try to get main content (common article selectors)
    $contentSelectors = [
        '//article',
        '//main',
        '//div[@class="entry-content"]',
        '//div[@class="post-content"]',
        '//div[contains(@class, "content")]',
        '//body'
    ];

    $content = '';
    foreach ($contentSelectors as $selector) {
        $nodes = $xpath->query($selector);
        if ($nodes->length > 0) {
            $content = $nodes->item(0)->textContent;
            break;
        }
    }

    // Clean content
    $content = preg_replace('/\s+/', ' ', strip_tags($content));
    $content = trim(substr($content, 0, $max_content_length));

    // Meta description
    $metaDesc = '';
    $metaNodes = $xpath->query('//meta[@name="description"]/@content');
    if ($metaNodes->length > 0) {
        $metaDesc = trim($metaNodes->item(0)->textContent);
    }

    // Collect internal links (simple)
    $links = [];
    $linkNodes = $xpath->query('//a[@href]');
    foreach ($linkNodes as $node) {
        $href = $node->getAttribute('href');
        if (strpos($href, 'http') === 0 || strpos($href, '/') === 0) {
            $links[] = $href;
        }
        if (count($links) >= 10) break; // limit
    }

    return [
        'url' => $url,
        'title' => $title,
        'meta_description' => $metaDesc,
        'content' => $content,
        'links' => array_unique($links),
        'fetched_at' => date('c'),
        'status' => 'success'
    ];
}
