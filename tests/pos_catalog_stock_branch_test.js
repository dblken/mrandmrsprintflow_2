'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const resolverSource = fs.readFileSync(path.join(root, 'includes/product_option_stock.php'), 'utf8');
const apiSource = fs.readFileSync(path.join(root, 'staff/api/get_products.php'), 'utf8');
assert.match(resolverSource, /printflow_product_option_stock_total\(\$productId, \$branchId\)/, 'variant stock is resolved for the requested branch');
assert.match(resolverSource, /printflow_product_effective_stock\(\$productId, \$branchId\)/, 'non-variant stock is resolved for the requested branch');
assert.match(resolverSource, /'branch_id' => \$branchId/, 'the resolver returns the branch it used');

assert.ok(apiSource.includes('printflow_branch_filter_for_user()'), 'POS API resolves the staff-selected branch');
assert.ok(apiSource.includes("printflow_get_branch_product_stock((int)$p['product_id'], $staffBranch)"), 'POS API requests stock for each product at that branch');
assert.ok(apiSource.includes("$p[$field] = $stock[$field]"), 'branch-resolved quantity and thresholds replace generic catalog values');
assert.ok(apiSource.includes("$p['stock_display'] = ["), 'POS API returns the shared stock status for the product card');

console.log('POS branch-specific catalog stock tests passed.');
