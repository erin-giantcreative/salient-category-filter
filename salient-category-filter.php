<?php
/**
 * Plugin Name: Salient Category Filter (WPBakery Element)
 * Description: Adds a category filter + AJAX post loop (shortcode + WPBakery element).
 * Version: 1.0.1
 */

if (!defined('ABSPATH')) exit;

class SCF_Salient_Blog_Filter {
  const QV = 'scf_cat';
  const NONCE_ACTION = 'scf_blog_filter_nonce';

  private static $used = false;

  public function __construct() {
    add_filter('query_vars', [$this, 'add_query_var']);
    add_action('pre_get_posts', [$this, 'maybe_filter_main_query'], 20);

    add_shortcode('scf_blog_filter', [$this, 'shortcode_filter_ui']);

    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

    // Optional: WPBakery element for the UI
    add_action('vc_after_init', [$this, 'register_vc_element']);
  }

  public function add_query_var($vars) {
    $vars[] = self::QV;
    return $vars;
  }

  /**
   * Adjust ONLY the main query on the target blog page.
   * By default this targets is_home() (Posts page).
   * If your blog is a normal Page with a Blog element, set page_id in shortcode/element.
   */
  public function maybe_filter_main_query($q) {
    if (is_admin() || !$q->is_main_query()) return;

    $cat = get_query_var(self::QV);
    $cat = $cat ? (int) $cat : 0;
    if ($cat <= 0) return;

    // Determine which page is being filtered:
    // Default: Posts page (is_home()).
    // OR a specific page (when scf_page_id is present).
    $target_page_id = isset($_GET['scf_page_id']) ? (int) $_GET['scf_page_id'] : 0;

    $is_target = false;

    if (is_home()) {
      $is_target = true;
    } elseif ($target_page_id > 0 && is_page($target_page_id)) {
      $is_target = true;
    }

    if (!$is_target) return;

    // Apply category constraint
    $q->set('cat', $cat);
  }

  public function enqueue_assets() {
    if (!self::$used) return;

    wp_register_script(
      'scf-filter',
      plugin_dir_url(__FILE__) . 'assets/scf-filter.js',
      ['jquery'],
      '1.0.0',
      true
    );

    wp_localize_script('scf-filter', 'SCF_BLOG_FILTER', [
      'nonce' => wp_create_nonce(self::NONCE_ACTION),
    ]);

    wp_enqueue_script('scf-filter');
  }

  public function shortcode_filter_ui($atts) {
    self::$used = true;

    $atts = shortcode_atts([
      'taxonomy' => 'category',
      'show_all' => '1',
      'all_label' => 'All',
      'include' => '',  // term IDs: 1,2,3
      'exclude' => '',  // term IDs: 1,2,3

      // IMPORTANT: selector for the Salient blog container to replace.
      // You must set this to a wrapper that contains the posts list.
      'replace_selector' => '.blog-wrap',

      // If your blog is a normal Page (not the Posts page),
      // set this to that page ID so pre_get_posts knows when to filter.
      'page_id' => '',
    ], $atts, 'scf_blog_filter');

    $taxonomy = sanitize_key($atts['taxonomy']);
    if (!taxonomy_exists($taxonomy)) return '';

    $terms_args = [
      'taxonomy' => $taxonomy,
      'hide_empty' => true,
      'orderby' => 'name',
      'order' => 'ASC',
    ];

    if (!empty($atts['include'])) {
      $terms_args['include'] = array_map('intval', array_filter(array_map('trim', explode(',', $atts['include']))));
    }
    if (!empty($atts['exclude'])) {
      $terms_args['exclude'] = array_map('intval', array_filter(array_map('trim', explode(',', $atts['exclude']))));
    }

    $terms = get_terms($terms_args);
    if (is_wp_error($terms) || empty($terms)) return '';

    $page_id = (int) $atts['page_id'];
    $replace_selector = (string) $atts['replace_selector'];

    // Base URL should be the current page permalink
    $base_url = is_singular() ? get_permalink() : home_url('/');

    ob_start(); ?>
      <div class="scf-blog-filter-ui"
           data-base-url="<?php echo esc_url($base_url); ?>"
           data-replace-selector="<?php echo esc_attr($replace_selector); ?>"
           data-page-id="<?php echo esc_attr($page_id); ?>">

        <div class="scf-blog-filter-ui__buttons">
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
          'description' => 'CSS selector of the Salient blog container to replace (inspect your blog area). Example: .blog-wrap or .posts-container',
        ],
        [
          'type' => 'textfield',
          'heading' => 'Blog Page ID (only if NOT using Posts page)',
          'param_name' => 'page_id',
          'value' => '',
          'description' => 'If your blog is a normal Page with a Blog element, enter that page ID. If you use the Posts page, leave blank.',
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
          'description' => 'Comma-separated category IDs to include.',
        ],
        [
          'type' => 'textfield',
          'heading' => 'Exclude term IDs',
          'param_name' => 'exclude',
          'description' => 'Comma-separated category IDs to exclude.',
        ],
      ],
    ]);
  }
}

new SCF_Salient_Blog_Filter();

if (class_exists('WPBakeryShortCode')) {
  class WPBakeryShortCode_scf_blog_filter extends WPBakeryShortCode {}
}