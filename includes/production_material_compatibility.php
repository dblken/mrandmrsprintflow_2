<?php
declare(strict_types=1);

/**
 * Server-side companion for public/assets/js/production_material_picker.js.
 * Keep the compatibility tiers aligned with the picker so forged material
 * assignments cannot bypass the filtered Production Assignment UI.
 */

function pfpm_normalize($value): string
{
    $text = strtoupper(trim((string)$value));
    $text = preg_replace('/[^A-Z0-9]+/', ' ', $text) ?? '';
    return trim(preg_replace('/\s+/', ' ', $text) ?? '');
}

function pfpm_material_text(array $item): string
{
    return pfpm_normalize(implode(' ', array_filter([
        $item['name'] ?? '',
        $item['category_name'] ?? '',
        $item['material_type'] ?? '',
        $item['type'] ?? '',
        $item['description'] ?? '',
        $item['sku'] ?? '',
    ], static fn($v) => trim((string)$v) !== '')));
}

function pfpm_material_family(array $item): string
{
    $name = pfpm_normalize($item['name'] ?? '');
    $category = pfpm_normalize($item['category_name'] ?? '');
    $metadata = pfpm_material_text($item);
    $colors = '(?:BLACK|BLUE|GOLD|GREEN|ORANGE|PINK|RED|SILVER|WHITE|YELLOW)';
    $plates = '(?:EURO|HOME|MC|NMC|PH|THAI)';
    $htvNames = array_flip([
        'HOLOGRAPHIC', 'MATTE BLACK', 'MAROON', 'CREAM', 'BROWN', 'DARK BROWN',
        'GOLD CHROME', 'GOLD', 'SILVER CHROME', 'SILVER', 'GRAY', 'RASPBERRY',
        'LIGHT VIOLET', 'VIOLET', 'LIGHT PINK', 'YELLOW GREEN', 'VIVID GREEN',
        'MINT GREEN', 'GOLDEN YELLOW', 'LIGHT YELLOW', 'LIGHT BLUE', 'ROYAL BLUE',
        'RED', 'PINK', 'BLACK', 'WHITE', 'ORANGE', 'GREEN', 'YELLOW', 'BLUE',
    ]);

    if ((int)($item['id'] ?? 0) === 63) return 'excluded';
    if ($name === 'CYNO') return 'unverified';
    if (str_starts_with($category, 'INK ') || preg_match('/^INK (?:L120|L130|TARP)\b/', $name)) return 'ink';
    if (preg_match('/^(?:AC|SP) ' . $plates . '$/', $name)) return 'plate';
    if ($category === 'TARPAULIN' || preg_match('/\bTARPAULIN\b/', $name) || preg_match('/\bTARP\b/', $name)) return 'tarpaulin';
    if ($name === '3M REFLECTIVE' || (preg_match('/\bREFLECT(?:IVE|ORIZED)?\b/', $metadata) && preg_match('/\bSTICKER\b/', $metadata))) return 'reflective';
    if (preg_match('/^STICKER ' . $colors . '$/', $name) || (preg_match('/\bSTICKERS?\b/', $metadata) && preg_match('/\b(?:COLOU?RED|COLORS?|CUT)\b/', $metadata))) return 'colored_sticker';
    if (in_array($name, ['NEXJET', 'PP STKR MATTE 98', 'HOLOGRAM', 'TRANSPARENT', 'STICKER PAPER'], true)) return 'printed_sticker';
    if (preg_match('/\bSTICKERS?\b/', $metadata) && preg_match('/\b(?:PRINTABLE|PRINTED|PAPER|ADHESIVE|INKJET|NEXJET|PP)\b/', $metadata)) return 'printed_sticker';
    if (in_array($name, ['GLOSS LAMINATE', 'MATTE LAMINATE'], true)) return 'laminate';
    if (preg_match('/\bSTICKERS?\b/', $metadata)) return 'sticker_media';
    if (preg_match('/^VINYL ' . $colors . '$/', $name) || isset($htvNames[$name]) || preg_match('/\bHTV\b/', $name)) return 'heat_vinyl';
    if (in_array($name, ['SINTRA 3MM 32', 'SINTRA 5MM'], true)) return 'sintra';
    if ($name === 'C2S BOARD') return 'c2s_board';
    if ($name === 'C2S SPECIAL PAPER') return 'c2s_special_paper';
    if ($name === 'SUBLI PAPER') return 'subli_paper';
    if ($name === 'PHOTO PAPER') return 'photo_paper';
    if ($name === 'EYELET' || $name === 'EYELETS') return 'eyelet';
    if ($name === 'MUG') return 'mug';
    if ($name === 'BOX MUG') return 'mug_box';
    if ($name === 'PVC ID') return 'pvc_id';
    return 'other';
}

