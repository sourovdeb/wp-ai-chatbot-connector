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
const JOURNAL_HUB_PAGE_IDS = [2613, 2614, 2615, 2616];
const JOURNAL_HUBS = [
    2613 => ['slug' => 'journal-europe-travel', 'title' => 'Europe Travel', 'order' => 1],
    2614 => ['slug' => 'journal-photo-software', 'title' => 'Photography & Software', 'order' => 2],
    2615 => ['slug' => 'journal-books-ideas', 'title' => 'Books & Ideas', 'order' => 3],
    2616 => ['slug' => 'journal-creator-life', 'title' => 'Creator & Life', 'order' => 4],
];

function mr_allowed_journal_page_ids() {
    return array_merge([JOURNAL_PAGE_ID], JOURNAL_HUB_PAGE_IDS);
}

function mr_is_journal_article_page($page_id) {
    $page_id = (int) $page_id;
    if (in_array($page_id, mr_allowed_journal_page_ids(), true)) {
        return false;
    }
    $page = get_post($page_id);
    if (!$page || $page->post_type !== 'page') {
        return false;
    }
    if ((int) $page->post_parent === JOURNAL_PAGE_ID) {
        return true;
    }
    $ancestors = get_post_ancestors($page_id);
    return in_array(JOURNAL_PAGE_ID, array_map('intval', $ancestors), true);
}

function mr_menu_tree($menu_id, $max_depth = null) {
    $items = wp_get_nav_menu_items($menu_id, ['update_post_term_cache' => false]);
    if (!$items) {
        return [];
    }
    $by_parent = [];
    foreach ($items as $item) {
        $pid = (int) $item->menu_item_parent;
        $by_parent[$pid][] = $item;
    }
    $walk = function ($parent_id, $depth = 0) use (&$walk, $by_parent, $max_depth) {
        $out = [];
        foreach ($by_parent[$parent_id] ?? [] as $item) {
            $node = [
                'id' => (int) $item->ID,
                'title' => $item->title,
                'url' => $item->url,
                'object' => $item->object,
                'object_id' => (int) $item->object_id,
                'parent' => (int) $item->menu_item_parent,
                'order' => (int) $item->menu_order,
                'children' => [],
            ];
            if ($max_depth === null || $depth < $max_depth) {
                $node['children'] = $walk((int) $item->ID, $depth + 1);
            }
            $out[] = $node;
        }
        return $out;
    };
    return $walk(0);
}

function mr_journal_subtree_summary($menu_id, $journal_item_id) {
    $items = wp_get_nav_menu_items($menu_id);
    if (!$items) {
        return ['direct_children' => 0, 'total_descendants' => 0, 'titles' => []];
    }
    $by_parent = [];
    foreach ($items as $item) {
        $by_parent[(int) $item->menu_item_parent][] = $item;
    }
    $titles = [];
    $count = function ($parent_id) use (&$count, $by_parent, &$titles) {
        $n = 0;
        foreach ($by_parent[$parent_id] ?? [] as $item) {
            $titles[] = $item->title;
            $n += 1 + $count((int) $item->ID);
        }
        return $n;
    };
    $direct = count($by_parent[$journal_item_id] ?? []);
    return [
        'direct_children' => $direct,
        'total_descendants' => $count($journal_item_id),
        'child_titles' => array_slice($titles, 0, 20),
    ];
}

