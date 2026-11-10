'use strict';
// Runs every tests/js/*.test.js with node, in sorted order; stops at the first failure.
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const files = fs.readdirSync(__dirname).filter((f) => f.endsWith('.test.js')).sort();
for (const f of files) {
  console.log('# ' + f);
  const r = spawnSync(process.execPath, [path.join(__dirname, f)], { stdio: 'inherit' });
  if (r.status !== 0) {
    console.error('FAILED: ' + f);
    process.exit(r.status || 1);
  }
}
console.log('All ' + files.length + ' JS test files passed.');