function pfpm_canonical_category_kind($category): string
{
    $normalized = pfpm_normalize($category);
    if ($normalized === '') return 'unknown';
    $singular = str_replace(['STICKERS', 'STANDEES'], ['STICKER', 'STANDEE'], $normalized);
    $aliases = [
        'tarpaulin' => ['TARPAULIN', 'TARP'],
        'tshirt' => ['T SHIRT', 'TSHIRT', 'SHIRT PRINTING', 'TEXTILE TRANSFER'],
        'stickers' => ['STICKER', 'DECAL', 'ADHESIVE LABEL'],
        'signage' => ['SIGNAGE', 'SIGN BOARD'],
        'print' => ['PRINT', 'PAPER PRINT', 'PRINT MEDIA'],
        'sintraboard' => ['SINTRABOARD STANDEE', 'SINTRA BOARD STANDEE'],
        'merchandise' => ['MERCHANDISE', 'SOUVENIR'],
    ];
    foreach ($aliases as $kind => $values) {
        foreach ($values as $alias) {
            $token = pfpm_normalize($alias);
            if ($singular === $token || preg_match('/(?:^| )' . str_replace(' ', ' +', preg_quote($token, '/')) . '(?: |$)/', $singular)) {
                return $kind;
            }
        }
    }
    return 'unknown';
}

function pfpm_flatten_structured_values($value, array &$out, int $depth = 0): void
{
    if ($depth > 4 || $value === null) return;
    if (is_array($value)) {
        $isAssoc = array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $key => $entry) {
            if ($isAssoc && in_array(pfpm_normalize($key), ['PRODUCT TYPE', 'SOUVENIR TYPE', 'STICKER TYPE', 'STICKERS TYPE', 'STICKER TYPE SIZE', 'STICKERS TYPE SIZE', 'CUT TYPE', 'MATERIAL TYPE', 'TYPE'], true)) {
                $out[] = pfpm_normalize(is_scalar($entry) ? $entry : json_encode($entry));
            }
            pfpm_flatten_structured_values($entry, $out, $depth + 1);
        }
    }
}

