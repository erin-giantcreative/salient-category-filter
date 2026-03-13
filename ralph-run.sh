#!/bin/bash

# =============================================================================
# RALPH WIGGUM - WordPress Child Theme + Plugin Optimization
# Master script: chains all 15 loops in order
# =============================================================================
# USAGE:
#   cd wp-content/themes/your-child-theme
#   bash ralph-run.sh
#
# REQUIREMENTS:
#   - Claude Code installed and authenticated
#   - ralph-loop plugin installed:
#       /plugin install ralph-loop@claude-plugins-official
#   - WP_DEBUG=true in wp-config.php before running
#   - Gulp installed in theme root: npm install
# =============================================================================



LOG_FILE="ralph-run.log"
TIMESTAMP=$(date +"%Y-%m-%d %H:%M:%S")

log() {
  echo ""
  echo "=============================================="
  echo "  $1"
  echo "  $(date +"%H:%M:%S")"
  echo "=============================================="
  echo "[$( date +"%Y-%m-%d %H:%M:%S")] $1" >> "$LOG_FILE"
}

run_loop() {
  local LOOP_NUM=$1
  local LABEL=$2
  local PROMPT=$3

  log "LOOP $LOOP_NUM — $LABEL"
  claude -p "$PROMPT"

  if [ $? -ne 0 ]; then
    echo ""
    echo "⚠️  Loop $LOOP_NUM ($LABEL) exited with an error."
    echo "   Check $LOG_FILE and review the output before continuing."
    read -p "   Press ENTER to continue to next loop, or CTRL+C to stop: "
  else
    echo "✅  Loop $LOOP_NUM complete."
  fi
}

echo "" >> "$LOG_FILE"
echo "==============================" >> "$LOG_FILE"
echo "RUN STARTED: $TIMESTAMP" >> "$LOG_FILE"
echo "==============================" >> "$LOG_FILE"

echo ""
echo "🚀 Starting Ralph Wiggum optimization run"
echo "   Log file: $LOG_FILE"
echo "   Total loops: 15"
echo ""
read -p "Press ENTER to begin or CTRL+C to cancel: "


# ==============================================================================
# CHILD THEME AUDITS (Loops 1–4)
# ==============================================================================

run_loop 1 "Child Theme Audit" \
'/ralph-loop:ralph-loop "Audit this WordPress child theme for Core Web Vitals issues.

Process:
1. Review functions.php, gulpfile.js, and src/scss/
2. List all render-blocking scripts and styles
3. List all unminified assets being enqueued
4. Check for jQuery dependencies
5. Check images for missing width/height and lazy load attributes
6. Check for Google Fonts or third-party resources
7. Write all findings ranked by CWV impact to baseline.md

Constraints:
- Read-only, no file changes

Output <promise>REFACTORED</promise> when complete." --max-iterations 10 --completion-promise "REFACTORED"' \


run_loop 2 "Child Theme Gulp Pipeline Upgrade" \
'/ralph-loop:ralph-loop "Upgrade the Gulp pipeline for Core Web Vitals optimization.

Process:
1. Add CSS minification via cssnano
2. Add autoprefixer via postcss
3. Add JS minification via terser
4. Add JS concatenation via gulp-concat
5. Add image optimization via imagemin
6. Add critical CSS extraction via the critical package
7. Add separate gulp dev and gulp build tasks
8. Run gulp build and fix any errors before continuing

Constraints:
- Existing SCSS to CSS compilation must keep working
- Do not change any asset paths referenced in functions.php

Output <promise>REFACTORED</promise> when complete." --max-iterations 20 --completion-promise "REFACTORED"' \


run_loop 3 "Child Theme SCSS Architecture" \
'/ralph-loop:ralph-loop "Refactor SCSS architecture to support critical CSS splitting.

Process:
1. Audit compiled CSS for unused selectors and remove them
2. Create a critical partial for above-the-fold styles (typography, layout, hero)
3. Move remaining styles into a deferred partial
4. Update gulpfile.js to compile critical and deferred as separate output files
5. Run gulp build after each change
6. Fix any compile errors before continuing
7. Verify visual output is pixel-identical

Constraints:
- Visual output must be identical before and after
- gulp build must compile cleanly at every step

Output <promise>REFACTORED</promise> when complete." --max-iterations 20 --completion-promise "REFACTORED"' \


