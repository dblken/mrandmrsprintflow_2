const assert = require('node:assert/strict');
const fs = require('node:fs');

const page = fs.readFileSync(__dirname + '/../staff/customizations.php', 'utf8');
const api = fs.readFileSync(__dirname + '/../admin/job_orders_api.php', 'utf8');
const start = page.indexOf('            parseBusinessDateValue(value) {');
const end = page.indexOf('            normalizeOrderRow(row) {', start);
assert.ok(start >= 0 && end > start, 'business date helpers exist');
const helpers = new Function('return ({' + page.slice(start, end) + '})')();
const context = Object.assign(Object.create(helpers), { dateFilter: 'CUSTOM', customDateFrom: '', customDateTo: '' });

const row = (business, fallback = {}) => ({ ...fallback, order_business_date: business });
assert.equal(context.orderBusinessDateKey(row('2026-09-01 00:00:00')), '2026-09-01');
assert.equal(context.businessDateMatchesRange(row('2026-09-01 00:00:00'), '2026-09-01', '2026-09-30'), true,
    'start date includes midnight on September 1');
assert.equal(context.businessDateMatchesRange(row('2026-09-30 23:59:59'), '2026-09-01', '2026-09-30'), true,
    'end date includes the full September 30 business day');
assert.equal(context.businessDateMatchesRange(row('2026-10-01 00:00:00'), '2026-09-01', '2026-09-30'), false,
    'October 1 is outside the September-only range');
assert.equal(context.businessDateMatchesRange(row('2026-10-15 23:59:59'), '2026-09-01', '2026-10-15'), true,
    'October 15 is included in the extended range');
assert.equal(context.businessDateMatchesRange(row('2026-10-16 00:00:00'), '2026-09-01', '2026-10-15'), false,
    'October 16 is outside the extended range');
assert.equal(context.orderBusinessDateKey(row('2026-09-30 23:59:59')), '2026-09-30',
    'timezone-free SQL DATETIME remains a Manila business wall date on any browser timezone');
assert.equal(context.orderBusinessDateValue({
    order_business_date: '', order_date: '2026-09-07 12:00:00', created_at: '2026-10-02 12:00:00'
}), '2026-09-07 12:00:00', 'filter and sort use the saved store business date before system creation time');

const zoned = context.parseBusinessDateValue('2026-09-30T16:30:00Z');
assert.ok(zoned instanceof Date && !Number.isNaN(zoned.getTime()), 'timestamps with an explicit timezone are parsed as instants');
assert.equal(context.orderBusinessDateKey(row('2026-09-30T16:30:00Z')), '2026-10-01',
    'timezone-qualified dates are compared in the application business timezone');
context.dateFilter = 'CUSTOM';
context.customDateFrom = '2026-09-01';
context.ordersHasMore = true;
context.orders = [row('2026-09-30 12:00:00'), row('2026-09-01 00:00:00')];
assert.equal(context.dateCoveragePending, true, 'date results remain pending until the ordered stream crosses below the start date');
context.orders.push(row('2026-08-31 23:59:59'));
assert.equal(context.dateCoveragePending, false, 'the date result set is complete once older rows prove the boundary was crossed');

const sortStart = page.indexOf('            get filteredOrders() {');
const sortEnd = page.indexOf('            buildOrderListItems(rows', sortStart);
assert.ok(sortStart >= 0 && sortEnd > sortStart, 'filtered order comparator exists');
const sorting = new Function('return ({' + page.slice(sortStart, sortEnd) + '})')();
const datedRows = [
    {order_id: 9101, order_business_date: '2026-09-15 10:00:00', updated_at: '2026-10-20 10:00:00'},
    {order_id: 9102, order_business_date: '2026-10-02 10:00:00', updated_at: '2026-09-30 10:00:00'},
    {order_id: 9103, order_business_date: '2026-09-17 10:00:00', updated_at: '2026-09-18 10:00:00'},
].map(item => ({...item, _businessTs: context.orderBusinessTimestamp(item)}));
const sortingContext = Object.assign(Object.create(sorting), {
    orders: datedRows,
    sortOrder: 'newest',
    activeStatus: 'ALL',
    matchesStatusTab: () => true,
    matchesNonStatusFilters: () => true,
});
assert.deepEqual(sortingContext.filteredOrders.map(item => item.order_id), [9102, 9103, 9101],
    'newest sort follows business chronology even when updated_at values disagree');
sortingContext.sortOrder = 'oldest';
assert.deepEqual(sortingContext.filteredOrders.map(item => item.order_id), [9101, 9103, 9102],
    'oldest sort is the reverse business chronology');

assert.ok(page.includes("this.$watch('customDateFrom'"), 'changing the custom start date triggers coverage and pagination refresh');
assert.ok(page.includes("this.$watch('customDateTo'"), 'changing the custom end date triggers coverage and pagination refresh');
assert.ok(page.includes('this.orders.some(row => {'), 'date filters load the sorted summary stream through the start boundary before counting pages');
assert.ok(page.includes('if (this.dateCoveragePending) return []'), 'partial date-range rows are held while coverage is still loading');
assert.ok(page.includes('if (this.dateCoveragePending) return false;'), 'pagination is hidden until the date-filtered total is complete');
assert.ok(page.includes('(b._businessTs || 0) - (a._businessTs || 0)'), 'newest/oldest sorting uses business timestamps');
assert.ok(!page.includes('const aUrgent = this.orderIsUrgentRequest(a) ? 1 : 0;'), 'urgent grouping cannot reorder dated results ahead of the selected chronology');
assert.ok(api.includes('ORDER BY COALESCE(o.order_date, jo.created_at) DESC,'), 'job-order SQL pages use the same business date and stable identity ordering');
assert.ok(api.includes("$a['order_business_date'] ?? $a['order_date'] ?? $a['created_at']"), 'combined API ordering prefers the same business date');
assert.ok(/\$dashboardFetchLimit\s*=\s*\$serviceOnly\s*\?\s*\$dashboardListOffset\s*\+\s*\$dashboardListLimit\s*\+\s*1/.test(api),
    'customization pagination fetches the full ordered prefix needed before merging and slicing pages');
assert.ok(api.includes(': min(500, $dashboardListOffset + $dashboardListLimit + 1);'),
    'non-customization callers retain their existing bounded fetch behavior');

console.log('PASS: custom date ranges are inclusive, timezone-aware, and aligned with stable business-date ordering and pagination coverage.');
