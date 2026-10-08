<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$layout = file_get_contents($root . '/app/Views/layouts/app.php');
$css = file_get_contents($root . '/public/css/core/app.css');
$js = file_get_contents($root . '/public/js/sidebar-collapse.js');

if ($layout === false || $css === false || $js === false) {
    throw new RuntimeException('Sidebar collapse files could not be read.');
}

$groupIds = ['operation', 'inventory', 'catalogs', 'organization', 'administration', 'account'];
preg_match_all('/data-sidebar-group="([a-z-]+)"/', $layout, $groupMatches);
$renderedGroups = $groupMatches[1] ?? [];

if ($renderedGroups !== $groupIds) {
    throw new RuntimeException('Sidebar group IDs do not match the approved tree.');
}

$navigationMarkup = preg_match('/<nav\b[^>]*>(.*?)<\/nav>/is', $layout, $navigationMatch) === 1
    ? $navigationMatch[1]
    : '';
preg_match_all('/href="(\/[^"#]+)"/', $navigationMarkup, $linkMatches);
$sidebarLinks = $linkMatches[1] ?? [];

if (count($sidebarLinks) !== 20 || count($sidebarLinks) !== count(array_unique($sidebarLinks))) {
    throw new RuntimeException('Sidebar must contain exactly 20 unique links.');
}

foreach ($groupIds as $groupId) {
    $targetId = 'sidebar-group-' . $groupId;
    if (!str_contains($layout, 'type="button" aria-expanded="true" aria-controls="' . $targetId . '"')
        || substr_count($layout, 'id="' . $targetId . '"') !== 1
    ) {
        throw new RuntimeException('Accessible toggle contract is incomplete for: ' . $groupId);
    }
}

if (!str_contains($layout, 'aria-current="page"')
    || !str_contains($layout, 'href="/app"')
    || !str_contains($layout, 'class="app-navigation__item')
) {
    throw new RuntimeException('Root navigation or active-link markup is missing.');
}

if (!str_contains($css, '.app-navigation {')
    || !preg_match('/\.app-navigation\s*\{[^}]*overflow-y:\s*auto;/s', $css)
    || !str_contains($css, '.app-navigation__group-content[hidden]')
) {
    throw new RuntimeException('Vertical scroll or no-JS fallback contract is missing.');
}

if (!preg_match('/\.app-sidebar\s*\{[^}]*position:\s*sticky;[^}]*top:\s*0;[^}]*align-self:\s*start;[^}]*height:\s*100vh;[^}]*height:\s*100dvh;/s', $css)) {
    throw new RuntimeException('Desktop sidebar sticky viewport contract is missing.');
}

if (!preg_match('/@media\s*\(max-width:\s*48rem\)\s*\{.*?\.app-sidebar\s*\{[^}]*position:\s*static;[^}]*height:\s*auto;[^}]*min-height:\s*auto;/s', $css)) {
    throw new RuntimeException('Responsive sidebar sticky override is missing.');
}

if (preg_match('/\.app-shell\s*\{[^}]*overflow\s*:/s', $css)
    || preg_match('/body\s*\{[^}]*overflow\s*:/s', $css)
) {
    throw new RuntimeException('A layout ancestor may block sticky positioning.');
}

foreach ([
    "r_erp_sidebar_groups_v1",
    "localStorage.setItem",
    "activeId",
] as $contract) {
    if (!str_contains($js, $contract)) {
        throw new RuntimeException('Sidebar JS contract is missing: ' . $contract);
    }
}

if (str_contains($js, 'fetch(')
    || str_contains($js, 'document.cookie')
    || str_contains($js, 'session')
) {
    throw new RuntimeException('Sidebar JS must remain presentation-only.');
}

echo 'PASS collapse groups=6 root=1 items=20 accessible=6 scroll=auto storage=ux-only sticky=desktop responsive=static' . PHP_EOL;