function pfpm_classify_service(array $context): string
{
    $structured = [];
    pfpm_flatten_structured_values($context['customization'] ?? [], $structured);
    foreach (['productType', 'souvenirType', 'stickerType', 'cutType'] as $key) {
        if (!empty($context[$key])) $structured[] = pfpm_normalize($context[$key]);
    }
    $structuredText = implode(' | ', $structured);
    $service = pfpm_normalize(($context['serviceType'] ?? '') ?: ($context['serviceLabel'] ?? ''));

    $fromName = 'unknown';
    if (str_contains($structuredText, 'PLATE NUMBER TEMPORARY PLATE')) $fromName = 'plate';
    elseif (str_contains($structuredText, 'SUBDIVISION GATE PASS VEHICLE STICKER')) $fromName = 'reflective_cut';
    elseif (preg_match('/\bMUG\b/', $structuredText) || preg_match('/\bMUG(?:S| PRINTING)?\b/', $service)) $fromName = 'mug';
    elseif (preg_match('/T SHIRT|TSHIRT/', $service . ' ' . $structuredText)) $fromName = 'tshirt';
    elseif (preg_match('/TARPAULIN/', $service)) $fromName = 'tarpaulin';
    elseif (preg_match('/SINTRABOARD STANDEE|SINTRA BOARD STANDEE/', $service)) $fromName = 'sintraboard';
    elseif (preg_match('/\bBROCHURE\b/', $service)) $fromName = 'brochure';
    elseif (preg_match('/RAFFLE TICKET/', $service)) $fromName = 'raffle';
    elseif (preg_match('/POSTER PRINTING|\bPOSTER\b/', $service)) $fromName = 'poster';
    elseif (preg_match('/REFLECTORIZED SIGNAGE/', $service)) $fromName = 'reflectorized_signage';
    elseif (preg_match('/^PLATES?$|PLATE NUMBER|TEMPORARY PLATE/', $service)) $fromName = 'plate';
    elseif ((preg_match('/REFLECT/', $structuredText) || preg_match('/REFLECTIVE STICKER/', $service)) && preg_match('/STICKER|DECAL/', $service)) $fromName = 'reflective_sticker';
    elseif ((preg_match('/COLORED|CUT ONLY|CUT OUT|CUT STICKER|PLOTTER/', $structuredText) || preg_match('/COLORED CUT STICKER|CUT STICKER/', $service)) && preg_match('/STICKER|DECAL/', $service)) $fromName = 'cut_sticker';
    elseif ((preg_match('/PRINTED|PRINTABLE|STICKER PAPER|NEXJET|PP STKR|HOLOGRAM|TRANSPARENT/', $structuredText) || preg_match('/PRINTED STICKER/', $service)) && preg_match('/STICKER|DECAL/', $service)) $fromName = 'printed_sticker';
    elseif (preg_match('/TRANSPARENT STICKER|GLASS .*STICKER|WALL .*STICKER/', $service)) $fromName = 'printed_sticker';
    elseif (preg_match('/STICKER|DECAL/', $service)) $fromName = 'sticker_unknown';

    if (in_array($fromName, ['plate', 'reflective_cut', 'mug', 'brochure', 'raffle', 'poster', 'reflectorized_signage', 'reflective_sticker', 'cut_sticker', 'printed_sticker', 'sintraboard'], true)) {
        return $fromName;
    }
    $categoryKind = pfpm_canonical_category_kind($context['serviceCategory'] ?? '');
    if ($categoryKind === 'stickers') return in_array($fromName, ['reflective_sticker', 'cut_sticker', 'printed_sticker'], true) ? $fromName : 'sticker_unknown';
    if ($categoryKind === 'merchandise') return $fromName === 'mug' ? 'mug' : 'unknown';
    return $categoryKind !== 'unknown' ? $categoryKind : $fromName;
}

function pfpm_matching_context_rule(array $item, array $context, array $rules): ?array
{
    $candidates = array_filter(array_map('pfpm_normalize', [
        $context['serviceType'] ?? '',
        $context['serviceName'] ?? '',
        $context['serviceLabel'] ?? '',
        $context['serviceCategory'] ?? '',
    ]));
    foreach ($rules as $rule) {
        if ((string)($rule['item_id'] ?? '') !== (string)($item['id'] ?? '')) continue;
        if (in_array(pfpm_normalize($rule['service_type'] ?? ''), $candidates, true)) return $rule;
    }
    return null;
}

function pfpm_tier_for_category(string $categoryKind, string $family): ?string
{
    $compat = [
        'tarpaulin' => [['tarpaulin'], ['eyelet']],
        'tshirt' => [['heat_vinyl'], []],
        'stickers' => [['colored_sticker', 'printed_sticker', 'sticker_media'], ['reflective', 'laminate']],
        'signage' => [['sintra'], ['reflective', 'colored_sticker', 'sticker_media']],
        'print' => [['c2s_board', 'c2s_special_paper'], ['photo_paper']],
        'sintraboard' => [['sintra'], []],
    ];
    if (!isset($compat[$categoryKind])) return null;
    if (in_array($family, $compat[$categoryKind][0], true)) return 'recommended';
    if (in_array($family, $compat[$categoryKind][1], true)) return 'optional';
    return 'unrelated';
}

