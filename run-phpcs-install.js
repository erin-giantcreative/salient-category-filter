#!/usr/bin/env node
/**
 * Auto-fix PHPCS violations then report remaining ones.
 */
const { spawnSync } = require('child_process');
const path = require('path');

const CWD   = path.resolve(__dirname);
const phpcs  = path.join(CWD, 'vendor/bin/phpcs');
const phpcbf = path.join(CWD, 'vendor/bin/phpcbf');

function run(bin, args, opts) {
  const r = spawnSync(bin, args, { cwd: CWD, timeout: 60000, encoding: 'utf8', ...opts });
  if (r.stdout) process.stdout.write(r.stdout);
  if (r.stderr) process.stderr.write(r.stderr);
  return r.status;
}

console.log('\n=== Auto-fixing with phpcbf (phpcs.xml.dist) ===');
// phpcbf exits 1 even when it fixes things successfully — that is normal.
run(phpcbf, ['--standard=phpcs.xml.dist']);

console.log('\n=== Remaining violations after auto-fix ===');
const status = run(phpcs, ['--standard=phpcs.xml.dist', '--report=full']);
console.log('\nPHPCS exit code:', status, '(0 = no violations, 1 = violations found)');
process.exit(status);
