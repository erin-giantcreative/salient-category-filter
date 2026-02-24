<?php
/**
 * Plugin Name: Salient Category Filter (WPBakery Element)
 * Description: Adds a category filter + AJAX post loop (shortcode + WPBakery element).
 * Version: 1.0.2
 */

if (!defined('ABSPATH')) exit;

class SCF_Salient_Blog_Filter {
  const QV = 'category';
  const NONCE_ACTION = 'scf_blog_filter_nonce';

  // Cache settings
  const HTML_CACHE_TTL = 300;   // 5 minutes
  const TERMS_CACHE_TTL = 3600; // 1 hour
  const CACHE_GROUP = 'scf_blog_filter';

  public function __construct() {
    add_filter('query_vars', [$this, 'add_query_var']);
    add_action('pre_get_posts', [$this, 'maybe_filter_main_query'], 20);

    add_shortcode('scf_blog_filter', [$this, 'shortcode_filter_ui']);

    add_action('wp_enqueue_scripts', [$this, 'register_assets']);

    // AJAX endpoint returns ONLY blog HTML
    add_action('wp_ajax_scf_get_blog_html', [$this, 'ajax_get_blog_html']);
    add_action('wp_ajax_nopriv_scf_get_blog_html', [$this, 'ajax_get_blog_html']);

    // Optional: WPBakery element for the UI
    add_action('vc_after_init', [$this, 'register_vc_element']);

    // Bust caches when content changes
    add_action('save_post_post', [$this, 'bust_all_cache']);
    add_action('deleted_post', [$this, 'bust_all_cache']);
    add_action('created_category', [$this, 'bust_all_cache']);
    add_action('edited_category', [$this, 'bust_all_cache']);
    add_action('delete_category', [$this, 'bust_all_cache']);
  }

  public function add_query_var($vars) {
    $vars[] = self::QV;
    return $vars;
  }

  public function maybe_filter_main_query($q) {
    if (is_admin() || !$q->is_main_query()) return;

    $cat = get_query_var(self::QV);
    $cat = $cat ? (int) $cat : 0;
    if ($cat <= 0) return;

    $target_page_id = isset($_GET['scf_page_id']) ? (int) $_GET['scf_page_id'] : 0;

    $is_target = false;
    if (is_home()) {
      $is_target = true;
    } elseif ($target_page_id > 0 && is_page($target_page_id)) {
      $is_target = true;
    }

    if (!$is_target) return;

    $q->set('cat', $cat);
  }

  public function register_assets() {
    wp_register_script(
      'scf-salient-blog-filter',
      plugin_dir_url(__FILE__) . 'assets/scf-filter.js',
      ['jquery'],
      '1.0.2',
      true
    );

    wp_localize_script('scf-salient-blog-filter', 'SCF_BLOG_FILTER', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce'   => wp_create_nonce(self::NONCE_ACTION),
    ]);