function pfpm_classify_item(array $item, array $context, array $rules = []): array
{
    $family = pfpm_material_family($item);
    if (in_array($family, ['excluded', 'ink'], true)) return ['tier' => 'excluded', 'family' => $family, 'applicable' => false];
    $serviceKind = pfpm_classify_service($context);
    $tier = 'unrelated';
    $rule = pfpm_matching_context_rule($item, $context, $rules);
    $ruleType = pfpm_normalize($rule['rule_type'] ?? '');
    if ($ruleType === 'REQUIRED') $tier = 'recommended';
    elseif ($ruleType === 'OPTIONAL') $tier = 'optional';
    elseif ($serviceKind === 'sintraboard' && $family === 'sintra') $tier = 'recommended';
    elseif ($serviceKind === 'brochure' && $family === 'c2s_special_paper') $tier = 'recommended';
    elseif ($serviceKind === 'raffle') $tier = $family === 'c2s_board' ? 'recommended' : ($family === 'c2s_special_paper' ? 'optional' : $tier);
    elseif ($serviceKind === 'poster') $tier = in_array($family, ['c2s_board', 'c2s_special_paper'], true) ? 'recommended' : ($family === 'photo_paper' ? 'optional' : $tier);
    elseif ($serviceKind === 'mug') $tier = in_array($family, ['mug', 'subli_paper'], true) ? 'recommended' : ($family === 'mug_box' ? 'optional' : $tier);
    elseif ($serviceKind === 'reflectorized_signage') $tier = $family === 'sintra' ? 'recommended' : (in_array($family, ['reflective', 'colored_sticker'], true) ? 'optional' : $tier);
    elseif ($serviceKind === 'tarpaulin') $tier = $family === 'tarpaulin' ? 'recommended' : ($family === 'eyelet' ? 'optional' : $tier);
    elseif ($serviceKind === 'tshirt' && $family === 'heat_vinyl') $tier = 'recommended';
    elseif ($serviceKind === 'printed_sticker') $tier = $family === 'printed_sticker' ? 'recommended' : ($family === 'laminate' ? 'optional' : $tier);
    elseif ($serviceKind === 'cut_sticker' && $family === 'colored_sticker') $tier = 'recommended';
    elseif ($serviceKind === 'reflective_cut') $tier = $family === 'reflective' ? 'recommended' : ($family === 'colored_sticker' ? 'optional' : $tier);
    elseif ($serviceKind === 'reflective_sticker') $tier = $family === 'reflective' ? 'recommended' : ($family === 'colored_sticker' ? 'optional' : $tier);
    elseif ($serviceKind === 'plate') $tier = $family === 'plate' ? 'recommended' : (in_array($family, ['colored_sticker', 'reflective'], true) ? 'optional' : $tier);
    elseif ($serviceKind === 'sticker_unknown') $tier = pfpm_tier_for_category('stickers', $family) ?? $tier;
    elseif (in_array($serviceKind, ['signage', 'print', 'stickers'], true)) $tier = pfpm_tier_for_category($serviceKind, $family) ?? $tier;
    elseif ($serviceKind === 'unknown') $tier = $rule ? $tier : 'unverified';
    if ($family === 'unverified' || ($family === 'other' && $tier === 'unrelated')) $tier = 'unverified';
    if ($family === 'pvc_id') $tier = 'unrelated';
    return ['tier' => $tier, 'family' => $family, 'serviceKind' => $serviceKind, 'applicable' => in_array($tier, ['recommended', 'optional'], true)];
}

function pfpm_material_rules(): array
{
    return db_query('SELECT service_type, item_id, rule_type FROM service_material_rules') ?: [];
}

function pfpm_item_with_category(int $itemId): ?array
{
    $rows = db_query(
        'SELECT i.*, c.name AS category_name FROM inv_items i LEFT JOIN inv_categories c ON c.id = i.category_id WHERE i.id = ? AND i.status = "ACTIVE" LIMIT 1',
        'i',
        [$itemId]
    ) ?: [];
    return $rows[0] ?? null;
}

function pfpm_assert_material_applicable(int $itemId, array $context): void
{
    $item = pfpm_item_with_category($itemId);
    if (!$item) {
        throw new Exception('Selected material is not available.');
    }
    $state = pfpm_classify_item($item, $context, pfpm_material_rules());
    if (empty($state['applicable'])) {
        $label = trim((string)($context['serviceLabel'] ?? $context['serviceType'] ?? 'this service'));
        throw new Exception('Selected material is not recommended for ' . ($label !== '' ? $label : 'this service') . '.');
    }
}
