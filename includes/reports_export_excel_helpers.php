<?php
/**
 * Shared PhpSpreadsheet helpers for admin report Excel exports.
 */
if (defined('REPORTS_EXPORT_EXCEL_HELPERS_LOADED')) {
    return;
}
define('REPORTS_EXPORT_EXCEL_HELPERS_LOADED', true);

require_once __DIR__ . '/reports_date_range.php';

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

function pf_excel_autosize_columns(Worksheet $sheet, int $fromColIdx, int $toColIdx): void {
    for ($i = $fromColIdx; $i <= $toColIdx; $i++) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    }
}

function pf_excel_style_doc_title(Worksheet $sheet, string $range): void {
    $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('00232b');
    $sheet->getStyle($range)->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle($range)->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(30);
}

function pf_excel_style_column_headers(Worksheet $sheet, string $range): void {
    $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F9FAFB');
    $sheet->getStyle($range)->getFont()->setBold(true)->setSize(10)->getColor()->setRGB('4B5563');
    $sheet->getStyle($range)->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER)
        ->setWrapText(true);
    $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E5E7EB');
    $sheet->getStyle($range)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('0D9488');
}

function pf_excel_zebra_body(Worksheet $sheet, int $firstRow, int $lastRow, int $colFromIdx, int $colToIdx): void {
    if ($lastRow < $firstRow) {
        return;
    }
    $f = Coordinate::stringFromColumnIndex($colFromIdx);
    $t = Coordinate::stringFromColumnIndex($colToIdx);
    for ($r = $firstRow; $r <= $lastRow; $r++) {
        if (($r - $firstRow) % 2 === 1) {
            $sheet->getStyle($f . $r . ':' . $t . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
        }
    }
}

/** Standard PrintFlow report banner + metadata block; returns first row for summary (8). */
function pf_excel_write_report_meta(
    Worksheet $sheet,
    string $reportType,
    string $branchName,
    string $dateLabel,
    string $lastCol = 'H'
): int {
    $sheet->setCellValue('A1', 'PrintFlow Sales & Analytics Report');
    $sheet->mergeCells('A1:' . $lastCol . '1');
    pf_excel_style_doc_title($sheet, 'A1:' . $lastCol . '1');

    $sheet->setCellValue('A3', 'Report Type');
    $sheet->setCellValue('B3', $reportType);
    $sheet->setCellValue('A4', 'Branch');
    $sheet->setCellValue('B4', $branchName);
    $sheet->setCellValue('A5', 'Date Range');
    $sheet->setCellValue('B5', $dateLabel);
    $sheet->setCellValue('A6', 'Generated On');
    $sheet->setCellValue('B6', date('F j, Y, g:i A'));
    $sheet->getStyle('A3:A6')->getFont()->setBold(true);
    $sheet->getStyle('B3:B6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

    return 8;
}

/** Excel column filter dropdowns on a data table (header row through last data row). */
function pf_excel_apply_table_autofilter(
    Worksheet $sheet,
    int $headerRow,
    int $lastColIdx,
    int $lastDataRow
): void {
    if ($headerRow <= 0 || $lastColIdx <= 0 || $lastDataRow < $headerRow) {
        return;
    }
    $from = Coordinate::stringFromColumnIndex(1) . $headerRow;
    $to = Coordinate::stringFromColumnIndex($lastColIdx) . $lastDataRow;
    $sheet->setAutoFilter($from . ':' . $to);
}

function pf_report_inventory_soh(int $itemId, bool $trackByRoll, $branchId): float {
    InventoryManager::ensureBranchScopedSchema();
    if ($branchId === 'all') {
        if ($trackByRoll) {
            return (float)(db_query(
                "SELECT COALESCE(SUM(remaining_length_ft), 0) AS soh
                 FROM inv_rolls
                 WHERE item_id = ? AND status = 'OPEN'",
                'i',
                [$itemId]
            )[0]['soh'] ?? 0);
        }
        return (float)(db_query(
            "SELECT COALESCE(SUM(CASE WHEN direction='IN' THEN quantity ELSE -quantity END), 0) AS soh
             FROM inventory_transactions
             WHERE item_id = ?",
            'i',
            [$itemId]
        )[0]['soh'] ?? 0);
    }

    return (float)InventoryManager::getStockOnHand($itemId, (int)$branchId);
}
