(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.PrintFlowProductionMaterials = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const COLORS = '(?:BLACK|BLUE|GOLD|GREEN|ORANGE|PINK|RED|SILVER|WHITE|YELLOW)';
    const PLATE_VARIANTS = '(?:EURO|HOME|MC|NMC|PH|THAI)';
    const TIER_ORDER = { recommended: 0, optional: 1, unrelated: 2, unverified: 3 };
    const HTV_NAMES = new Set([
        'HOLOGRAPHIC', 'MATTE BLACK', 'MAROON', 'CREAM', 'BROWN', 'DARK BROWN',
        'GOLD CHROME', 'GOLD', 'SILVER CHROME', 'SILVER', 'GRAY', 'RASPBERRY',
        'LIGHT VIOLET', 'VIOLET', 'LIGHT PINK', 'YELLOW GREEN', 'VIVID GREEN',
        'MINT GREEN', 'GOLDEN YELLOW', 'LIGHT YELLOW', 'LIGHT BLUE', 'ROYAL BLUE',
        'RED', 'PINK', 'BLACK', 'WHITE', 'ORANGE', 'GREEN', 'YELLOW', 'BLUE'
    ]);
    const SEARCH_ALIASES = {
        TARPAULIN: ['TARP'],
        STICKER: ['STKR'],
        REFLECTIVE: ['REFLECTORIZED', '3M'],
        MUG: ['CUP'],
        'BOX MUG': ['MUG BOX', 'MUG BX', 'BX MUG'],
        PLATE: ['TEMPORARY PLATE'],
        VINYL: ['HEAT TRANSFER'],
        LAMINATE: ['LAMINATION'],
        SINTRA: ['SINTRABOARD'],
        C2S: ['C2S PAPER'],
        SUBLI: ['SUBLIMATION'],
        MATTE: ['MAT'],
        BLACK: ['BLK'],
        TRANSPARENT: [],
        HOLOGRAM: [],
        HOLOGRAPHIC: []
    };

    function normalize(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toUpperCase()
            .replace(/[^A-Z0-9]+/g, ' ')
            .trim()
            .replace(/\s+/g, ' ');
    }

    function familyFor(item) {
        const name = normalize(item && item.name);
        const category = normalize(item && item.category_name);
        if (Number(item && item.id) === 63) return 'excluded';
        if (name === 'CYNO') return 'unverified';
        if (category.startsWith('INK ') || /^INK (?:L120|L130|TARP)\b/.test(name)) return 'ink';
        if (new RegExp('^(?:AC|SP) ' + PLATE_VARIANTS + '$').test(name)) return 'plate';
        if (new RegExp('^VINYL ' + COLORS + '$').test(name) || HTV_NAMES.has(name) || /\bHTV\b/.test(name)) return 'heat_vinyl';
        if (new RegExp('^STICKER ' + COLORS + '$').test(name)) return 'colored_sticker';
        if (/^(?:\d+(?:\.\d+)? ?FT )?TARPAULIN(?: ROLL)?$/.test(name)) return 'tarpaulin';
        if (name === '3M REFLECTIVE') return 'reflective';
        if (['NEXJET', 'PP STKR MATTE 98', 'HOLOGRAM', 'TRANSPARENT', 'STICKER PAPER'].includes(name)) return 'printed_sticker';
        if (['GLOSS LAMINATE', 'MATTE LAMINATE'].includes(name)) return 'laminate';
        if (['SINTRA 3MM 32', 'SINTRA 5MM'].includes(name)) return 'sintra';
        if (name === 'C2S BOARD') return 'c2s_board';
        if (name === 'C2S SPECIAL PAPER') return 'c2s_special_paper';
        if (name === 'SUBLI PAPER') return 'subli_paper';
        if (name === 'PHOTO PAPER') return 'photo_paper';
        if (name === 'EYELET' || name === 'EYELETS') return 'eyelet';
        if (name === 'MUG') return 'mug';
        if (name === 'BOX MUG') return 'mug_box';
        if (name === 'PVC ID') return 'pvc_id';
        return 'other';
    }

    function flattenStructuredValues(value, output, depth) {
        if (depth > 4 || value === null || value === undefined) return;
        if (Array.isArray(value)) {
            value.forEach(entry => flattenStructuredValues(entry, output, depth + 1));
            return;
        }
        if (typeof value === 'object') {
            Object.entries(value).forEach(([key, entry]) => {
                const normalizedKey = normalize(key);
                if (['PRODUCT TYPE', 'SOUVENIR TYPE', 'STICKER TYPE', 'CUT TYPE', 'MATERIAL TYPE', 'TYPE'].includes(normalizedKey)) {
                    output.push(normalize(entry));
                }
                flattenStructuredValues(entry, output, depth + 1);
            });
        }
    }

    function classifyService(context) {
        const structured = [];
        flattenStructuredValues(context && context.customization, structured, 0);
        [context && context.productType, context && context.souvenirType, context && context.stickerType, context && context.cutType]
            .forEach(value => { if (value) structured.push(normalize(value)); });
        const structuredText = structured.join(' | ');
        const service = normalize(context && (context.serviceType || context.serviceLabel));

        if (structuredText.includes('PLATE NUMBER TEMPORARY PLATE')) return 'plate';
        if (structuredText.includes('SUBDIVISION GATE PASS VEHICLE STICKER')) return 'reflective_cut';
        if (/\bMUG\b/.test(structuredText) || /\bMUG(?:S| PRINTING)?\b/.test(service)) return 'mug';
        if (/T SHIRT|TSHIRT/.test(service) || /T SHIRT|TSHIRT/.test(structuredText)) return 'tshirt';
        if (/TARPAULIN/.test(service)) return 'tarpaulin';
        if (/SINTRABOARD STANDEE|SINTRA BOARD STANDEE/.test(service)) return 'sintraboard';
        if (/\bBROCHURE\b/.test(service)) return 'brochure';
        if (/RAFFLE TICKET/.test(service)) return 'raffle';
        if (/POSTER PRINTING|\bPOSTER\b/.test(service)) return 'poster';
        if (/REFLECTORIZED SIGNAGE/.test(service)) return 'reflectorized_signage';
        if (/^PLATES?$|PLATE NUMBER|TEMPORARY PLATE/.test(service)) return 'plate';
        if ((/REFLECT/.test(structuredText) || /REFLECTIVE STICKER/.test(service)) && /STICKER|DECAL/.test(service)) return 'reflective_sticker';
        if ((/COLORED|CUT ONLY|CUT STICKER|PLOTTER/.test(structuredText) || /COLORED CUT STICKER|CUT STICKER/.test(service)) && /STICKER|DECAL/.test(service)) return 'cut_sticker';
        if ((/PRINTED|PRINTABLE|STICKER PAPER|NEXJET|PP STKR|HOLOGRAM|TRANSPARENT/.test(structuredText) || /PRINTED STICKER/.test(service)) && /STICKER|DECAL/.test(service)) return 'printed_sticker';
        if (/TRANSPARENT STICKER|GLASS .*STICKER|WALL .*STICKER/.test(service)) return 'printed_sticker';
        if (/STICKER|DECAL/.test(service)) return 'sticker_unknown';
        return 'unknown';
    }

    function matchingRule(item, serviceType, rules) {
        const itemId = String(item && item.id);
        const service = normalize(serviceType);
        return (Array.isArray(rules) ? rules : []).find(rule =>
            String(rule.item_id) === itemId && normalize(rule.service_type) === service
        ) || null;
    }

    function classifyItem(item, context, rules) {
        const family = familyFor(item);
        if (family === 'excluded' || family === 'ink') {
            return {
                tier: 'excluded', family, selectable: false, directSelectable: false,
                overrideable: false, inStock: false, reason: ''
            };
        }

        const serviceKind = classifyService(context || {});
        let tier = 'unrelated';
        if (serviceKind === 'sintraboard' && family === 'sintra') {
            tier = 'recommended';
        } else if (serviceKind === 'brochure' && family === 'c2s_special_paper') {
            tier = 'recommended';
        } else if (serviceKind === 'raffle') {
            if (family === 'c2s_board') tier = 'recommended';
            if (family === 'c2s_special_paper') tier = 'optional';
        } else if (serviceKind === 'poster') {
            if (family === 'c2s_board' || family === 'c2s_special_paper') tier = 'recommended';
            if (family === 'photo_paper') tier = 'optional';
        } else if (serviceKind === 'mug') {
            if (family === 'mug' || family === 'subli_paper') tier = 'recommended';
            if (family === 'mug_box') tier = 'optional';
        } else if (serviceKind === 'reflectorized_signage') {
            if (family === 'sintra') tier = 'recommended';
            if (family === 'reflective' || family === 'colored_sticker') tier = 'optional';
        } else if (serviceKind === 'tarpaulin') {
            if (family === 'tarpaulin') tier = 'recommended';
            if (family === 'eyelet') tier = 'optional';
        } else if (serviceKind === 'tshirt' && family === 'heat_vinyl') {
            tier = 'recommended';
        } else if (serviceKind === 'printed_sticker') {
            if (family === 'printed_sticker') tier = 'recommended';
            if (family === 'laminate') tier = 'optional';
        } else if (serviceKind === 'cut_sticker' && family === 'colored_sticker') {
            tier = 'recommended';
        } else if (serviceKind === 'reflective_cut') {
            if (family === 'reflective') tier = 'recommended';
            if (family === 'colored_sticker') tier = 'optional';
        } else if (serviceKind === 'reflective_sticker') {
            if (family === 'reflective') tier = 'recommended';
            if (family === 'colored_sticker') tier = 'optional';
        } else if (serviceKind === 'plate') {
            if (family === 'plate') tier = 'recommended';
            if (family === 'colored_sticker' || family === 'reflective') tier = 'optional';
        } else if (serviceKind === 'sticker_unknown') {
            if (['printed_sticker', 'colored_sticker', 'reflective', 'laminate'].includes(family)) tier = 'unverified';
        } else if (serviceKind === 'unknown') {
            const rule = matchingRule(item, context && context.serviceType, rules);
            if (rule && normalize(rule.rule_type) === 'REQUIRED') tier = 'recommended';
            if (rule && normalize(rule.rule_type) === 'OPTIONAL') tier = 'optional';
            if (!rule) tier = 'unverified';
        }

        if (family === 'unverified' || (family === 'other' && tier === 'unrelated')) tier = 'unverified';
        if (family === 'pvc_id') tier = 'unrelated';

        const stock = Number.parseFloat(item && item.current_stock);
        const inStock = Number.isFinite(stock) && stock > 0;
        const directSelectable = (tier === 'recommended' || tier === 'optional') && inStock;
        const overrideable = (tier === 'unrelated' || tier === 'unverified') && inStock;
        const selectable = directSelectable || overrideable;
        const serviceLabel = String((context && (context.serviceLabel || context.serviceType)) || 'this service').trim();
        const reason = !inStock
            ? 'Out of stock'
            : tier === 'unverified'
                ? 'Usage not verified'
            : tier === 'unrelated'
                ? 'Not suggested for ' + serviceLabel
                : tier === 'optional' ? 'Optional / related material' : 'Recommended for this job';
        return { tier, family, selectable, directSelectable, overrideable, inStock, reason, serviceKind };
    }

    function descriptionFor(item) {
        const family = familyFor(item);
        const labels = {
            plate: 'Plate material',
            sintra: 'Sintraboard material',
            c2s_board: 'C2S board stock',
            c2s_special_paper: 'C2S special paper',
            subli_paper: 'Sublimation transfer paper',
            photo_paper: 'Photo paper alternative',
            printed_sticker: 'Printed sticker material',
            colored_sticker: 'Colored cut sticker',
            reflective: 'Reflective cut material',
            laminate: 'Optional sticker finishing',
            heat_vinyl: 'T-shirt heat-transfer material',
            tarpaulin: 'Tarpaulin material',
            eyelet: '4 standard eyelets included; add only extras',
            mug: 'Blank mug',
            mug_box: 'Optional mug packaging',
            pvc_id: 'PVC ID material',
            unverified: 'Usage not verified'
        };
        return labels[family] || '';
    }

    function editDistance(a, b, maximum) {
        if (Math.abs(a.length - b.length) > maximum) return maximum + 1;
        let previous = Array.from({ length: b.length + 1 }, (_, index) => index);
        for (let i = 1; i <= a.length; i += 1) {
            const current = [i];
            let rowMin = i;
            for (let j = 1; j <= b.length; j += 1) {
                current[j] = Math.min(
                    current[j - 1] + 1,
                    previous[j] + 1,
                    previous[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1)
                );
                rowMin = Math.min(rowMin, current[j]);
            }
            if (rowMin > maximum) return maximum + 1;
            previous = current;
        }
        return previous[b.length];
    }

    function searchableText(item) {
        const name = normalize(item && item.name);
        const category = normalize(item && item.category_name);
        const aliases = [];
        Object.entries(SEARCH_ALIASES).forEach(([term, values]) => {
            if (name.includes(term) || values.some(value => name.includes(value))) aliases.push(term, ...values);
        });
        return normalize([name, category, aliases.join(' ')].join(' '));
    }

    function searchScore(item, query) {
        const q = normalize(query);
        if (!q) return 1;
        const name = normalize(item && item.name);
        const haystack = searchableText(item);
        if (name === q) return 1000;
        if (name.startsWith(q)) return 900;
        if (name.includes(q)) return 820;
        const queryTokens = q.split(' ').filter(Boolean);
        const candidateTokens = haystack.split(' ').filter(Boolean);
        let total = 0;
        for (const token of queryTokens) {
            if (candidateTokens.includes(token)) {
                total += 160;
                continue;
            }
            if (candidateTokens.some(candidate => candidate.startsWith(token) || token.startsWith(candidate))) {
                total += 130;
                continue;
            }
            const tolerance = token.length >= 7 ? 2 : token.length >= 3 ? 1 : 0;
            const fuzzy = tolerance > 0 && candidateTokens.some(candidate => editDistance(token, candidate, tolerance) <= tolerance);
            if (!fuzzy) return 0;
            total += 105;
        }
        return total;
    }

    function rankItems(items, context, rules, query) {
        return (Array.isArray(items) ? items : [])
            .map(item => ({ ...item, compatibility: classifyItem(item, context, rules), search_score: searchScore(item, query) }))
            .filter(item => item.compatibility.tier !== 'excluded' && item.search_score > 0)
            .sort((left, right) => {
                if (query && right.search_score !== left.search_score) return right.search_score - left.search_score;
                const tierDiff = TIER_ORDER[left.compatibility.tier] - TIER_ORDER[right.compatibility.tier];
                if (tierDiff !== 0) return tierDiff;
                return String(left.name || '').localeCompare(String(right.name || ''), undefined, { numeric: true });
            });
    }

    function inkModeFor(item) {
        const family = familyFor(item);
        if (family === 'tarpaulin') return 'tarp';
        if (['mug', 'subli_paper', 'printed_sticker', 'c2s_board', 'c2s_special_paper', 'photo_paper'].includes(family)) return 'standard';
        if (['heat_vinyl', 'colored_sticker', 'reflective', 'plate', 'sintra'].includes(family)) return 'none';
        return 'unknown';
    }

    return { normalize, familyFor, classifyService, classifyItem, descriptionFor, searchScore, rankItems, inkModeFor };
});