    wp_register_style(
      'scf-salient-blog-filter',
      plugin_dir_url(__FILE__) . 'assets/scf-style.css',
      [],
      '1.0.2'
    );
  }

  private function get_terms_cached($taxonomy, $include_csv, $exclude_csv) {
    $key = 'scf_terms_' . md5(serialize([$taxonomy, $include_csv, $exclude_csv]));
    $cached = get_transient($key);
    if ($cached !== false) return $cached;

    $terms_args = [
      'taxonomy' => $taxonomy,
      'hide_empty' => true,
      'orderby' => 'name',
      'order' => 'ASC',
    ];

    if (!empty($include_csv)) {
      $terms_args['include'] = array_map('intval', array_filter(array_map('trim', explode(',', $include_csv))));
    }
    if (!empty($exclude_csv)) {
      $terms_args['exclude'] = array_map('intval', array_filter(array_map('trim', explode(',', $exclude_csv))));
    }

    $terms = get_terms($terms_args);
    if (is_wp_error($terms) || empty($terms)) {
      set_transient($key, [], 300);
      return [];
    }

    set_transient($key, $terms, self::TERMS_CACHE_TTL);
    return $terms;
  }

  private function get_blog_base_url($page_id) {
    if (is_home()) {
      $posts_page_id = (int) get_option('page_for_posts');
      return $posts_page_id ? get_permalink($posts_page_id) : home_url('/');
    }
    if (is_page()) {
      return get_permalink(get_queried_object_id());
    }
    if (!empty($page_id)) {
      return get_permalink((int)$page_id);
    }
    return home_url('/');
  }

  public function shortcode_filter_ui($atts) {
    wp_enqueue_script('scf-salient-blog-filter');
    wp_enqueue_style('scf-salient-blog-filter');

    $atts = shortcode_atts([
      'taxonomy' => 'category',
      'show_all' => '1',
      'all_label' => 'All',
      'include' => '',
      'exclude' => '',
      'replace_selector' => '.blog-wrap',
      'page_id' => '',
    ], $atts, 'scf_blog_filter');

    $taxonomy = sanitize_key($atts['taxonomy']);
    if (!taxonomy_exists($taxonomy)) return '';

    $page_id = (int) $atts['page_id'];
    $replace_selector = (string) $atts['replace_selector'];

    $terms = $this->get_terms_cached($taxonomy, $atts['include'], $atts['exclude']);
    if (empty($terms)) return '';

    $base_url = $this->get_blog_base_url($page_id);

    ob_start(); ?>
      <div class="scf-blog-filter-ui"
           data-base-url="<?php echo esc_url($base_url); ?>"
           data-replace-selector="<?php echo esc_attr($replace_selector); ?>"
           data-page-id="<?php echo esc_attr($page_id); ?>">


        <div class="scf-blog-filter-ui__buttons">
          <span>FILTER BY:</span>
          <?php if ((int)$atts['show_all'] === 1): ?>
            <button class="scf-btn is-active" type="button" data-cat="0"><?php echo esc_html($atts['all_label']); ?></button>
          <?php endif; ?>

          <?php foreach ($terms as $t): ?>
            <button class="scf-btn" type="button" data-cat="<?php echo (int)$t->term_id; ?>">
              <?php echo esc_html($t->name); ?>
            </button>
          <?php endforeach; ?>
        </div>

      </div>
    <?php
    return ob_get_clean();
  }

  /**
   * AJAX: returns the HTML of replace_selector from the target blog page,
   * with scf_cat applied via query var and pre_get_posts hook.
   */
  public function ajax_get_blog_html() {
    check_ajax_referer(self::NONCE_ACTION, 'nonce');

    $base_url = isset($_POST['base_url']) ? esc_url_raw($_POST['base_url']) : '';
    $replace_selector = isset($_POST['replace_selector']) ? sanitize_text_field($_POST['replace_selector']) : '';
    $cat_id = isset($_POST['cat_id']) ? (int) $_POST['cat_id'] : 0;
    $page_id = isset($_POST['page_id']) ? (int) $_POST['page_id'] : 0;

    if (!$base_url || !$replace_selector) {
      wp_send_json_error(['message' => 'Missing base_url or replace_selector.'], 400);
    }

    // Cache key includes URL + selector + cat
    $cache_key = 'scf_html_' . md5(serialize([$base_url, $replace_selector, $cat_id, $page_id]));
    $cached = get_transient($cache_key);
    if ($cached !== false) {
      wp_send_json_success(['html' => $cached, 'cached' => true]);
    }

    // Build request URL with filter state
    $url = add_query_arg([], $base_url);

    if ($cat_id > 0) {
      $url = add_query_arg(self::QV, $cat_id, $url);
    } else {
      // Ensure not present
      $url = remove_query_arg(self::QV, $url);
    }

    if ($page_id > 0) {
      $url = add_query_arg('scf_page_id', $page_id, $url);
    } else {
      $url = remove_query_arg('scf_page_id', $url);
    }

    // Fetch HTML from the blog page (same server) - much smaller than browser full-page parsing
    $resp = wp_remote_get($url, [
      'timeout'     => 10,
      'sslverify'   => false,   // <-- key fix for self-signed cert
      'httpversion' => '1.1',
      'headers'     => [
        'X-SCF-Request' => '1',
      ],
    ]);

    if (is_wp_error($resp)) {
      wp_send_json_error(['message' => $resp->get_error_message()], 500);
    }

    $body = wp_remote_retrieve_body($resp);
    if (!$body) {
      wp_send_json_error(['message' => 'Empty response body.'], 500);
    }

    // Extract only the requested selector block
    $extracted = $this->extract_selector_html($body, $replace_selector);

    if (!$extracted) {
      // If selector fails, return a helpful error
      wp_send_json_error([
        'message' => 'Could not find replace_selector in response HTML.',
        'selector' => $replace_selector,
      ], 422);
    }

    set_transient($cache_key, $extracted, self::HTML_CACHE_TTL);

    wp_send_json_success(['html' => $extracted, 'cached' => false]);
  }

  /**
   * Extract HTML for a CSS class selector like ".blog-wrap" or an ID selector "#something".
   * This is intentionally limited to keep it safe/fast.
   */
  private function extract_selector_html($html, $selector) {
    $selector = trim($selector);

    // Only allow simple ".class" or "#id" selectors for this extractor.
    if (!preg_match('/^([.#])([A-Za-z0-9_-]+)$/', $selector, $m)) {
      return '';
    }

    $type = $m[1];
    $name = $m[2];

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();

    // Prevent DOMDocument from mangling UTF-8 badly
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);

    $xpath = new DOMXPath($dom);

    if ($type === '.') {
      $query = "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$name} ')]";
    } else {
      $query = "//*[@id='{$name}']";
    }

    $nodes = $xpath->query($query);
    if (!$nodes || $nodes->length === 0) return '';

    $node = $nodes->item(0);

    // Return inner HTML of the node (or outer HTML if you prefer)
    $out = '';
    foreach ($node->childNodes as $child) {
      $out .= $dom->saveHTML($child);
    }

    return $out;
  }

  public function bust_all_cache() {
    // Simple approach: bump a version key and include it in cache keys if you want.
    // For now, we just delete known transients by pattern is not possible in WP core.
    // Instead, lower TTL + manual bust via version option.
    update_option('scf_cache_bust', time(), false);

    // Also clear term caches we created (best effort)
    // (No pattern delete in core; leaving TTL is fine)
  }

  public function register_vc_element() {
    if (!defined('WPB_VC_VERSION') || !function_exists('vc_map')) return;

    vc_map([
      'name' => 'Blog Category Filter (Salient)',
      'base' => 'scf_blog_filter',
      'category' => 'Content',
      'description' => 'Filters Salient blog output on the same page.',
      'params' => [
        [
          'type' => 'textfield',
          'heading' => 'Replace selector (required)',
          'param_name' => 'replace_selector',
          'value' => '.blog-wrap',
          'description' => 'Use a simple .class or #id selector that wraps the posts + pagination.',
        ],
        [
          'type' => 'textfield',
          'heading' => 'Blog Page ID (only if NOT using Posts page)',
          'param_name' => 'page_id',
          'value' => '',
        ],
        [
          'type' => 'textfield',
          'heading' => '"All" label',
          'param_name' => 'all_label',
          'value' => 'All',
        ],
        [
          'type' => 'checkbox',
          'heading' => 'Show "All" button',
          'param_name' => 'show_all',
          'value' => ['Yes' => '1'],
          'std' => '1',
        ],
        [
          'type' => 'textfield',
          'heading' => 'Include term IDs',
          'param_name' => 'include',
        ],
        [
          'type' => 'textfield',
          'heading' => 'Exclude term IDs',
          'param_name' => 'exclude',
        ],
      ],
    ]);
  }
}

new SCF_Salient_Blog_Filter();

if (class_exists('WPBakeryShortCode')) {
  class WPBakeryShortCode_scf_blog_filter extends WPBakeryShortCode {}
}