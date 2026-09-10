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

    function materialText(item) {
        return normalize([
            item && item.name,
            item && item.category_name,
            item && item.material_type,
            item && item.type,
            item && item.description,
            item && item.sku
        ].filter(Boolean).join(' '));
    }

    function familyFor(item) {
        const name = normalize(item && item.name);
        const category = normalize(item && item.category_name);
        const metadata = materialText(item);
        if (Number(item && item.id) === 63) return 'excluded';
        if (name === 'CYNO') return 'unverified';
        if (category.startsWith('INK ') || /^INK (?:L120|L130|TARP)\b/.test(name)) return 'ink';
        if (new RegExp('^(?:AC|SP) ' + PLATE_VARIANTS + '$').test(name)) return 'plate';
        if (category === 'TARPAULIN' || /\bTARPAULIN\b/.test(name) || /\bTARP\b/.test(name)) return 'tarpaulin';
        if (name === '3M REFLECTIVE' || (/\bREFLECT(?:IVE|ORIZED)?\b/.test(metadata) && /\bSTICKER\b/.test(metadata))) return 'reflective';
        if (new RegExp('^STICKER ' + COLORS + '$').test(name)
            || (/\bSTICKERS?\b/.test(metadata) && /\b(?:COLOU?RED|COLORS?|CUT)\b/.test(metadata))) return 'colored_sticker';
        if (['NEXJET', 'PP STKR MATTE 98', 'HOLOGRAM', 'TRANSPARENT', 'STICKER PAPER'].includes(name)) return 'printed_sticker';
        if (/\bSTICKERS?\b/.test(metadata)
            && /\b(?:PRINTABLE|PRINTED|PAPER|ADHESIVE|INKJET|NEXJET|PP)\b/.test(metadata)) return 'printed_sticker';
        if (['GLOSS LAMINATE', 'MATTE LAMINATE'].includes(name)) return 'laminate';
        if (/\bSTICKERS?\b/.test(metadata)) return 'sticker_media';
        if (new RegExp('^VINYL ' + COLORS + '$').test(name) || HTV_NAMES.has(name) || /\bHTV\b/.test(name)) return 'heat_vinyl';
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

    const CATEGORY_COMPATIBILITY = {
        tarpaulin: {
            recommended: new Set(['tarpaulin']),
            optional: new Set(['eyelet'])
        },
        tshirt: {
            recommended: new Set(['heat_vinyl']),
            optional: new Set([])
        },
        stickers: {
            recommended: new Set(['colored_sticker', 'printed_sticker', 'sticker_media']),
            optional: new Set(['reflective', 'laminate'])
        },
        signage: {
            recommended: new Set(['sintra']),
            optional: new Set(['reflective', 'colored_sticker', 'sticker_media'])
        },
        print: {
            recommended: new Set(['c2s_board', 'c2s_special_paper']),
            optional: new Set(['photo_paper'])
        },
        sintraboard: {
            recommended: new Set(['sintra']),
            optional: new Set([])
        }
    };

    function tierForCategory(categoryKind, family) {
        const compatibility = CATEGORY_COMPATIBILITY[categoryKind];
        if (!compatibility) return null;
        if (compatibility.recommended.has(family)) return 'recommended';
        if (compatibility.optional.has(family)) return 'optional';
        return 'unrelated';
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
                if (['PRODUCT TYPE', 'SOUVENIR TYPE', 'STICKER TYPE', 'STICKERS TYPE', 'STICKER TYPE SIZE', 'STICKERS TYPE SIZE', 'CUT TYPE', 'MATERIAL TYPE', 'TYPE'].includes(normalizedKey)) {
                    output.push(normalize(entry));
                }
                flattenStructuredValues(entry, output, depth + 1);
            });
        }
    }

    const SPECIFIC_SERVICE_NAME_KINDS = new Set([
        'plate', 'reflective_cut', 'mug', 'brochure', 'raffle', 'poster', 'reflectorized_signage',
        'reflective_sticker', 'cut_sticker', 'printed_sticker', 'sintraboard'
    ]);
    const CATEGORY_CONCEPT_ALIASES = {
        tarpaulin: ['TARPAULIN', 'TARP'],
        tshirt: ['T SHIRT', 'TSHIRT', 'SHIRT PRINTING', 'TEXTILE TRANSFER'],
        stickers: ['STICKER', 'DECAL', 'ADHESIVE LABEL'],
        signage: ['SIGNAGE', 'SIGN BOARD'],
        print: ['PRINT', 'PAPER PRINT', 'PRINT MEDIA'],
        sintraboard: ['SINTRABOARD STANDEE', 'SINTRA BOARD STANDEE'],
        merchandise: ['MERCHANDISE', 'SOUVENIR']
    };

    function canonicalCategoryKind(category) {
        const normalized = normalize(category);
        if (!normalized) return 'unknown';
        for (const [kind, aliases] of Object.entries(CATEGORY_CONCEPT_ALIASES)) {
            if (aliases.some(alias => {
                const token = normalize(alias);
                const singular = normalized.replace(/\bSTICKERS\b/g, 'STICKER').replace(/\bSTANDEES\b/g, 'STANDEE');
                return singular === token || new RegExp('(?:^| )' + token.replace(/ /g, ' +') + '(?: |$)').test(singular);
            })) return kind;
        }
        return 'unknown';
    }

    function classifyServiceFromName(context) {
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
        if ((/COLORED|CUT ONLY|CUT OUT|CUT STICKER|PLOTTER/.test(structuredText) || /COLORED CUT STICKER|CUT STICKER/.test(service)) && /STICKER|DECAL/.test(service)) return 'cut_sticker';
        if ((/PRINTED|PRINTABLE|STICKER PAPER|NEXJET|PP STKR|HOLOGRAM|TRANSPARENT/.test(structuredText) || /PRINTED STICKER/.test(service)) && /STICKER|DECAL/.test(service)) return 'printed_sticker';
        if (/TRANSPARENT STICKER|GLASS .*STICKER|WALL .*STICKER/.test(service)) return 'printed_sticker';
        if (/STICKER|DECAL/.test(service)) return 'sticker_unknown';
        return 'unknown';
    }

    function classifyService(context) {
        const fromName = classifyServiceFromName(context || {});
        if (SPECIFIC_SERVICE_NAME_KINDS.has(fromName)) {
            return fromName;
        }

        const categoryKind = canonicalCategoryKind(context && context.serviceCategory);
        if (categoryKind === 'stickers') {
            if (fromName === 'reflective_sticker' || fromName === 'cut_sticker' || fromName === 'printed_sticker') {
                return fromName;
            }
            return 'sticker_unknown';
        }
        if (categoryKind === 'merchandise') {
            return fromName === 'mug' ? 'mug' : 'unknown';
        }
        if (categoryKind !== 'unknown') {
            return categoryKind;
        }

        return fromName;
    }

    function matchingRule(item, serviceType, rules) {
        const itemId = String(item && item.id);
        const service = normalize(serviceType);
        return (Array.isArray(rules) ? rules : []).find(rule =>
            String(rule.item_id) === itemId && normalize(rule.service_type) === service
        ) || null;
    }

    function matchingContextRule(item, context, rules) {
        const candidates = [
            context && context.serviceType,
            context && context.serviceName,
            context && context.serviceLabel,
            context && context.serviceCategory
        ].map(normalize).filter(Boolean);
        for (const candidate of candidates) {
            const rule = matchingRule(item, candidate, rules);
            if (rule) return rule;
        }
        return null;
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
        const exactRule = matchingContextRule(item, context || {}, rules);
        const exactRuleType = normalize(exactRule && exactRule.rule_type);
        if (exactRuleType === 'REQUIRED') {
            tier = 'recommended';
        } else if (exactRuleType === 'OPTIONAL') {
            tier = 'optional';
        } else if (serviceKind === 'sintraboard' && family === 'sintra') {
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
            tier = tierForCategory('stickers', family);
        } else if (serviceKind === 'signage') {
            tier = tierForCategory('signage', family);
        } else if (serviceKind === 'print') {
            tier = tierForCategory('print', family);
        } else if (serviceKind === 'stickers') {
            tier = tierForCategory('stickers', family);
        } else if (serviceKind === 'unknown') {
            tier = exactRule ? tier : 'unverified';
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
            sticker_media: 'Sticker / adhesive material',
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

    /**
     * Extracts the roll/finished width (in ft) encoded in a tarpaulin item's name,
     * e.g. "4ft Tarpaulin" / "TARPAULIN 4FT" / "4FT TARP" -> 4.
     * Returns null when no numeric width can be determined.
     */
    function tarpaulinRollWidthFor(item) {
        const name = normalize(item && item.name);
        const match = name.match(/(\d+(?:\.\d+)?)\s*FT/);
        if (!match) return null;
        const width = parseFloat(match[1]);
        return Number.isFinite(width) && width > 0 ? width : null;
    }

    /**
     * Deterministically ranks in-stock tarpaulin candidates against the customer's
     * finished width/height. Production can rotate artwork, so either finished
     * dimension may be placed across the roll width; the other dimension is fed
     * along the roll's length (no fixed ceiling, but it is the material actually
     * consumed). Preferring the orientation that fits the LARGER finished
     * dimension across the width minimizes length consumed (least waste); only
     * when no roll is wide enough for that do we fall back to fitting the
     * smaller dimension (which consumes more length). Smallest matching width
     * within a tier is ranked first.
     */
    function rankTarpaulinRolls(candidates, customerWidth, customerHeight) {
        const dims = [Number.parseFloat(customerWidth) || 0, Number.parseFloat(customerHeight) || 0].filter(dim => dim > 0);
        if (!dims.length) return [];
        const primaryDim = Math.max(...dims);
        const secondaryDim = Math.min(...dims);
        return candidates
            .map(item => ({ item, rollWidth: tarpaulinRollWidthFor(item) }))
            .filter(candidate => Number.isFinite(candidate.rollWidth) && candidate.rollWidth > 0)
            .map(candidate => {
                if (candidate.rollWidth >= primaryDim) {
                    return { ...candidate, fits: true, tier: 0, waste: candidate.rollWidth - primaryDim };
                }
                if (candidate.rollWidth >= secondaryDim) {
                    return { ...candidate, fits: true, tier: 1, waste: candidate.rollWidth - secondaryDim };
                }
                return { ...candidate, fits: false, tier: 2, waste: Infinity };
            })
            .filter(candidate => candidate.fits)
            .sort((a, b) => a.tier - b.tier || a.rollWidth - b.rollWidth || a.waste - b.waste);
    }

    /**
     * Deterministic, explainable single-material auto-select rule (no ML/AI):
     * - Tarpaulin: pick the smallest in-stock roll width that fits either finished
     *   dimension; only auto-select when it is an unambiguous (strictly smallest) winner.
     * - Other services: auto-select only when exactly one recommended, in-stock
     *   material exists (canonical service/material mapping already resolved it).
     * Returns the matching item, or null when no safe/unambiguous match exists.
     */
    function getAutoSelectCandidate(items, context, rules) {
        const recommended = rankItems(items, context, rules, '')
            .filter(item => item.compatibility.tier === 'recommended' && item.compatibility.directSelectable);
        if (!recommended.length) return null;

        const serviceKind = classifyService(context || {});
        if (serviceKind === 'tarpaulin') {
            const fitted = rankTarpaulinRolls(recommended, context && context.customerWidth, context && context.customerHeight);
            if (!fitted.length) return null;
            if (fitted.length === 1) return fitted[0].item;
            const isClearWinner = fitted[0].tier < fitted[1].tier || fitted[0].rollWidth < fitted[1].rollWidth;
            return isClearWinner ? fitted[0].item : null;
        }

        return recommended.length === 1 ? recommended[0] : null;
    }

    function inkModeFor(item) {
        const family = familyFor(item);
        if (family === 'tarpaulin') return 'tarp';
        if (['mug', 'subli_paper', 'printed_sticker', 'c2s_board', 'c2s_special_paper', 'photo_paper'].includes(family)) return 'standard';
        if (['heat_vinyl', 'colored_sticker', 'reflective', 'plate', 'sintra'].includes(family)) return 'none';
        return 'unknown';
    }

    return {
        normalize, materialText, familyFor, canonicalCategoryKind, classifyServiceFromName, classifyService, classifyItem,
        descriptionFor, searchScore, rankItems, inkModeFor, tarpaulinRollWidthFor, getAutoSelectCandidate
    };
});