run_loop 4 "Child Theme PHP Enqueue" \
'/ralph-loop:ralph-loop "Refactor WordPress asset enqueuing for Core Web Vitals.

Process:
1. Add defer attribute to non-critical JS via script_loader_tag filter
2. Load non-critical CSS asynchronously
3. Inline compiled critical CSS into wp_head
4. Self-host Google Fonts if present
5. Add font-display: swap to all @font-face rules
6. After each change verify WP_DEBUG shows zero new PHP errors

Constraints:
- All scripts and styles must still load and function correctly
- No direct <script> tags in template files
- WP_DEBUG must show zero new errors throughout

Output <promise>REFACTORED</promise> when complete." --max-iterations 20 --completion-promise "REFACTORED"' \


# ==============================================================================
# PLUGIN AUDITS (Loops 5–11)
# ==============================================================================

run_loop 5 "Plugin Code Quality Audit" \
'/ralph-loop:ralph-loop "Audit this WordPress plugin for code quality and best practices.

Process:
1. Check all PHP files follow WordPress Coding Standards (indentation, naming conventions, spacing)
2. Check all functions are prefixed to avoid naming collisions
3. Check all hooks use proper priority and argument counts
4. Check for direct database queries — should use $wpdb or WP_Query
5. Check for hardcoded URLs or paths — should use plugins_url() and plugin_dir_path()
6. Check nonces are used on all form submissions and AJAX calls
7. Check all user input is sanitized on the way in and escaped on the way out
8. Check for any deprecated WordPress functions
9. Write all findings with file name and line number to audit-code-quality.md

Constraints:
- Read-only, no file changes

Output <promise>REFACTORED</promise> when complete." --max-iterations 10 --completion-promise "REFACTORED"' \


run_loop 6 "Plugin PHP Standards Audit" \
'/ralph-loop:ralph-loop "Audit this WordPress plugin for PHP and WordPress compliance.

Process:
1. Check PHP version compatibility (target PHP 8.1+)
2. Check all classes follow OOP best practices — single responsibility, no god classes
3. Check plugin headers are complete (Name, Version, Requires at least, Tested up to, Requires PHP)
4. Check activation, deactivation, and uninstall hooks are all implemented
5. Check text domain is consistent and all strings are translation-ready with __() or esc_html__()
6. Check no output is sent before headers (no PHP warnings or stray whitespace)
7. Check options are cleaned up on uninstall — nothing left in wp_options
8. Run PHPCS if available and log any violations
9. Write all findings with severity (critical/warning/info) to audit-php-standards.md

Constraints:
- Read-only, no file changes

Output <promise>REFACTORED</promise> when complete." --max-iterations 10 --completion-promise "REFACTORED"' \


run_loop 7 "Plugin AJAX & REST API Performance Audit" \
'/ralph-loop:ralph-loop "Audit all AJAX calls and REST API endpoints in this WordPress plugin for performance.

Process:
1. List every wp_ajax_ and wp_ajax_nopriv_ action and what each does
2. List every REST API endpoint registered via register_rest_route()
3. For each AJAX action check: is it doing unnecessary DB queries, is it missing caching, is it running on every page load
4. For each REST endpoint check: are permissions set correctly, is data being cached with transients or object cache, is the response payload minimal
5. Check if any AJAX calls can be replaced with a REST endpoint for better performance
6. Check if wp_localize_script is passing more data than needed on page load
7. Check for N+1 query problems in any loops that call the DB
8. Check if any calls can be batched into a single request
9. Measure and log estimated DB queries per AJAX call
10. Write all findings with suggested fixes to audit-ajax-performance.md

Constraints:
- Read-only, no file changes

Output <promise>REFACTORED</promise> when complete." --max-iterations 10 --completion-promise "REFACTORED"' \


run_loop 8 "Plugin Frontend Assets Audit" \
'/ralph-loop:ralph-loop "Audit all frontend assets (JS, CSS, SCSS) in this WordPress plugin.

Process:
1. List all enqueued scripts and styles and check they use wp_enqueue_script/wp_enqueue_style correctly
2. Check scripts and styles are only loaded on pages where the shortcode or widget is active — not globally
3. Check for missing version numbers on enqueued assets (cache busting)
4. Check if JS is loaded in the footer where possible
5. Check for any inline scripts that could be moved to an external file
6. Check SCSS structure — is it organized, are there unused rules, is output minified
7. Check if the plugin duplicates any scripts already enqueued by Salient theme (jQuery, etc.)
8. Check if a Gulp pipeline exists — if not, note what would be gained by adding one
9. Check all assets have defer or async where appropriate
10. Write all findings to audit-frontend-assets.md

