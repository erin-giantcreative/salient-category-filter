<?php
/**
 * Plugin Name: Salient Category Filter (WPBakery Element)
 * Description: Adds a category filter + AJAX post loop (shortcode + WPBakery element).
 * Version: 1.0.2
 * Text Domain: salient-category-filter
 *
 * @package Salient_Category_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCF_VERSION', '1.0.2' );

/**
 * Main plugin class.
 *
 * Registers the shortcode, REST endpoint, legacy AJAX handler, WPBakery element,
 * asset enqueueing, and transient cache management for the Salient category filter.
 *
 * @package Salient_Category_Filter
 */
class SCF_Salient_Blog_Filter {

	const QV           = 'category';
	const NONCE_ACTION = 'scf_blog_filter_nonce';

	/**
	 * Transient TTL for cached HTML fragments (seconds).
	 */
	const HTML_CACHE_TTL = 300;

	/**
	 * Transient TTL for cached term lists (seconds).
	 */
	const TERMS_CACHE_TTL = 3600;

	/**
	 * Register all WordPress hooks.
	 */
	public function __construct() {
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'pre_get_posts', array( $this, 'maybe_filter_main_query' ), 20 );

		add_shortcode( 'scf_blog_filter', array( $this, 'shortcode_filter_ui' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		// Primary endpoint: REST API (lighter bootstrap than admin-ajax.php, cacheable by CDN).
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Legacy AJAX endpoint kept for backwards compatibility with any cached JS.
		add_action( 'wp_ajax_scf_get_blog_html', array( $this, 'ajax_get_blog_html' ) );
		add_action( 'wp_ajax_nopriv_scf_get_blog_html', array( $this, 'ajax_get_blog_html' ) );

		// Add defer attribute to the filter script.
		add_filter( 'script_loader_tag', array( $this, 'add_defer_to_script' ), 10, 2 );

		// Optional: WPBakery element.
		add_action( 'vc_after_init', array( $this, 'register_vc_element' ) );

		// Bust caches when content changes.
		add_action( 'save_post_post', array( $this, 'bust_all_cache' ) );
		add_action( 'deleted_post', array( $this, 'bust_all_cache' ) );
		add_action( 'created_category', array( $this, 'bust_all_cache' ) );
		add_action( 'edited_category', array( $this, 'bust_all_cache' ) );
		add_action( 'delete_category', array( $this, 'bust_all_cache' ) );
	}

	// -------------------------------------------------------------------------
	// Query / routing.
	// -------------------------------------------------------------------------

	/**
	 * Register the category query var so WordPress passes it through.
	 *
	 * @param array $vars Existing public query vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QV;
		return $vars;
	}

	/**
	 * Apply the category filter to the main query when the correct page is loaded.
	 *
	 * The scf_page_id parameter is an internal plugin parameter appended by the
	 * loopback request; nonce verification is intentionally omitted here because
	 * this fires on pre_get_posts (before any form submission).
	 *
	 * @param WP_Query $q The current query object.
	 */
	public function maybe_filter_main_query( $q ) {
		if ( is_admin() || ! $q->is_main_query() ) {
			return;
		}

		$cat = get_query_var( self::QV );
		$cat = $cat ? (int) $cat : 0;
		if ( $cat <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$target_page_id = isset( $_GET['scf_page_id'] ) ? (int) $_GET['scf_page_id'] : 0;

		$is_target = false;
		if ( is_home() ) {
			$is_target = true;
		} elseif ( $target_page_id > 0 && is_page( $target_page_id ) ) {
			$is_target = true;
		}

		if ( ! $is_target ) {
			return;
		}

		$q->set( 'cat', $cat );
	}

	// -------------------------------------------------------------------------
	// Asset registration.
	// -------------------------------------------------------------------------

	/**
	 * Register (but do not enqueue) the plugin script and style.
	 *
	 * Assets are enqueued on demand inside shortcode_filter_ui() so they only
	 * load on pages where the shortcode is present.
	 */
	public function register_assets() {
		wp_register_script(
			'scf-salient-blog-filter',
			plugin_dir_url( __FILE__ ) . 'assets/scf-filter.js',
			array( 'jquery' ),
			SCF_VERSION,
			true // Load in footer.
		);

		wp_localize_script(
			'scf-salient-blog-filter',
			'SCF_BLOG_FILTER',
			array(
				'restUrl' => rest_url( 'scf/v1/blog-html' ),
				// Legacy fields so cached JS still works via admin-ajax fallback.
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			)
		);

		wp_register_style(
			'scf-salient-blog-filter',
			plugin_dir_url( __FILE__ ) . 'assets/scf-style.css',
			array(),
			SCF_VERSION
		);
	}

	/**
	 * Add defer attribute to the filter script so it is non-blocking.
	 *
	 * Footer loading already prevents render-blocking; defer is belt-and-suspenders.
	 *
	 * @param string $tag    Script HTML tag.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public function add_defer_to_script( $tag, $handle ) {
		if ( 'scf-salient-blog-filter' === $handle ) {
			return str_replace( ' src=', ' defer src=', $tag );
		}
		return $tag;
	}

	// -------------------------------------------------------------------------
	// REST API endpoint.
	// -------------------------------------------------------------------------

	/**
	 * Register the REST API route for blog HTML retrieval.
	 */
	public function register_rest_routes() {
		register_rest_route(
			'scf/v1',
			'/blog-html',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_get_blog_html' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'base_url'         => array(
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
					),
					'replace_selector' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'cat_id'           => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'page_id'          => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * REST API callback: return the filtered blog HTML fragment.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_blog_html( WP_REST_Request $request ) {
		$base_url         = $request->get_param( 'base_url' );
		$replace_selector = $request->get_param( 'replace_selector' );
		$cat_id           = (int) $request->get_param( 'cat_id' );
		$page_id          = (int) $request->get_param( 'page_id' );

		$result = $this->fetch_blog_html_data( $base_url, $replace_selector, $cat_id, $page_id );

		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $status );
		}

		return rest_ensure_response( $result );
	}

	// -------------------------------------------------------------------------
	// Legacy admin-ajax endpoint.
	// -------------------------------------------------------------------------

	/**
	 * Legacy admin-ajax handler kept for backwards compatibility.
	 *
	 * Verifies the SCF nonce then delegates to fetch_blog_html_data().
	 */
	public function ajax_get_blog_html() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$base_url         = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '';
		$replace_selector = isset( $_POST['replace_selector'] ) ? sanitize_text_field( wp_unslash( $_POST['replace_selector'] ) ) : '';
		$cat_id           = isset( $_POST['cat_id'] ) ? (int) $_POST['cat_id'] : 0;
		$page_id          = isset( $_POST['page_id'] ) ? (int) $_POST['page_id'] : 0;

		$result = $this->fetch_blog_html_data( $base_url, $replace_selector, $cat_id, $page_id );

		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
			wp_send_json_error( array( 'message' => $result->get_error_message() ), $status );
		}

