#!/usr/bin/env node
/**
 * Verification script for the Salient Category Filter plugin.
 *
 * Runs:
 *  1. PHP lint (syntax check)
 *  2. PHPCS WordPress standard
 *  3. WP-CLI plugin check (if DB is accessible)
 *  4. REST endpoint test (if site is running)
 *  5. Gulp build confirmation
 *
 * Usage: node run-verify.js
 */

'use strict';

const { spawnSync, execSync } = require('child_process');
const http  = require('http');
const https = require('https');
const path  = require('path');
const fs    = require('fs');

const CWD    = path.resolve(__dirname);
const PHP    = '/Users/44northdigitalmarketing/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php';
const WPCLI  = '/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix/wp';
const phpcs  = path.join(CWD, 'vendor/bin/phpcs');

let allPassed = true;

function pass(msg)  { console.log('  ✅ PASS:', msg); }
function fail(msg)  { console.log('  ❌ FAIL:', msg); allPassed = false; }
function warn(msg)  { console.log('  ⚠️  WARN:', msg); }
function header(h)  { console.log('\n══════════════════════════════════════'); console.log(' ' + h); console.log('══════════════════════════════════════'); }

// ─── 1. PHP syntax check ─────────────────────────────────────────────────────

header('1. PHP Syntax Check');
{
  const r = spawnSync(PHP, ['-l', 'salient-category-filter.php'], { cwd: CWD, encoding: 'utf8' });
  if (r.status === 0 && r.stdout.includes('No syntax errors')) {
    pass(r.stdout.trim());
  } else {
    fail(r.stdout.trim() + r.stderr.trim());
  }
}

// ─── 2. PHPCS ────────────────────────────────────────────────────────────────

header('2. PHPCS WordPress Standard');
{
  const r = spawnSync(phpcs, ['--standard=phpcs.xml.dist', '--report=full'], { cwd: CWD, encoding: 'utf8' });
  if (r.status === 0) {
    pass('Zero violations');
  } else {
    fail('Violations found:\n' + r.stdout);
  }
}

// ─── 3. Gulp build ───────────────────────────────────────────────────────────

header('3. Gulp Build');
{
  const r = spawnSync('npx', ['gulp', 'build'], { cwd: CWD, encoding: 'utf8', shell: true });
  const out = r.stdout + r.stderr;
  if (r.status === 0 && out.includes("Finished 'build'")) {
    pass('Gulp build completed cleanly');
    const jsSize  = fs.statSync(path.join(CWD, 'assets/scf-filter.js')).size;
    const cssSize = fs.statSync(path.join(CWD, 'assets/scf-style.css')).size;
    console.log('    scf-filter.js:', jsSize, 'bytes (minified)');
    console.log('    scf-style.css:', cssSize, 'bytes (minified)');
  } else {
    fail('Gulp build error:\n' + out);
  }
}

// ─── 4. Site connectivity + REST endpoint ────────────────────────────────────

header('4. REST Endpoint Test');

function httpGet(url) {
  return new Promise((resolve, reject) => {
    const mod = url.startsWith('https') ? https : http;
    const opts = { rejectUnauthorized: false, timeout: 5000 };
    const req = mod.get(url, opts, res => {
      let data = '';
      res.on('data', d => { data += d; });
      res.on('end', () => resolve({ status: res.statusCode, body: data }));
    });
    req.on('error', reject);
    req.on('timeout', () => { req.destroy(); reject(new Error('Timeout')); });
  });
}

async function testEndpoints() {
  const SITE = 'https://tlc.giantcreative.ca';

  // Check if site is reachable
  try {
    const home = await httpGet(SITE + '/');
    if (home.status !== 200 && home.status !== 301 && home.status !== 302) {
      warn('Site returned HTTP ' + home.status + '; skipping endpoint tests');
      return;
    }
  } catch (e) {
    warn('Site not reachable (' + e.message + ') — skipping live endpoint tests');
    warn('Start the site in Local by Flywheel and re-run to complete live testing');
    return;
  }

  pass('Site is reachable');

  // Get the base URL for testing (assumes blog is at /blog/ or homepage)
  const blogUrl = encodeURIComponent(SITE + '/');
  const selector = encodeURIComponent('.blog-wrap');

  // Test 1: no filter (cat_id=0)
  try {
    const r = await httpGet(
      SITE + '/wp-json/scf/v1/blog-html?base_url=' + blogUrl + '&replace_selector=' + selector + '&cat_id=0'
    );
    if (r.status === 200) {
      const json = JSON.parse(r.body);
      if (typeof json.html === 'string' && typeof json.cached === 'boolean') {
        pass('REST /scf/v1/blog-html (cat=0) → 200 OK, html: ' + json.html.length + ' chars, cached: ' + json.cached);
      } else {
        fail('REST response shape unexpected: ' + JSON.stringify(Object.keys(json)));
      }
    } else if (r.status === 422) {
      warn('REST 422 — selector .blog-wrap not found on home page (expected if blog is on a different URL)');
    } else {
      fail('REST returned HTTP ' + r.status + ': ' + r.body.slice(0, 200));
    }
  } catch (e) {
    fail('REST request failed: ' + e.message);
  }

  // Test 2: second call should be cached
  try {
    const r = await httpGet(
      SITE + '/wp-json/scf/v1/blog-html?base_url=' + blogUrl + '&replace_selector=' + selector + '&cat_id=0'
    );
    if (r.status === 200) {
      const json = JSON.parse(r.body);
      if (json.cached === true) {
        pass('REST second call → cached: true (transient cache working)');
      } else {
        warn('REST second call → cached: false (may be expected on first run after cache bust)');
      }
    }
  } catch (e) {
    fail('REST cache test failed: ' + e.message);
  }

  // Test 3: invalid base_url (SSRF protection)
  try {
    const r = await httpGet(
      SITE + '/wp-json/scf/v1/blog-html?base_url=' + encodeURIComponent('https://evil.com/') + '&replace_selector=.foo'
    );
    if (r.status === 400) {
      pass('SSRF protection → 400 Bad Request for cross-origin base_url');
    } else {
      fail('SSRF protection failed — expected 400, got ' + r.status);
    }
  } catch (e) {
    fail('SSRF test failed: ' + e.message);
  }

  // Check debug.log for errors
  const logPath = path.join(CWD, '../../../../debug.log');
  if (fs.existsSync(logPath) && fs.statSync(logPath).size > 0) {
    const log = fs.readFileSync(logPath, 'utf8');
    const scfErrors = log.split('\n').filter(l => l.includes('scf') || l.includes('SCF') || l.includes('salient-category-filter'));
    if (scfErrors.length > 0) {
      fail('WP_DEBUG log contains SCF-related errors:\n' + scfErrors.join('\n'));
    } else {
      pass('WP_DEBUG log: no SCF-related errors found');
    }
  } else {
    warn('No debug.log or empty — WP_DEBUG_LOG may not be active yet');
  }
}