Constraints:
- Read-only, no file changes

Output <promise>REFACTORED</promise> when complete." --max-iterations 10 --completion-promise "REFACTORED"' \


run_loop 9 "Plugin Accessibility Audit" \
'/ralph-loop:ralph-loop "Audit this WordPress plugin for accessibility compliance.

Process:
1. Check all shortcode output for semantic HTML — headings in correct order, lists used properly
2. Check all interactive elements (buttons, links) have descriptive text or aria-label
3. Check all images have alt attributes
4. Check all form fields have associated labels
5. Check colour contrast if any inline styles set colours
6. Check AJAX-powered widgets update aria-live regions so screen readers are notified of changes
7. Check all custom elements are keyboard navigable (tab order, focus states)
8. Check for any use of outline: none without a focus-visible replacement
9. Check for any content that relies on colour alone to convey meaning
10. Check plugin output validates against WCAG 2.1 AA criteria
11. Write all findings with WCAG criterion reference to audit-accessibility.md

Constraints:
- Read-only, no file changes

Output <promise>REFACTORED</promise> when complete." --max-iterations 10 --completion-promise "REFACTORED"' \


run_loop 10 "Plugin Security Audit" \
'/ralph-loop:ralph-loop "Audit this WordPress plugin for security vulnerabilities.

Process:
1. Check every AJAX handler verifies a nonce with check_ajax_referer()
2. Check every REST endpoint has a permission_callback that is not __return_true
3. Check all $_POST, $_GET, $_REQUEST data is sanitized before use
4. Check all output is escaped with esc_html(), esc_attr(), esc_url(), or wp_kses()
5. Check for any direct file includes that use user-supplied input
6. Check for any SQL queries that concatenate user input instead of using $wpdb->prepare()
7. Check ABSPATH guard is at the top of every PHP file
8. Check for any sensitive data being exposed in REST API responses
9. Check for any open redirect vulnerabilities in redirect calls
10. Write all findings with severity (critical/high/medium/low) to audit-security.md

Constraints:
- Read-only, no file changes

Output <promise>REFACTORED</promise> when complete." --max-iterations 10 --completion-promise "REFACTORED"' \


run_loop 11 "Plugin Salient Compatibility Audit" \
'/ralph-loop:ralph-loop "Audit this WordPress plugin for compatibility with the Salient theme.

Process:
1. Check if the plugin enqueues any scripts or styles that conflict with Salient asset versions
2. Check if the plugin overrides any Salient hooks or filters unintentionally
3. Check if shortcode output uses Salient expected column and row classes where appropriate
4. Check if the custom element respects Salient spacing, typography, and colour variables
5. Check if the plugin CSS has any selectors with specificity high enough to break Salient styles
6. Check if WooCommerce compatibility is needed and handled if Salient WooCommerce styles are active
7. Check if the plugin works correctly with Salient built-in lazy loading and animation system
8. Write all findings to audit-salient-compatibility.md

Constraints:
- Read-only, no file changes

Output <promise>REFACTORED</promise> when complete." --max-iterations 10 --completion-promise "REFACTORED"' \


# ==============================================================================
# PLUGIN REFACTORS (Loops 12–14)
# ==============================================================================

log "All audits complete. Starting refactor loops."
echo ""
read -p "Review your audit .md files now if you want, then press ENTER to begin refactoring: "


run_loop 12 "Plugin Fix Code Quality, PHP Standards & Security" \
'/ralph-loop:ralph-loop "Refactor this WordPress plugin to fix all issues found in audit-code-quality.md and audit-php-standards.md and audit-security.md.

Process:
1. Read all three audit files before making any changes
2. Fix critical and high severity security issues first (nonces, sanitization, escaping, $wpdb->prepare)
3. Add ABSPATH guard to any PHP file missing it
4. Fix REST endpoint permission callbacks
5. Add function prefixes to any unprefixed functions
6. Fix all hook registrations with incorrect priority or argument counts
7. Replace deprecated WordPress functions with current equivalents
8. Fix all PHPCS violations
9. Refactor any god classes into smaller single-responsibility classes
10. Add missing activation, deactivation, and uninstall hooks
11. Wrap all translatable strings in __() or esc_html__() with correct text domain
12. Add cleanup logic to uninstall hook to remove plugin options from wp_options
13. After each fix verify WP_DEBUG shows zero new errors

