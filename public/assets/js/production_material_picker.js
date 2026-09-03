(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.PrintFlowProductionMaterials = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const COLORS = '(?:BLACK|BLUE|GOLD|GREEN|ORANGE|PINK|RED|SILVER|WHITE|YELLOW)';
    const PLATE_VARIANTS = '(?:EURO|HOME|MC|NMC|PH|THAI)';
    const TIER_ORDER = { recommended: 0, optional: 1, unrelated: 2 };
    const SEARCH_ALIASES = {
        TARPAULIN: ['TARP'],
        STICKER: ['STKR'],
        REFLECTIVE: ['REFLECTORIZED', '3M'],
        MUG: ['CUP'],
        'BOX MUG': ['MUG BOX', 'MUG BX', 'BX MUG'],
        PLATE: ['TEMPORARY PLATE'],
        VINYL: ['HEAT TRANSFER'],
        LAMINATE: ['LAMINATION'],
        TRANSPARENT: [],
        HOLOGRAM: ['HOLOGRAPHIC']
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
        if (category.startsWith('INK ') || /^INK (?:L120|L130|TARP)\b/.test(name)) return 'ink';
        if (new RegExp('^(?:AC|SP) ' + PLATE_VARIANTS + '$').test(name)) return 'plate';
        if (new RegExp('^VINYL ' + COLORS + '$').test(name)) return 'heat_vinyl';
        if (new RegExp('^STICKER ' + COLORS + '$').test(name)) return 'colored_sticker';
        if (/^[34567] ?FT TARPAULIN$/.test(name)) return 'tarpaulin';
        if (name === '3M REFLECTIVE') return 'reflective';
        if (['NEXJET', 'PP STKR MATTE 98', 'HOLOGRAM', 'TRANSPARENT'].includes(name)) return 'printed_sticker';
        if (['GLOSS LAMINATE', 'MATTE LAMINATE'].includes(name)) return 'laminate';
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
                if (['PRODUCT TYPE', 'SOUVENIR TYPE', 'STICKER TYPE', 'CUT TYPE', 'MATERIAL TYPE'].includes(normalizedKey)) {
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
        if (/PLATE NUMBER|TEMPORARY PLATE/.test(service)) return 'plate';
        if (/COLORED (?:CUT )?STICKER|CUT ONLY|CUT STICKER/.test(structuredText + ' ' + service)) return 'cut_sticker';
        if (/TRANSPARENT STICKER|GLASS .*STICKER|WALL .*STICKER|DECALS? STICKERS?|STICKER PRINTING/.test(service)) return 'printed_sticker';
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
        if (family === 'excluded' || family === 'ink') return { tier: 'excluded', family, selectable: false, reason: '' };

        const serviceKind = classifyService(context || {});
        let tier = 'unrelated';
        if (serviceKind === 'mug') {
            if (family === 'mug') tier = 'recommended';
            if (family === 'mug_box') tier = 'optional';
        } else if (serviceKind === 'tarpaulin' && family === 'tarpaulin') {
            tier = 'recommended';
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
        } else if (serviceKind === 'plate') {
            if (family === 'plate') tier = 'recommended';
            if (family === 'colored_sticker' || family === 'reflective') tier = 'optional';
        } else if (serviceKind === 'unknown') {
            const rule = matchingRule(item, context && context.serviceType, rules);
            if (rule && normalize(rule.rule_type) === 'REQUIRED') tier = 'recommended';
            if (rule && normalize(rule.rule_type) === 'OPTIONAL') tier = 'optional';
        }

        const stock = Number.parseFloat(item && item.current_stock);
        const inStock = Number.isFinite(stock) && stock > 0;
        const selectable = tier !== 'unrelated' && inStock;
        const serviceLabel = String((context && (context.serviceLabel || context.serviceType)) || 'this service').trim();
        const reason = !inStock && tier !== 'unrelated'
            ? 'Out of stock'
            : tier === 'unrelated'
                ? 'Not applicable to ' + serviceLabel
                : tier === 'optional' ? 'Optional / related material' : 'Recommended for this job';
        return { tier, family, selectable, inStock, reason, serviceKind };
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
        if (['mug', 'printed_sticker'].includes(family)) return 'standard';
        if (['heat_vinyl', 'colored_sticker', 'reflective', 'plate'].includes(family)) return 'none';
        return 'unknown';
    }

    return { normalize, familyFor, classifyService, classifyItem, searchScore, rankItems, inkModeFor };
});
