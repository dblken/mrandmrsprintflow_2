const fs = require('node:fs');
const assert = require('node:assert/strict');

const profile = fs.readFileSync('customer/profile.php', 'utf8');
const legacy = fs.readFileSync('customer/api_testing_data_cleanup.php', 'utf8');
const preview = fs.readFileSync('admin/api_jenny_cleanup_preview.php', 'utf8');

assert.doesNotMatch(profile, /pf-open-testing-cleanup|pf-testing-cleanup-modal|Delete My Testing Data/);
assert.match(legacy, /http_response_code\(410\)/);
assert.doesNotMatch(legacy, /DELETE\s+FROM|begin_transaction|PF_JENNY_CLEANUP_DELETE_ORDER/);
assert.match(preview, /is_admin\(\)/);
assert.match(preview, /REQUEST_METHOD.*GET/);
assert.match(preview, /customer_id` = \?/);
assert.match(preview, /array_chunk\(\$parentIds, 80\)/);
assert.match(preview, /usleep\(100000\)/);
assert.match(preview, /db_error_code/);
assert.match(preview, /scope_ownership_complete/);
assert.match(preview, /deletion_enabled' => false/);
assert.match(preview, /preview_safe_for_deletion' => false/);
assert.match(preview, /polymorphic_order_notification_batch_/);
assert.match(preview, /push_queue_notification_batch_/);
assert.doesNotMatch(preview, /csrf_token|preview_token/);
assert.doesNotMatch(preview, /\b(?:DELETE|UPDATE|INSERT)\s+INTO?\b/i);
assert.doesNotMatch(preview, /\b(?:DELETE|UPDATE|INSERT)\s+(?:FROM|INTO)\b/i);

console.log('Jenny Admin cleanup preview contract checks passed.');