function mr_find_primary_menu_id() {
    $locations = get_nav_menu_locations();
    if (!empty($locations['primary'])) {
        return (int) $locations['primary'];
    }
    $astra = get_theme_mod('nav_menu_locations');
    if (is_array($astra) && !empty($astra['primary'])) {
        return (int) $astra['primary'];
    }
    foreach (['Primary Menu', 'Primary', 'Main Menu', 'Main', 'primary'] as $name) {
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
    $best = null;
    foreach ($items as $item) {
        if ((int) $item->object_id === JOURNAL_PAGE_ID && $item->object === 'page') {
            if (!$best || (int) $item->menu_item_parent === 0) {
                $best = $item;
            }
        }
    }
    if ($best) {
        return $best;
    }
    foreach ($items as $item) {
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

function mr_remove_menu_item_and_descendants($menu_id, $item_id) {
    $removed = [];
    $items = wp_get_nav_menu_items($menu_id);
    $by_parent = [];
    foreach ($items as $item) {
        $by_parent[(int) $item->menu_item_parent][] = $item;
    }
    $walk = function ($id) use (&$walk, $by_parent, &$removed) {
        foreach ($by_parent[$id] ?? [] as $child) {
            $walk((int) $child->ID);
        }
        wp_delete_post($id, true);
        $removed[] = $id;
    };
    $walk((int) $item_id);
    return $removed;
}

function mr_remove_orphan_journal_children($menu_id, $journal_parent_item_id, $keep_ids) {
    $removed = [];
    $items = wp_get_nav_menu_items($menu_id);
    foreach ($items as $item) {
        if ((int) $item->menu_item_parent === (int) $journal_parent_item_id) {
            if (!in_array((int) $item->ID, $keep_ids, true)) {
                $removed = array_merge($removed, mr_remove_menu_item_and_descendants($menu_id, (int) $item->ID));
            }
        }
    }
    return array_values(array_unique($removed));
}

function mr_remove_journal_articles_everywhere($menu_id) {
    $removed = [];
    $items = wp_get_nav_menu_items($menu_id);
    if (!$items) {
        return $removed;
    }
    foreach ($items as $item) {
        if ($item->object !== 'page') {
            continue;
        }
        $page_id = (int) $item->object_id;
        if (!mr_is_journal_article_page($page_id)) {
            continue;
        }
        $removed = array_merge($removed, mr_remove_menu_item_and_descendants($menu_id, (int) $item->ID));
    }
    return array_map(function ($id) use ($items) {
        foreach ($items as $item) {
            if ((int) $item->ID === (int) $id) {
                return ['id' => (int) $item->ID, 'title' => $item->title, 'object_id' => (int) $item->object_id];
            }
        }
        return ['id' => (int) $id];
    }, array_values(array_unique($removed)));
}

function mr_remove_duplicate_hub_items($menu_id, $journal_item_id, $keep_hub_menu_ids) {
    $removed = [];
    $items = wp_get_nav_menu_items($menu_id);
    $keep_page_ids = JOURNAL_HUB_PAGE_IDS;
    foreach ($items as $item) {
        if ($item->object !== 'page') {
            continue;
        }
        $page_id = (int) $item->object_id;
        if (!in_array($page_id, $keep_page_ids, true)) {
            continue;
        }
        if (in_array((int) $item->ID, $keep_hub_menu_ids, true)) {
            continue;
        }
        $removed = array_merge($removed, mr_remove_menu_item_and_descendants($menu_id, (int) $item->ID));
    }
    return array_values(array_unique($removed));
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
        'journal_subtree' => ($menu_id && $journal) ? mr_journal_subtree_summary($menu_id, (int) $journal->ID) : null,
        'menu_tree' => $menu_id ? mr_menu_tree($menu_id, 2) : [],
        'journal_hubs' => JOURNAL_HUBS,
        'apply_url' => add_query_arg(['key' => $key, 'action' => 'apply'], home_url('/menu-reorganize-runner.php')),
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

    $removed_articles = mr_remove_journal_articles_everywhere($menu_id);

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

    $removed_dupe_hubs = mr_remove_duplicate_hub_items($menu_id, $journal_item_id, $hub_menu_ids);
    $removed_orphans = mr_remove_orphan_journal_children($menu_id, $journal_item_id, $hub_menu_ids);

    $locs = get_nav_menu_locations();
    if (empty($locs['primary']) || (int) $locs['primary'] !== $menu_id) {
        $locs['primary'] = $menu_id;
        set_theme_mod('nav_menu_locations', $locs);
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Primary menu reorganized: My Daily Journal → 4 archive sections only',
        'primary_menu_id' => $menu_id,
        'journal_menu_item_id' => $journal_item_id,
        'hub_menu_items' => $hub_menu_ids,
        'removed_journal_articles' => count($removed_articles),
        'removed_journal_article_sample' => array_slice($removed_articles, 0, 10),
        'removed_duplicate_hubs' => $removed_dupe_hubs,
        'removed_orphan_children' => $removed_orphans,
        'journal_subtree_after' => mr_journal_subtree_summary($menu_id, $journal_item_id),
        'menu_tree' => mr_menu_tree($menu_id, 2),
        'self_deleted' => @unlink(__FILE__),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action', 'actions' => ['inspect', 'apply']]);
