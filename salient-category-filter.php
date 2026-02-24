<?php
/**
 * Plugin Name: Salient Category Filter (WPBakery Element)
 * Description: Adds a category filter + AJAX post loop (shortcode + WPBakery element).
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

class SCF_Plugin {
  private static $used = false;
  const NONCE_ACTION = 'scf_nonce';

  public function __construct() {
    add_action('init', [$this, 'register_shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

    add_action('wp_ajax_scf_filter_posts', [$this, 'ajax_filter_posts']);
    add_action('wp_ajax_nopriv_scf_filter_posts', [$this, 'ajax_filter_posts']);

    // WPBakery element
    add_action('vc_before_init', [$this, 'register_vc_element']);
  }

  public function register_shortcode() {
    add_shortcode('salient_category_filter', [$this, 'shortcode']);
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

    wp_localize_script('scf-filter', 'SCF', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce'   => wp_create_nonce(self::NONCE_ACTION),
    ]);

    wp_enqueue_script('scf-filter');

    wp_register_style(
      'scf-style',
      plugin_dir_url(__FILE__) . 'assets/scf-style.css',
      [],
      '1.0.0'
    );

    wp_enqueue_style('scf-style');
  }

  public function shortcode($atts) {
    self::$used = true;
    $atts = shortcode_atts([
      'posts_per_page' => 9,
      'taxonomy' => 'category',
      'include' => '',       // comma IDs: 1,2,3
      'exclude' => '',       // comma IDs: 1,2,3
      'hide_empty' => 1,
      'orderby' => 'name',
      'order' => 'ASC',
      'show_all' => 1,
      'all_label' => 'All',
      'layout' => 'grid',    // grid|list
    ], $atts, 'salient_category_filter');

    $taxonomy = sanitize_key($atts['taxonomy']);
    if (!taxonomy_exists($taxonomy)) return '<p>Taxonomy not found.</p>';

    $uid = 'scf_' . wp_generate_uuid4();

    $terms_args = [
      'taxonomy' => $taxonomy,
      'hide_empty' => (bool) (int) $atts['hide_empty'],
      'orderby' => sanitize_key($atts['orderby']),
      'order' => sanitize_key($atts['order']) === 'DESC' ? 'DESC' : 'ASC',
    ];

    if (!empty($atts['include'])) {
      $terms_args['include'] = array_map('intval', array_filter(array_map('trim', explode(',', $atts['include']))));
    }
    if (!empty($atts['exclude'])) {
      $terms_args['exclude'] = array_map('intval', array_filter(array_map('trim', explode(',', $atts['exclude']))));
    }

    $terms = get_terms($terms_args);
    if (is_wp_error($terms)) return '<p>Could not load categories.</p>';

    $posts_per_page = (int) $atts['posts_per_page'];
    $layout = sanitize_key($atts['layout']);
    $show_all = (int) $atts['show_all'];
    $all_label = sanitize_text_field($atts['all_label']);

    ob_start(); ?>
      <div class="salient-cat-filter" id="<?php echo esc_attr($uid); ?>"
        data-posts-per-page="<?php echo esc_attr($posts_per_page); ?>"
        data-taxonomy="<?php echo esc_attr($taxonomy); ?>"
        data-layout="<?php echo esc_attr($layout); ?>">

        <div class="salient-cat-filter__buttons">
          <?php if ($show_all): ?>
            <button class="scf-btn is-active" data-term-id="0" type="button"><?php echo esc_html($all_label); ?></button>
          <?php endif; ?>

          <?php foreach ($terms as $term): ?>
            <button class="scf-btn" data-term-id="<?php echo (int) $term->term_id; ?>" type="button">
              <?php echo esc_html($term->name); ?>
            </button>
          <?php endforeach; ?>
        </div>

        <div class="salient-cat-filter__results">
          <?php echo $this->render_posts_html([
            'term_id' => 0,
            'taxonomy' => $taxonomy,
            'posts_per_page' => $posts_per_page,
            'layout' => $layout,
            'paged' => 1,
          ]); ?>
        </div>
      </div>
    <?php
    return ob_get_clean();
  }

  private function render_posts_html($args) {
    $term_id = (int) ($args['term_id'] ?? 0);
    $taxonomy = sanitize_key($args['taxonomy'] ?? 'category');
    $posts_per_page = (int) ($args['posts_per_page'] ?? 9);
    $layout = sanitize_key($args['layout'] ?? 'grid');
    $paged = max(1, (int) ($args['paged'] ?? 1));

    $query_args = [
      'post_type' => 'post',
      'post_status' => 'publish',
      'posts_per_page' => $posts_per_page,
      'paged' => $paged,
      'ignore_sticky_posts' => true,
    ];

    if ($term_id > 0) {
      $query_args['tax_query'] = [[
        'taxonomy' => $taxonomy,
        'field' => 'term_id',
        'terms' => [$term_id],
      ]];
    }

    $q = new WP_Query($query_args);

    ob_start();

    if ($q->have_posts()) {
      echo '<div class="scf-posts scf-layout-' . esc_attr($layout) . '">';
      while ($q->have_posts()) {
        $q->the_post();
        echo '<article class="scf-post">';
          if (has_post_thumbnail()) {
            echo '<a class="scf-thumb" href="' . esc_url(get_permalink()) . '">';
            the_post_thumbnail('medium_large');
            echo '</a>';
          }
          echo '<h3 class="scf-title"><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h3>';
          echo '<div class="scf-excerpt">' . esc_html(wp_strip_all_tags(get_the_excerpt())) . '</div>';
        echo '</article>';
      }
      echo '</div>';
    } else {
      echo '<p>No posts found.</p>';
    }

    wp_reset_postdata();
    return ob_get_clean();
  }

  public function ajax_filter_posts() {
    check_ajax_referer(self::NONCE_ACTION, 'nonce');

    $term_id = isset($_POST['term_id']) ? (int) $_POST['term_id'] : 0;
    $taxonomy = isset($_POST['taxonomy']) ? sanitize_key($_POST['taxonomy']) : 'category';
    $posts_per_page = isset($_POST['posts_per_page']) ? (int) $_POST['posts_per_page'] : 9;
    $layout = isset($_POST['layout']) ? sanitize_key($_POST['layout']) : 'grid';
    $paged = isset($_POST['paged']) ? max(1, (int) $_POST['paged']) : 1;

    if (!taxonomy_exists($taxonomy)) {
      wp_send_json_error(['message' => 'Invalid taxonomy.'], 400);
    }

    $html = $this->render_posts_html([
      'term_id' => $term_id,
      'taxonomy' => $taxonomy,
      'posts_per_page' => $posts_per_page,
      'layout' => $layout,
      'paged' => $paged,
    ]);

    wp_send_json_success(['html' => $html]);
  }

  public function register_vc_element() {
    if (!function_exists('vc_map')) return;

    vc_map([
      'name' => 'Category Filter (AJAX)',
      'base' => 'salient_category_filter',
      'category' => 'Content',
      'description' => 'Filter posts by category without leaving the page.',
      'js_view' => 'VcBackendTtaTabsView',
      'params' => [
        [
          'type' => 'textfield',
          'heading' => 'Posts per page',
          'param_name' => 'posts_per_page',
          'value' => '9',
        ],
        [
          'type' => 'dropdown',
          'heading' => 'Taxonomy',
          'param_name' => 'taxonomy',
          'value' => [
            'Category' => 'category',
            'Post Tag' => 'post_tag',
          ],
        ],
        [
          'type' => 'dropdown',
          'heading' => 'Layout',
          'param_name' => 'layout',
          'value' => [
            'Grid' => 'grid',
            'List' => 'list',
          ],
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
          'heading' => '"All" label',
          'param_name' => 'all_label',
          'value' => 'All',
          'dependency' => ['element' => 'show_all', 'value' => '1'],
        ],
        [
          'type' => 'textfield',
          'heading' => 'Include term IDs',
          'param_name' => 'include',
          'description' => 'Comma-separated term IDs (example: 12,14,22). Leave blank for all.',
        ],
        [
          'type' => 'textfield',
          'heading' => 'Exclude term IDs',
          'param_name' => 'exclude',
          'description' => 'Comma-separated term IDs to exclude.',
        ],
      ],
    ]);
  }
}

new SCF_Plugin();