Constraints:
- No changes to frontend behaviour or output
- WP_DEBUG must show zero errors throughout
- One logical fix per commit, critical issues first

Output <promise>REFACTORED</promise> when complete." --max-iterations 30 --completion-promise "REFACTORED"' \


run_loop 13 "Plugin Fix AJAX, REST API, Assets & Salient Compatibility" \
'/ralph-loop:ralph-loop "Refactor this WordPress plugin to fix all issues found in audit-ajax-performance.md and audit-frontend-assets.md and audit-salient-compatibility.md.

Process:
1. Read all three audit files before making any changes
2. Add transient caching to AJAX handlers and REST endpoints doing repeated DB queries
3. Fix all N+1 query problems by replacing loops with single queries using WHERE IN
4. Slim down wp_localize_script payloads to only what JS needs on load
5. Batch any multiple AJAX calls on the same user action into a single request
6. Wrap all enqueue calls so assets only load on pages where the shortcode or widget is active
7. Add version numbers to all enqueued assets
8. Move all script enqueues to the footer
9. Add defer to non-critical scripts via script_loader_tag filter
10. Remove any scripts or styles that duplicate Salient enqueues
11. If no Gulp pipeline exists create gulpfile.js with SCSS, autoprefixer, cssnano, terser, and imagemin
12. Resolve any script or style version conflicts with Salient
13. Fix any CSS selectors with specificity too high that override Salient styles
14. Update shortcode output to use Salient column and row classes where appropriate
15. Replace hardcoded colours and spacing with Salient CSS variables
16. Run gulp build after asset changes and fix any errors

Constraints:
- All existing AJAX and REST functionality must work identically
- All existing frontend output must be visually identical
- WP_DEBUG must show zero errors throughout

Output <promise>REFACTORED</promise> when complete." --max-iterations 30 --completion-promise "REFACTORED"' \


run_loop 14 "Plugin Fix Accessibility" \
'/ralph-loop:ralph-loop "Refactor this WordPress plugin to fix all issues found in audit-accessibility.md.

Process:
1. Read audit-accessibility.md before making any changes
2. Fix heading hierarchy in all shortcode output
3. Add aria-label to all interactive elements missing descriptive text
4. Add alt attributes to all images missing them
5. Add label elements or aria-labelledby to all form fields missing them
6. Fix any colour contrast violations in inline styles
7. Add aria-live="polite" to all containers updated by AJAX
8. Ensure all interactive elements are reachable and operable via keyboard
9. Replace any outline: none with a visible focus-visible style
10. Verify no information is conveyed by colour alone
11. After each fix validate the output against WCAG 2.1 AA criteria

Constraints:
- No changes to visual design beyond what is required for contrast and focus fixes
- All existing functionality must work identically

Output <promise>REFACTORED</promise> when complete." --max-iterations 25 --completion-promise "REFACTORED"' \


# ==============================================================================
# FINAL VERIFICATION (Loop 15)
# ==============================================================================

run_loop 15 "Final Verification & Report" \
'/ralph-loop:ralph-loop "Run a final verification pass across the entire theme and plugin after all refactoring is complete.

Process:
1. Re-read all audit .md files and verify every finding has been addressed
2. Run PHPCS and confirm zero violations
3. Run gulp build and confirm clean compile with zero errors
4. Check WP_DEBUG shows zero errors or warnings on page load
5. Test every AJAX call and REST endpoint and confirm they return correct responses
6. Test every shortcode on a Salient page and confirm correct visual output
7. Run Lighthouse on a page containing the plugin custom element and record LCP, CLS, INP
8. Compare Lighthouse scores against baseline.md from Loop 1
9. Write a final-report.md summarising all changes made, scores before and after, and any remaining recommendations

Output <promise>REFACTORED</promise> when complete." --max-iterations 15 --completion-promise "REFACTORED"' \


# ==============================================================================
# DONE
# ==============================================================================

log "ALL 15 LOOPS COMPLETE"
echo ""
echo "✅  Full run finished. Check final-report.md for your results."
echo "    Full log saved to: $LOG_FILE"
echo ""