const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '../admin/settings.php'), 'utf8');

assert.doesNotMatch(source, /Maintenance \/ Demo Data Tools/);
assert.doesNotMatch(source, /demo-seed-/);
assert.doesNotMatch(source, /printflowInitDemoSeedTools/);
assert.match(source, /function printflowInitSettingsPage\(\)/);

console.log('Demo seed UI retirement contract checks passed.');
