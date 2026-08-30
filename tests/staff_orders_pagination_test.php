<?php

$root = dirname(__DIR__);
$orders = (string)file_get_contents($root . '/staff/orders.php');
$serviceWorker = (string)file_get_contents($root . '/public/sw.php');
$functions = (string)file_get_contents($root . '/includes/functions.php');

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(strpos($orders, '$items_per_page = 15;') !== false, 'Orders must retain the 15-row server page size.');
$assert(strpos($orders, '$current_page = $total_pages > 0 ? min($current_page, $total_pages) : 1;') !== false, 'Out-of-range page requests must resolve to a valid boundary.');
$assert(substr_count($orders, 'render_pagination($current_page, $total_pages, $pagination_params)') === 2, 'Initial and AJAX pagination must preserve the same parameters.');
$assert(strpos($orders, '$pagination_params[\'status\'] = $status_filter;') !== false, 'An explicit valid status parameter must survive page navigation.');
$assert(strpos($orders, 'data-turbo="false"') !== false, 'Orders pagination must use reliable native navigation instead of Turbo interception.');
$assert(strpos($orders, "fetchUpdatedTable({ page: 1 });") !== false, 'Filter changes must reset safely to page 1.');
$assert(strpos($orders, "fetchUpdatedTable({ sort: sortKey, page: 1 });") !== false, 'Sort changes must reset safely to page 1.');
$assert(strpos($orders, "fetchUpdatedTable({}, { silent: true });") !== false, 'Background refresh must preserve the current URL page.');
$assert(strpos($orders, "wrapper.dataset.paginationBound === '1'") !== false, 'Pagination feedback must bind only once.');
$assert(strpos($orders, "wrapper.classList.contains('is-loading')") !== false, 'Duplicate pagination clicks must be suppressed while navigation is pending.');
$assert(substr_count($orders, 'ordersFetchController = new AbortController();') === 1, 'Orders table refresh must retain one request owner.');
$assert(strpos($orders, 'ordersRequestSerial += 1;') !== false, 'Stale Orders responses must remain serial-guarded.');
$assert(substr_count($orders, 'class="action-cell orders-card-actions') >= 2, 'Initial and AJAX rows must use the dedicated equal-width action grid.');
$assert(strpos($orders, 'grid-template-columns: repeat(2, minmax(0, 1fr)) !important;') !== false, 'Mobile View and Message actions must occupy equal grid columns.');
$assert(strpos($serviceWorker, 'networkOnlyDocument(request)') !== false && strpos($serviceWorker, "fetch(request, { cache: 'no-store' })") !== false, 'PHP page navigation must remain network-only in the Service Worker.');

$renderStart = strpos($functions, 'function render_pagination(');
$renderEnd = strpos($functions, 'function get_pagination_links(', $renderStart !== false ? $renderStart : 0);
$assert($renderStart !== false && $renderEnd !== false, 'The shared pagination renderer must remain discoverable.');
if ($renderStart !== false && $renderEnd !== false && !function_exists('render_pagination')) {
    eval(substr($functions, $renderStart, $renderEnd - $renderStart));
}

if (function_exists('render_pagination')) {
    $params = ['status' => 'ALL', 'sort' => 'oldest'];
    $first = render_pagination(1, 142, $params);
    $second = render_pagination(2, 142, $params);
    $third = render_pagination(3, 142, $params);
    $middle = render_pagination(72, 142, $params);
    $last = render_pagination(142, 142, $params);

    $assert(strpos($first, '?status=ALL&amp;sort=oldest&amp;page=2') !== false, 'Page 1 Next must target page 2 and preserve status/sort.');
    $assert(strpos($first, 'pagination-prev') === false && strpos($first, 'pagination-next') !== false, 'First-page boundaries must omit Previous and retain Next.');
    $assert(strpos($second, '?status=ALL&amp;sort=oldest&amp;page=1') !== false && strpos($second, '?status=ALL&amp;sort=oldest&amp;page=3') !== false, 'Page 2 must link backward to 1 and forward to 3.');
    $assert(strpos($third, 'aria-current="page"') !== false && strpos($third, '>3</a>') !== false, 'Page 3 must render as the active page after navigation.');
    $assert(substr_count($middle, 'letter-spacing:1px') === 2, 'A middle page must retain a compact two-ellipsis window.');
    $assert(strpos($last, '?status=ALL&amp;sort=oldest&amp;page=141') !== false, 'Last-page Previous must target page 141 with filters intact.');
    $assert(strpos($last, 'pagination-next') === false && strpos($last, 'pagination-prev') !== false, 'Last-page boundaries must omit Next and retain Previous.');
}

if ($failures) {
    fwrite(STDERR, "Staff Orders pagination regression test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Staff Orders pagination regression test passed.\n";
