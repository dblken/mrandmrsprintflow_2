<?php
/**
 * Dynamic barcode helpers for product SKUs.
 *
 * Uses Picqer when vendor autoload is complete. The small Code 128B fallback
 * keeps SKU barcodes available in constrained deployments and supports the
 * existing SKU character set: letters, numbers, spaces, and hyphens.
 */

function printflow_barcode_clean_value(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return preg_replace('/[^A-Za-z0-9\- ]/', '', $value) ?? '';
}

function printflow_barcode_svg(string $value, int $widthFactor = 2, int $height = 70): string
{
    $value = printflow_barcode_clean_value($value);
    if ($value === '') {
        throw new InvalidArgumentException('Barcode value is required.');
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    if (class_exists('\Picqer\Barcode\BarcodeGeneratorSVG')) {
        $generator = new \Picqer\Barcode\BarcodeGeneratorSVG();
        return $generator->getBarcode($value, $generator::TYPE_CODE_128, $widthFactor, $height);
    }

    return printflow_barcode_code128b_svg($value, $widthFactor, $height);
}

function printflow_barcode_code128b_svg(string $value, int $widthFactor = 2, int $height = 70): string
{
    static $patterns = [
        '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
        '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
        '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
        '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
        '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
        '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
        '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
        '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
        '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
        '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
        '114131','311141','411131','211412','211214','211232','2331112',
    ];

    $codes = [104]; // Code 128 Start B
    $checksum = 104;
    $chars = str_split($value);
    foreach ($chars as $i => $char) {
        $ord = ord($char);
        if ($ord < 32 || $ord > 126) {
            throw new InvalidArgumentException('Barcode value contains unsupported characters.');
        }
        $code = $ord - 32;
        $codes[] = $code;
        $checksum += ($i + 1) * $code;
    }
    $codes[] = $checksum % 103;
    $codes[] = 106; // Stop

    $quiet = 10 * $widthFactor;
    $x = $quiet;
    $bars = '';
    foreach ($codes as $code) {
        $pattern = $patterns[$code] ?? '';
        $draw = true;
        foreach (str_split($pattern) as $moduleWidth) {
            $w = (int)$moduleWidth * $widthFactor;
            if ($draw) {
                $bars .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . $height . '" />';
            }
            $x += $w;
            $draw = !$draw;
        }
    }

    $totalWidth = $x + $quiet;
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $totalWidth . '" height="' . $height . '" viewBox="0 0 ' . $totalWidth . ' ' . $height . '" shape-rendering="crispEdges" role="img" aria-label="Code 128 barcode">'
        . '<rect width="100%" height="100%" fill="#fff"/>'
        . '<g fill="#000">' . $bars . '</g>'
        . '</svg>';
}
