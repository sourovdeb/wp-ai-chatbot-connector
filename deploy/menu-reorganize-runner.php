<?php
/**
 * Inspect and reorganize Primary menu — My Daily Journal dropdown
 * GET ?key=SECRET&action=inspect
 * GET ?key=SECRET&action=apply
 */
require_once dirname(__FILE__) . '/wp-load.php';
header('Content-Type: application/json; charset=utf-8');

$secret = get_option('sourov_ai_secret_key', '0767044896thevenet_');
$key = $_GET['key'] ?? '';
if (!$secret || !hash_equals((string) $secret, (string) $key)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

const JOURNAL_PAGE_ID = 39;
const JOURNAL_HUBS = [
    2613 => ['slug' => 'journal-europe-travel', 'title' => 'Europe Travel', 'order' => 1],
    2614 => ['slug' => 'journal-photo-software', 'title' => 'Photography & Software', 'order' => 2],
    2615 => ['slug' => 'journal-books-ideas', 'title' => 'Books & Ideas', 'order' => 3],
    2616 => ['slug' => 'journal-creator-life', 'title' => 'Creator & Life', 'order' => 4],
];

function mr_menu_tree($menu_id) {
    $items = wp_get_nav_menu_items($menu_id, ['update_post_term_cache' => false]);
    if (!$items) {
        return [];
    }
    $by_parent = [];
    foreach ($items as $item) {
        $pid = (int) $item->menu_item_parent;
        $by_parent[$pid][] = $item;
    }
    $walk = function ($parent_id) use (&$walk, $by_parent) {
        $out = [];
        foreach ($by_parent[$parent_id] ?? [] as $item) {
            $out[] = [
                'id' => (int) $item->ID,
                'title' => $item->title,
                'url' => $item->url,
                'object' => $item->object,
                'object_id' => (int) $item->object_id,
                'parent' => (int) $item->menu_item_parent,
                'order' => (int) $item->menu_order,
                'children' => $walk((int) $item->ID),
            ];
        }
        return $out;
    };
    return $walk(0);
}

function mr_find_primary_menu_id() {
    $locations = get_nav_menu_locations();
    if (!empty($locations['primary'])) {
        return (int) $locations['primary'];
    }
    foreach (['primary', 'menu-1'] as $loc) {
        if (!empty($locations[$loc])) {
            return (int) $locations[$loc];
        }
    }
    foreach (['Primary', 'Main Menu', 'Main', 'primary'] as $name) {
        $menu = wp_get_nav_menu_object($name);
        if ($menu) {
            return (int) $menu->term_id;
        }
    }
    $menus = wp_get_nav_menus();
    if (empty($menus)) {
        return 0;
    }
    usort($menus, fn($a, $b) => (int) $b->count - (int) $a->count);
    return (int) $menus[0]->term_id;
}

function mr_find_journal_parent_item($menu_id) {
    $items = wp_get_nav_menu_items($menu_id);
    if (!$items) {
        return null;
    }
    foreach ($items as $item) {
        if ((int) $item->object_id === JOURNAL_PAGE_ID && $item->object === 'page') {
            return $item;
        }
        if (stripos($item->title, 'daily journal') !== false || stripos($item->url, 'my-daily-journal') !== false) {
            return $item;
        }
    }
    return null;
}

function mr_ensure_menu_item($menu_id, $args) {
    $existing_id = $args['existing_id'] ?? 0;
    if ($existing_id) {
        return (int) $existing_id;
    }
    $id = wp_update_nav_menu_item($menu_id, 0, [
        'menu-item-title' => $args['title'],
        'menu-item-object' => 'page',
        'menu-item-object-id' => $args['page_id'],
        'menu-item-type' => 'post_type',
        'menu-item-status' => 'publish',
        'menu-item-parent-id' => $args['parent_id'] ?? 0,
        'menu-item-position' => $args['position'] ?? 0,
    ]);
    return is_wp_error($id) ? 0 : (int) $id;
}

function mr_remove_orphan_journal_children($menu_id, $journal_parent_item_id, $keep_ids) {
    $removed = [];
    $items = wp_get_nav_menu_items($menu_id);
    foreach ($items as $item) {
        if ((int) $item->menu_item_parent === (int) $journal_parent_item_id) {
            if (!in_array((int) $item->ID, $keep_ids, true)) {
                wp_delete_post($item->ID, true);
                $removed[] = ['id' => (int) $item->ID, 'title' => $item->title];
            }
        }
    }
    return $removed;
}

function mr_remove_deep_children($menu_id, $parent_ids) {
    $removed = [];
    $items = wp_get_nav_menu_items($menu_id);
    foreach ($items as $item) {
        if (in_array((int) $item->menu_item_parent, $parent_ids, true)) {
            wp_delete_post($item->ID, true);
            $removed[] = ['id' => (int) $item->ID, 'title' => $item->title, 'parent' => (int) $item->menu_item_parent];
        }
    }
    return $removed;
}

$action = $_GET['action'] ?? 'inspect';

if ($action === 'inspect') {
    $menu_id = mr_find_primary_menu_id();
    $locations = get_nav_menu_locations();
    $journal = $menu_id ? mr_find_journal_parent_item($menu_id) : null;
    $menus = array_map(fn($m) => [
        'id' => (int) $m->term_id,
        'name' => $m->name,
        'slug' => $m->slug,
        'count' => (int) $m->count,
    ], wp_get_nav_menus());

    echo json_encode([
        'ok' => true,
        'active_theme' => get_option('stylesheet'),
        'menu_locations' => $locations,
        'primary_menu_id' => $menu_id,
        'primary_menu_name' => $menu_id ? (wp_get_nav_menu_object($menu_id)->name ?? null) : null,
        'all_menus' => $menus,
        'journal_menu_item' => $journal ? [
            'id' => (int) $journal->ID,
            'title' => $journal->title,
            'url' => $journal->url,
            'parent' => (int) $journal->menu_item_parent,
        ] : null,
        'menu_tree' => $menu_id ? mr_menu_tree($menu_id) : [],
        'journal_hubs' => JOURNAL_HUBS,
        'apply_url' => add_query_arg(['key' => $key, 'action' => 'apply'], site_url('/menu-reorganize-runner.php')),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'apply') {
    $menu_id = mr_find_primary_menu_id();
    if (!$menu_id) {
        $menu_id = wp_create_nav_menu('Primary');
        if (is_wp_error($menu_id)) {
            echo json_encode(['error' => 'could not create menu', 'detail' => $menu_id->get_error_message()]);
            exit;
        }
        $menu_id = (int) $menu_id;
        $locs = get_nav_menu_locations();
        $locs['primary'] = $menu_id;
        set_theme_mod('nav_menu_locations', $locs);
    }

    $journal_item = mr_find_journal_parent_item($menu_id);
    $journal_item_id = 0;
    if ($journal_item) {
        $journal_item_id = (int) $journal_item->ID;
    } else {
        $journal_item_id = mr_ensure_menu_item($menu_id, [
            'title' => 'My Daily Journal',
            'page_id' => JOURNAL_PAGE_ID,
            'position' => 50,
        ]);
    }

    if (!$journal_item_id) {
        echo json_encode(['error' => 'could not ensure journal parent menu item']);
        exit;
    }

    $hub_menu_ids = [];
    $existing_children = [];
    $items = wp_get_nav_menu_items($menu_id);
    foreach ($items as $item) {
        if ((int) $item->menu_item_parent === $journal_item_id) {
            $existing_children[(int) $item->object_id] = (int) $item->ID;
        }
    }

    $position = 1;
    foreach (JOURNAL_HUBS as $page_id => $hub) {
        $existing_id = $existing_children[$page_id] ?? 0;
        $mid = mr_ensure_menu_item($menu_id, [
            'title' => $hub['title'],
            'page_id' => $page_id,
            'parent_id' => $journal_item_id,
            'position' => $position,
            'existing_id' => $existing_id,
        ]);
        if ($mid) {
            wp_update_nav_menu_item($menu_id, $mid, [
                'menu-item-title' => $hub['title'],
                'menu-item-object' => 'page',
                'menu-item-object-id' => $page_id,
                'menu-item-type' => 'post_type',
                'menu-item-status' => 'publish',
                'menu-item-parent-id' => $journal_item_id,
                'menu-item-position' => $position,
            ]);
            $hub_menu_ids[] = $mid;
        }
        $position++;
    }

    $removed_orphans = mr_remove_orphan_journal_children($menu_id, $journal_item_id, $hub_menu_ids);
    $removed_deep = mr_remove_deep_children($menu_id, $hub_menu_ids);

    $locs = get_nav_menu_locations();
    if (empty($locs['primary']) || (int) $locs['primary'] !== $menu_id) {
        $locs['primary'] = $menu_id;
        set_theme_mod('nav_menu_locations', $locs);
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Primary menu reorganized: My Daily Journal → 4 archive sections',
        'primary_menu_id' => $menu_id,
        'journal_menu_item_id' => $journal_item_id,
        'hub_menu_items' => $hub_menu_ids,
        'removed_orphan_children' => $removed_orphans,
        'removed_deep_children' => $removed_deep,
        'menu_tree' => mr_menu_tree($menu_id),
        'self_deleted' => @unlink(__FILE__),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action', 'actions' => ['inspect', 'apply']]);