		wp_send_json_success( $result );
	}

	// -------------------------------------------------------------------------
	// Shared blog-HTML fetch logic.
	// -------------------------------------------------------------------------

	/**
	 * Validates params, checks the transient cache, performs the loopback HTTP
	 * request, extracts the requested HTML fragment, and stores the result.
	 *
	 * @param string $base_url         The blog page URL.
	 * @param string $replace_selector CSS selector whose inner HTML to extract.
	 * @param int    $cat_id           Category term ID (0 = all).
	 * @param int    $page_id          Page ID for same-page blog (0 = posts page).
	 * @return array{html: string, cached: bool}|WP_Error
	 */
	private function fetch_blog_html_data( $base_url, $replace_selector, $cat_id, $page_id ) {
		if ( ! $base_url || ! $replace_selector ) {
			return new WP_Error( 'missing_params', 'Missing base_url or replace_selector.', array( 'status' => 400 ) );
		}

		// SSRF protection: only allow same-origin requests.
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$req_host  = wp_parse_url( $base_url, PHP_URL_HOST );
		if ( ! $req_host || $req_host !== $home_host ) {
			return new WP_Error( 'invalid_url', 'base_url must be same origin.', array( 'status' => 400 ) );
		}

		// Cache key incorporates bust version so stale entries are ignored on content changes.
		$cache_key = 'scf_html_v' . $this->cache_version()
		. '_' . absint( $cat_id )
		. '_p' . absint( $page_id )
		. '_' . md5( $base_url . $replace_selector );

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return array(
				'html'   => $cached,
				'cached' => true,
			);
		}

		// Render the blog template directly in PHP — no HTTP loopback needed.
		$full_html = $this->render_blog_html_internal( $cat_id, $page_id );

		if ( ! $full_html ) {
			return new WP_Error( 'render_failed', 'Could not render blog template.', array( 'status' => 500 ) );
		}

		$extracted = $this->extract_selector_html( $full_html, $replace_selector );
		if ( ! $extracted ) {
			return new WP_Error(
				'selector_not_found',
				'Could not find replace_selector in response HTML.',
				array(
					'status'   => 422,
					'selector' => $replace_selector,
				)
			);
		}

		set_transient( $cache_key, $extracted, self::HTML_CACHE_TTL );

		return array(
			'html'   => $extracted,
			'cached' => false,
		);
	}

	// -------------------------------------------------------------------------
	// HTML extraction.
	// -------------------------------------------------------------------------

	/**
	 * Extract inner HTML for a CSS class or ID selector.
	 *
	 * Intentionally limited to simple single-token selectors (.class or #id)
	 * for safety and performance.
	 *
	 * @param string $html     Full HTML document string.
	 * @param string $selector CSS selector (e.g. ".blog-wrap" or "#main").
	 * @return string Extracted inner HTML, or empty string on failure.
	 */
	private function extract_selector_html( $html, $selector ) {
		$selector = trim( $selector );

		if ( ! preg_match( '/^([.#])([A-Za-z0-9_-]+)$/', $selector, $m ) ) {
			return '';
		}

		$type = $m[1];
		$name = $m[2];

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );

		$xpath = new DOMXPath( $dom );

		if ( '.' === $type ) {
			$query = "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$name} ')]";
		} else {
			$query = "//*[@id='{$name}']";
		}

		$nodes = $xpath->query( $query );
		if ( ! $nodes || 0 === $nodes->length ) {
			return '';
		}

		$node = $nodes->item( 0 );
		$out  = '';
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		foreach ( $node->childNodes as $child ) {
			$out .= $dom->saveHTML( $child );
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// Cache helpers.
	// -------------------------------------------------------------------------

	/**
	 * Render the blog page HTML directly using WordPress template loading,
	 * bypassing any HTTP loopback request entirely.
	 *
	 * Overrides the global WP_Query for the duration of the include so that
	 * the theme template sees the correct posts, then restores all globals.
	 *
	 * @param int $cat_id  Category ID (0 = all posts).
	 * @param int $page_id Page ID for same-page blog embed (0 = posts page).
	 * @return string Full HTML output of the rendered template.
	 */
	private function render_blog_html_internal( $cat_id, $page_id ) {
		global $wp_query, $wp_the_query, $post;

		$query_args = array(
			'post_type'      => 'post',
			'posts_per_page' => (int) get_option( 'posts_per_page' ),
			'paged'          => 1,
		);
		if ( $cat_id > 0 ) {
			$query_args['cat'] = $cat_id;
		}

		// Save globals.
		$orig_query     = $wp_query;
		$orig_the_query = $wp_the_query;
		$orig_post      = $post;

		// Override with the filtered query.
		$new_query          = new WP_Query( $query_args );
		$new_query->is_home = ( 0 === $cat_id );
		$wp_query           = $new_query;  // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_the_query       = $new_query;  // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// Locate the appropriate template file.
		if ( $page_id > 0 ) {
			$templates = array( 'page-' . $page_id . '.php', 'page.php' );
		} else {
			$templates = array( 'home.php', 'index.php' );
		}
		$template_file = locate_template( $templates );

		// Capture the full page output; we'll extract the selector we need from it.
		ob_start();
		if ( $template_file ) {
			( static function ( $f ) { include $f; } )( $template_file );
		}
		$html = ob_get_clean();

		// Restore globals.
		$wp_query     = $orig_query;     // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_the_query = $orig_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post         = $orig_post;      // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_reset_postdata();

		return (string) $html;
	}

	/**
	 * Return the current cache-bust version integer stored as a WP option.
	 *
	 * @return int
	 */
	private function cache_version() {
		return (int) get_option( 'scf_cache_bust', 0 );
	}

	/**
	 * Retrieve category terms from transient cache; prime the cache on miss.
	 *
	 * @param string $taxonomy    Taxonomy slug.
	 * @param string $include_csv Comma-separated term IDs to include.
	 * @param string $exclude_csv Comma-separated term IDs to exclude.
	 * @return WP_Term[]
	 */
	private function get_terms_cached( $taxonomy, $include_csv, $exclude_csv ) {
		$key    = 'scf_terms_v' . $this->cache_version() . '_' . md5( $taxonomy . '|' . $include_csv . '|' . $exclude_csv );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$terms_args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);

		if ( ! empty( $include_csv ) ) {
			$terms_args['include'] = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $include_csv ) ) ) );
		}
		if ( ! empty( $exclude_csv ) ) {
			$terms_args['exclude'] = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $exclude_csv ) ) ) );
		}

		$terms = get_terms( $terms_args );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			set_transient( $key, array(), 300 );
			return array();
		}

		// Exclude specific categories by name.
		$excluded_names = array( 'Landing Page' );
		$terms          = array_values( array_filter( $terms, function( $t ) use ( $excluded_names ) {
			return ! in_array( $t->name, $excluded_names, true );
		} ) );

		set_transient( $key, $terms, self::TERMS_CACHE_TTL );
		return $terms;
	}

	/**
	 * Invalidate all plugin transients by bumping the cache-bust version.
	 */
	public function bust_all_cache() {
		update_option( 'scf_cache_bust', time(), false );
	}

	// -------------------------------------------------------------------------
	// Shortcode.
	// -------------------------------------------------------------------------

	/**
	 * Determine the base URL for the blog page.
	 *
	 * @param int $page_id Explicit page ID override (0 = auto-detect).
	 * @return string
	 */
	private function get_blog_base_url( $page_id ) {
		if ( is_home() ) {
			$posts_page_id = (int) get_option( 'page_for_posts' );
			return $posts_page_id ? get_permalink( $posts_page_id ) : home_url( '/' );
		}
		if ( is_page() ) {
			return get_permalink( get_queried_object_id() );
		}
		if ( ! empty( $page_id ) ) {
			return get_permalink( (int) $page_id );
		}
		return home_url( '/' );
	}

	/**
	 * Shortcode callback: render the filter button UI.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_filter_ui( $atts ) {
		wp_enqueue_script( 'scf-salient-blog-filter' );
		wp_enqueue_style( 'scf-salient-blog-filter' );

		$atts = shortcode_atts(
			array(
				'taxonomy'         => 'category',
				'show_all'         => '1',
				'all_label'        => 'All',
				'include'          => '',
				'exclude'          => '',
				'replace_selector' => '.blog-wrap',
				'page_id'          => '',
			),
			$atts,
			'scf_blog_filter'
		);

		$taxonomy = sanitize_key( $atts['taxonomy'] );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}

		$page_id          = (int) $atts['page_id'];
		$replace_selector = (string) $atts['replace_selector'];

		$terms = $this->get_terms_cached( $taxonomy, $atts['include'], $atts['exclude'] );
		if ( empty( $terms ) ) {
			return '';
		}

		$base_url = $this->get_blog_base_url( $page_id );

		ob_start(); ?>
		<div class="scf-blog-filter-ui"
			data-base-url="<?php echo esc_url( $base_url ); ?>"
			data-replace-selector="<?php echo esc_attr( $replace_selector ); ?>"
			data-page-id="<?php echo esc_attr( $page_id ); ?>">

		<div class="scf-blog-filter-ui__buttons"
			role="group"
			aria-label="<?php esc_attr_e( 'Filter by category', 'salient-category-filter' ); ?>">
			<span aria-hidden="true"><?php esc_html_e( 'FILTER BY:', 'salient-category-filter' ); ?></span>
			<?php if ( 1 === (int) $atts['show_all'] ) : ?>
			<button class="scf-btn is-active" type="button" data-cat="0" aria-pressed="true"><?php echo esc_html( $atts['all_label'] ); ?></button>
			<?php endif; ?>

			<?php foreach ( $terms as $t ) : ?>
			<button class="scf-btn" type="button" data-cat="<?php echo (int) $t->term_id; ?>" aria-pressed="false">
				<?php echo esc_html( $t->name ); ?>
			</button>
			<?php endforeach; ?>
		</div>

		<div class="scf-live-status screen-reader-text" aria-live="polite" aria-atomic="true"></div>

		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// WPBakery element.
	// -------------------------------------------------------------------------

	/**
	 * Register the WPBakery Visual Composer element mapping.
	 */
	public function register_vc_element() {
		if ( ! defined( 'WPB_VC_VERSION' ) || ! function_exists( 'vc_map' ) ) {
			return;
		}

		vc_map(
			array(
				'name'        => 'Blog Category Filter (Salient)',
				'base'        => 'scf_blog_filter',
				'category'    => 'Content',
				'description' => 'Filters Salient blog output on the same page.',
				'params'      => array(
					array(
						'type'        => 'textfield',
						'heading'     => 'Replace selector (required)',
						'param_name'  => 'replace_selector',
						'value'       => '.blog-wrap',
						'description' => 'Use a simple .class or #id selector that wraps the posts + pagination.',
					),
					array(
						'type'       => 'textfield',
						'heading'    => 'Blog Page ID (only if NOT using Posts page)',
						'param_name' => 'page_id',
						'value'      => '',
					),
					array(
						'type'       => 'textfield',
						'heading'    => '"All" label',
						'param_name' => 'all_label',
						'value'      => 'All',
					),
					array(
						'type'       => 'checkbox',
						'heading'    => 'Show "All" button',
						'param_name' => 'show_all',
						'value'      => array( 'Yes' => '1' ),
						'std'        => '1',
					),
					array(
						'type'       => 'textfield',
						'heading'    => 'Include term IDs',
						'param_name' => 'include',
					),
					array(
						'type'       => 'textfield',
						'heading'    => 'Exclude term IDs',
						'param_name' => 'exclude',
					),
				),
			)
		);
	}
}

new SCF_Salient_Blog_Filter();

if ( class_exists( 'WPBakeryShortCode' ) ) {
	/**
	 * WPBakery shortcode class for scf_blog_filter.
	 *
	 * The class name follows WPBakery's required naming convention
	 * (WPBakeryShortCode_{shortcode_base}) and cannot be changed.
	 *
	 * @package Salient_Category_Filter
	 */
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
	class WPBakeryShortCode_scf_blog_filter extends WPBakeryShortCode {} // phpcs:ignore WordPress.NamingConventions.ValidClassName.NotCamelCaps
}

// ---------------------------------------------------------------------------
// Lifecycle hooks.
// ---------------------------------------------------------------------------

/**
 * Plugin activation callback.
 */
function scf_activate() {}

/**
 * Plugin deactivation callback.
 */
function scf_deactivate() {}

register_activation_hook( __FILE__, 'scf_activate' );
register_deactivation_hook( __FILE__, 'scf_deactivate' );

if ( ! function_exists( 'scf_uninstall' ) ) {
	/**
	 * Plugin uninstall callback: remove persistent options.
	 */
	function scf_uninstall() {
		delete_option( 'scf_cache_bust' );
	}
}
register_uninstall_hook( __FILE__, 'scf_uninstall' );
