<?php
/**
 * Shared date-range parsing for reports exports (matches admin/reports.php).
 */
if (defined('PF_REPORTS_DATE_RANGE_LOADED')) {
    return;
}
define('PF_REPORTS_DATE_RANGE_LOADED', true);

/**
 * @return array{from:string,to:string,fromStart:string,toEnd:string,label:string}
 */
function pf_reports_export_date_range(): array {
    $from_req = array_key_exists('from', $_GET) ? $_GET['from'] : null;
    $to_req = array_key_exists('to', $_GET) ? $_GET['to'] : null;

    if ($from_req === null) {
        $from = date('Y-m-d', strtotime('-30 days'));
    } else {
        $from = (string)$from_req;
    }

    if ($to_req === null) {
        $to = date('Y-m-d');
    } else {
        $to = (string)$to_req;
    }

    if ($from !== '' && $to !== '' && strtotime($from) > strtotime($to)) {
        $tmp = $from;
        $from = $to;
        $to = $tmp;
    }

    $fromNorm = ($from !== '') ? date('Y-m-d', strtotime($from)) : '';
    $toNorm = ($to !== '') ? date('Y-m-d', strtotime($to)) : '';

    if ($fromNorm !== '' && $toNorm !== '') {
        $label = date('F j, Y', strtotime($fromNorm)) . ' – ' . date('F j, Y', strtotime($toNorm));
    } elseif ($fromNorm !== '') {
        $label = 'From ' . date('F j, Y', strtotime($fromNorm));
    } elseif ($toNorm !== '') {
        $label = 'Through ' . date('F j, Y', strtotime($toNorm));
    } else {
        $label = 'All time';
    }

    return [
        'from' => $fromNorm,
        'to' => $toNorm,
        'fromStart' => $fromNorm !== '' ? $fromNorm . ' 00:00:00' : '',
        'toEnd' => $toNorm !== '' ? $toNorm . ' 23:59:59' : '',
        'label' => $label,
    ];
}

/**
 * SQL fragment for orders.order_date (or other datetime column) within export range.
 *
 * @return array{0:string,1:string,2:array<int|string>}
 */
function pf_reports_order_date_where(string $alias = 'o', string $column = 'order_date'): array {
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'o';
    $column = preg_replace('/[^A-Za-z0-9_]/', '', $column) ?: 'order_date';
    $dr = pf_reports_export_date_range();

    if ($dr['fromStart'] !== '' && $dr['toEnd'] !== '') {
        return [" AND {$alias}.{$column} BETWEEN ? AND ?", 'ss', [$dr['fromStart'], $dr['toEnd']]];
    }
    if ($dr['fromStart'] !== '') {
        return [" AND {$alias}.{$column} >= ?", 's', [$dr['fromStart']]];
    }
    if ($dr['toEnd'] !== '') {
        return [" AND {$alias}.{$column} <= ?", 's', [$dr['toEnd']]];
    }

    return ['', '', []];
}