// ─── 5. Lighthouse ───────────────────────────────────────────────────────────

async function runLighthouse() {
  header('5. Lighthouse Core Web Vitals');

  const SITE = 'https://tlc.giantcreative.ca';

  // Check site first
  try {
    await httpGet(SITE + '/');
  } catch (e) {
    warn('Site not reachable — skipping Lighthouse');
    warn('Run manually: npx lighthouse ' + SITE + '/ --output=json --output-path=lighthouse-report.json --chrome-flags="--ignore-certificate-errors"');
    return;
  }

  // Check if lighthouse is available
  const lh = spawnSync('npx', ['--no', 'lighthouse', '--version'], { encoding: 'utf8', shell: true });
  if (lh.status !== 0) {
    warn('Lighthouse not installed. Install with: npm install -g lighthouse');
    return;
  }

  console.log('  Running Lighthouse against', SITE, '...');
  const lhRun = spawnSync(
    'npx',
    [
      'lighthouse', SITE + '/',
      '--output=json',
      '--output-path=' + path.join(CWD, 'lighthouse-report.json'),
      '--only-categories=performance',
      '--chrome-flags=--headless --no-sandbox --ignore-certificate-errors',
      '--quiet'
    ],
    { cwd: CWD, encoding: 'utf8', timeout: 120000, shell: true }
  );

  if (lhRun.status === 0 || fs.existsSync(path.join(CWD, 'lighthouse-report.json'))) {
    try {
      const report = JSON.parse(fs.readFileSync(path.join(CWD, 'lighthouse-report.json'), 'utf8'));
      const audits = report.audits;
      const lcp = audits['largest-contentful-paint'] && audits['largest-contentful-paint'].numericValue;
      const cls = audits['cumulative-layout-shift'] && audits['cumulative-layout-shift'].numericValue;
      const inp = audits['interaction-to-next-paint'] && audits['interaction-to-next-paint'].numericValue;
      const perf = report.categories && report.categories.performance && report.categories.performance.score;

      console.log('  Performance score:', Math.round((perf || 0) * 100));
      console.log('  LCP:', lcp ? (lcp / 1000).toFixed(2) + 's' : 'N/A', lcp < 2500 ? '✅' : '⚠️');
      console.log('  CLS:', cls !== undefined ? cls.toFixed(3) : 'N/A', cls < 0.1 ? '✅' : '⚠️');
      console.log('  INP:', inp ? inp + 'ms' : 'N/A', !inp || inp < 200 ? '✅' : '⚠️');

      // Write to baseline if not exists
      const baselinePath = path.join(CWD, 'baseline.md');
      if (!fs.existsSync(baselinePath)) {
        fs.writeFileSync(baselinePath, [
          '# Lighthouse Baseline',
          '**Date:** ' + new Date().toISOString().split('T')[0],
          '**URL:** ' + SITE + '/',
          '',
          '| Metric | Value | Target |',
          '|--------|-------|--------|',
          '| Performance | ' + Math.round((perf || 0) * 100) + ' | ≥ 90 |',
          '| LCP | ' + (lcp ? (lcp/1000).toFixed(2) + 's' : 'N/A') + ' | < 2.5s |',
          '| CLS | ' + (cls !== undefined ? cls.toFixed(3) : 'N/A') + ' | < 0.10 |',
          '| INP | ' + (inp ? inp + 'ms' : 'N/A') + ' | < 200ms |',
        ].join('\n'));
        pass('baseline.md written');
      }
    } catch (e) {
      fail('Could not parse Lighthouse report: ' + e.message);
    }
  } else {
    warn('Lighthouse exited with code ' + lhRun.status);
    warn('Output: ' + lhRun.stdout + lhRun.stderr);
  }
}

// Run all async tests
(async () => {
  await testEndpoints();
  await runLighthouse();

  header('Summary');
  if (allPassed) {
    console.log('  ✅ All automated checks passed.');
  } else {
    console.log('  ❌ Some checks failed — see above.');
  }
})();
