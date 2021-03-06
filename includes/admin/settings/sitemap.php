<?php
/**
 * Sitemap settings admin page
 *
 * @package wpmu-dev-seo
 */

/**
 * Sitemap settings admin page class
 */
class Smartcrawl_Sitemap_Settings extends Smartcrawl_Settings_Admin {

	/**
	 * Singleton instance
	 *
	 * @var Smartcrawl_Sitemap_Settings
	 */
	private static $_instance;

	/**
	 * Singleton instance getter
	 *
	 * @return Smartcrawl_Sitemap_Settings instance
	 */
	public static function get_instance() {
		if ( empty( self::$_instance ) ) {
			self::$_instance = new self;
		}
		return self::$_instance;
	}

	/**
	 * Validate submitted options
	 *
	 * @param array $input Raw input.
	 *
	 * @return array Validated input
	 */
	public function validate( $input ) {
		$result = array();

		if ( ! empty( $input['wds_sitemap-setup'] ) ) { $result['wds_sitemap-setup'] = true; }

		$strings = array(
			'verification-google',
			'verification-bing',
			'verification-pages',
		);
		foreach ( $strings as $str ) {
			if ( isset( $input[ $str ] ) ) { $result[ $str ] = sanitize_text_field( $input[ $str ] ); }
		}

		$booleans = array(
			'ping-google',
			'ping-bing',
			'sitemap-images',
			'sitemap-stylesheet',
			'sitemap-dashboard-widget',
			'sitemap-disable-automatic-regeneration',
			'sitemap-buddypress-groups',
			'sitemap-buddypress-profiles',
		);
		foreach ( $booleans as $bool ) {
			if ( ! empty( $input[ $bool ] ) ) { $result[ $bool ] = true; }
		}

		// Array Booleans.
		$input['exclude_post_types'] = ! empty( $input['exclude_post_types'] ) && is_array( $input['exclude_post_types'] ) ? $input['exclude_post_types'] : array();
		foreach ( array_keys( $this->_get_post_types_options() ) as $post_type ) {
			$result[ $post_type ] = in_array( $post_type, $input['exclude_post_types'] );
		}
		$input['exclude_taxonomies'] = ! empty( $input['exclude_taxonomies'] ) && is_array( $input['exclude_taxonomies'] ) ? $input['exclude_taxonomies'] : array();
		foreach ( array_keys( $this->_get_taxonomies_options() ) as $tax ) {
			$result[ $tax ] = in_array( $tax, $input['exclude_taxonomies'] );
		}

		// BuddyPress-specific.
		$bpo = $this->_get_buddyress_template_values();
		if ( ! empty( $bpo['exclude_groups'] ) && is_array( $bpo['exclude_groups'] ) ) {
			$input['exclude_bp_groups'] = ! empty( $input['exclude_bp_groups'] ) && is_array( $input['exclude_bp_groups'] ) ? $input['exclude_bp_groups'] : array();
			foreach ( $bpo['exclude_groups'] as $slug => $name ) {
				$key = "sitemap-buddypress-{$slug}";
				$result[ $key ] = in_array( $key, $input['exclude_bp_groups'] );
			}
		}

		if ( ! empty( $bpo['exclude_roles'] ) && is_array( $bpo['exclude_roles'] ) ) {
			$input['exclude_bp_roles'] = isset( $input['exclude_bp_roles'] ) && is_array( $input['exclude_bp_roles'] ) ? $input['exclude_bp_roles'] : array();
			foreach ( $bpo['exclude_roles'] as $slug => $name ) {
				$key = "sitemap-buddypress-roles-{$slug}";
				$result[ $key ] = in_array( $key, $input['exclude_bp_roles'] );
			}
		}

		// Meta tags.
		if ( ! empty( $input['verification-google-meta'] ) ) {
			$result['verification-google-meta'] = $input['verification-google-meta'];
			$result['verification-google'] = false;
		}
		if ( ! empty( $input['verification-bing-meta'] ) ) {
			$result['verification-bing-meta'] = $input['verification-bing-meta'];
			$result['verification-bing'] = false;
		}

		$custom_values_key = 'additional-metas';
		if ( ! empty( $input[ $custom_values_key ] ) && is_array( $input[ $custom_values_key ] ) ) {
			$result[ $custom_values_key ] = $input[ $custom_values_key ];
		}

		$result = $this->_validate_crawler_settings( $input, $result );

		if ( isset( $input['extra_sitemap_urls'] ) ) {
			$extra_urls = explode( "\n", $input['extra_sitemap_urls'] );
			$sanitized_extra_urls = array();
			foreach ( $extra_urls as $extra_url ) {
				if ( trim( $extra_url ) ) {
					$sanitized_extra_urls[] = esc_url( $extra_url );
				}
			}
			Smartcrawl_Xml_Sitemap::set_extra_urls( $sanitized_extra_urls );

			unset( $input['extra_sitemap_urls'] );
		}

		if ( isset( $input['sitemap_ignore_urls'] ) ) {
			$ignore_urls = explode( "\n", $input['sitemap_ignore_urls'] );
			$sanitized_ignore_urls = array();
			foreach ( $ignore_urls as $ignore_url ) {
				if ( trim( $ignore_url ) ) {
					$sanitized_ignore_urls[] = smartcrawl_sanitize_relative_url( $ignore_url );
				}
			}
			Smartcrawl_Xml_Sitemap::set_ignore_urls( $sanitized_ignore_urls );

			unset( $input['sitemap_ignore_urls'] );
		}

		if ( isset( $input['sitemap_ignore_post_ids'] ) ) {
			$ignore_post_ids = explode( ',', $input['sitemap_ignore_post_ids'] );
			$sanitized_ignore_post_ids = array();
			foreach ( $ignore_post_ids as $pid ) {
				if ( trim( $pid ) && (int) $pid ) {
					$sanitized_ignore_post_ids[] = (int) $pid;
				}
			}
			Smartcrawl_Xml_Sitemap::set_ignore_ids( $sanitized_ignore_post_ids );

			unset( $input['sitemap_ignore_post_ids'] );
		}

		return $result;
	}

	/**
	 * Crawler settings validation
	 *
	 * @param array $input Raw input.
	 * @param array $result Result this far.
	 *
	 * @return array
	 */
	private function _validate_crawler_settings( $input, $result ) {
		if ( empty( $input['crawler-cron-enable'] ) ) {
			$result['crawler-cron-enable'] = false;
			return $result;
		} else { $result['crawler-cron-enable'] = true; }

		$frequency = ! empty( $input['crawler-frequency'] )
			? Smartcrawl_Controller_Cron::get()->get_valid_frequency( $input['crawler-frequency'] )
			: Smartcrawl_Controller_Cron::get()->get_default_frequency();
		$result['crawler-frequency'] = $frequency;

		$dow = isset( $input['crawler-dow'] ) && is_numeric( $input['crawler-dow'] )
			? (int) $input['crawler-dow']
			: 0
		;
		$result['crawler-dow'] = in_array( $dow, range( 0,6 ) ) ? $dow : 0;

		$tod = isset( $input['crawler-tod'] ) && is_numeric( $input['crawler-tod'] )
			? (int) $input['crawler-tod']
			: 0
		;
		$result['crawler-tod'] = in_array( $tod, range( 0,23 ) ) ? $tod : 0;

		return $result;
	}

	/**
	 * Initialize the handler
	 */
	public function init() {
		$this->option_name = 'wds_sitemap_options';
		$this->name        = Smartcrawl_Settings::COMP_SITEMAP;
		$this->slug        = Smartcrawl_Settings::TAB_SITEMAP;
		$this->action_url  = admin_url( 'options.php' );
		$this->title       = __( 'Sitemap', 'wds' );
		$this->page_title  = __( 'SmartCrawl Wizard: Sitemap', 'wds' );

		add_action( 'wp_ajax_wds-toggle-sitemap-status', array( $this, 'json_toggle_sitemap_status' ) );

		parent::init();
	}

	/**
	 * Get a list of post type based options
	 *
	 * @return array
	 */
	protected function _get_post_types_options() {
		$options = array();

		foreach ( get_post_types(array(
			'public' => true,
			'show_ui' => true,
		)) as $post_type ) {
			if ( in_array( $post_type, array( 'revision', 'nav_menu_item', 'attachment' ) ) ) { continue; }
			$pt = get_post_type_object( $post_type );
			$options[ 'post_types-' . $post_type . '-not_in_sitemap' ] = $pt;
		}

		return $options;
	}

	/**
	 * Get a list of taxonomy based options
	 *
	 * @return array
	 */
	protected function _get_taxonomies_options() {
		$options = array();

		foreach ( get_taxonomies(array(
			'public' => true,
			'show_ui' => true,
		)) as $taxonomy ) {
			if ( in_array( $taxonomy, array( 'nav_menu', 'link_category', 'post_format' ) ) ) { continue; }
			$tax = get_taxonomy( $taxonomy );
			$options[ 'taxonomies-' . $taxonomy . '-not_in_sitemap' ] = $tax;
		}

		return $options;
	}

	/**
	 * Runs SEO Audit crawl
	 */
	public function run_crawl() {
		if ( current_user_can( 'manage_options' ) ) {
			$service = Smartcrawl_Service::get( Smartcrawl_Service::SERVICE_SEO );
			$service->start();
		}
		wp_safe_redirect( esc_url( remove_query_arg( 'run-crawl' ) ) );
		die;
	}

	/**
	 * Process run action
	 *
	 * @return bool
	 */
	public function process_run_action() {
		if ( ! empty( $_GET['run-crawl'] ) ) { // Simple presence switch, no value needed.
			return $this->run_crawl();
		}
		return false;
	}

	/**
	 * Add admin settings page
	 */
	public function options_page() {
		parent::options_page();

		$smartcrawl_options = Smartcrawl_Settings::get_options();
		$arguments = array(
			'post_types' => array(),
			'taxonomies' => array(),
			'engines' => array(
				'ping-google' => __( 'Google', 'wds' ),
				'ping-bing' => __( 'Bing', 'wds' ),
			),
			'checkbox_options' => array(
				'yes' => __( 'Yes', 'wds' ),
			),
			'verification_pages' => array(
				'' => __( 'All pages', 'wds' ),
				'home' => __( 'Home page', 'wds' ),
			),
		);

		foreach ( $this->_get_post_types_options() as $opt => $post_type ) {
			$arguments['post_types'][ $opt ] = $post_type;
		}
		foreach ( $this->_get_taxonomies_options() as $opt => $taxonomy ) {
			$arguments['taxonomies'][ $opt ] = $taxonomy;
		}

		$arguments['google_msg'] = ! empty( $smartcrawl_options['verification-google'] )
			? '<code>' . esc_html( '<meta name="google-site-verification" value="' ) . esc_attr( $smartcrawl_options['verification-google'] ) . esc_html( '" />' ) . '</code>'
			: '<small>' . esc_html( __( 'No META tag will be added', 'wds' ) ) . '</small>'
		;
		$arguments['bing_msg'] = ! empty( $smartcrawl_options['verification-bing'] )
			? '<code>' . esc_html( '<meta name="msvalidate.01" content="' ) . esc_attr( $smartcrawl_options['verification-bing'] ) . esc_html( '" />' ) . '</code>'
			: '<small>' . esc_html( __( 'No META tag will be added', 'wds' ) ) . '</small>'
		;

		$arguments['wds_buddypress'] = $this->_get_buddyress_template_values();

		$arguments['active_tab'] = $this->_get_last_active_tab( 'tab_sitemap' );

		$extra_urls = Smartcrawl_Xml_Sitemap::get_extra_urls();
		if ( is_array( $extra_urls ) ) {
			$arguments['extra_urls'] = ! empty( $extra_urls )
				? implode( "\n", $extra_urls )
				: ''
			;
		}

		$ignore_urls = Smartcrawl_Xml_Sitemap::get_ignore_urls();
		if ( is_array( $ignore_urls ) ) {
			$arguments['ignore_urls'] = ! empty( $ignore_urls )
				? implode( "\n", $ignore_urls )
				: ''
			;
		}

		$ignore_post_ids = Smartcrawl_Xml_Sitemap::get_ignore_ids();
		if ( is_array( $ignore_post_ids ) ) {
			$arguments['ignore_post_ids'] = ! empty( $ignore_post_ids )
				? implode( ',', $ignore_post_ids )
				: ''
			;
		}

		wp_enqueue_script( 'wds-admin-sitemaps' );
		$this->_render_page( 'sitemap/sitemap-settings', $arguments );
	}

	/**
	 * BuddyPress settings fields helper.
	 *
	 * @return array BuddyPress values for the template
	 */
	private function _get_buddyress_template_values() {
		$arguments = array();
		if ( ! defined( 'BP_VERSION' ) ) { return $arguments; }

		$arguments['checkbox_options'] = array(
			'yes' => __( 'Yes', 'wds' ),
		);

		if ( function_exists( 'groups_get_groups' ) ) { // We have BuddyPress groups, so let's get some settings.
			$groups = groups_get_groups( array( 'per_page' => SMARTCRAWL_BP_GROUPS_LIMIT ) );
			$arguments['groups'] = ! empty( $groups['groups'] ) ? $groups['groups'] : array();
			$arguments['exclude_groups'] = array();
			foreach ( $arguments['groups'] as $group ) {
				$arguments['exclude_groups'][ "exclude-buddypress-group-{$group->slug}" ] = $group->name;
			}
		}

		$wp_roles = new WP_Roles();
		$wp_roles = $wp_roles->get_names();
		$wp_roles = $wp_roles ? $wp_roles : array();
		$arguments['exclude_roles'] = array();
		foreach ( $wp_roles as $key => $label ) {
			$arguments['exclude_roles'][ "exclude-profile-role-{$key}" ] = $label;
		}

		return $arguments;
	}

	/**
	 * Default settings
	 */
	public function defaults() {
		if ( is_multisite() && SMARTCRAWL_SITEWIDE ) {
			$this->options = get_site_option( $this->option_name );
		} else {
			$this->options = get_option( $this->option_name );
		}

		$dir = wp_upload_dir();
		$path = trailingslashit( $dir['basedir'] );

		if ( empty( $this->options['wds_sitemap-setup'] ) ) {
			if ( ! isset( $this->options['sitemap-stylesheet'] ) ) {
				$this->options['sitemap-stylesheet'] = 1;
			}
		}

		if ( empty( $this->options['sitemappath'] ) ) {
			$this->options['sitemappath'] = $path . 'sitemap.xml';
		}

		if ( empty( $this->options['sitemapurl'] ) ) {
			$this->options['sitemapurl'] = get_bloginfo( 'url' ) . '/sitemap.xml';
		}

		if ( empty( $this->options['sitemap-images'] ) ) {
			$this->options['sitemap-images'] = 0;
		}

		if ( empty( $this->options['sitemap-stylesheet'] ) ) {
			$this->options['sitemap-stylesheet'] = 0;
		}

		if ( empty( $this->options['sitemap-dashboard-widget'] ) ) {
			$this->options['sitemap-dashboard-widget'] = 0;
		}

		if ( empty( $this->options['sitemap-disable-automatic-regeneration'] ) ) {
			$this->options['sitemap-disable-automatic-regeneration'] = 0;
		}

		if ( empty( $this->options['verification-google'] ) ) {
			$this->options['verification-google'] = '';
		}

		if ( empty( $this->options['verification-bing'] ) ) {
			$this->options['verification-bing'] = '';
		}

		if ( empty( $this->options['verification-pages'] ) ) {
			$this->options['verification-pages'] = '';
		}

		if ( empty( $this->options['sitemap-buddypress-groups'] ) ) {
			$this->options['sitemap-buddypress-groups'] = 0;
		}

		if ( empty( $this->options['sitemap-buddypress-profiles'] ) ) {
			$this->options['sitemap-buddypress-profiles'] = 0;
		}

		if ( empty( $this->options['verification-google-meta'] ) ) { $this->options['verification-google-meta'] = ''; }
		if ( empty( $this->options['verification-bing-meta'] ) ) { $this->options['verification-bing-meta'] = ''; }
		if ( empty( $this->options['additional-metas'] ) ) { $this->options['additiona-metas'] = array(); }

		if ( ! isset( $this->options['crawler-cron-enable'] ) ) { $this->options['crawler-cron-enable'] = false; }
		if ( ! isset( $this->options['crawler-frequency'] ) ) { $this->options['crawler-frequency'] = Smartcrawl_Controller_Cron::get()->get_default_frequency(); }
		if ( ! isset( $this->options['crawler-dow'] ) ) { $this->options['crawler-dow'] = rand( 0, 6 ); }
		if ( ! isset( $this->options['crawler-tod'] ) ) { $this->options['crawler-tod'] = rand( 0, 23 ); }

		if ( is_multisite() && SMARTCRAWL_SITEWIDE ) {
			update_site_option( $this->option_name, $this->options );
		} else {
			update_option( $this->option_name, $this->options );
		}
	}

	/**
	 * Handles sitemap active toggle request
	 */
	public function json_toggle_sitemap_status() {
		$data = stripslashes_deep( $_POST );
		$status = (bool) smartcrawl_get_array_value( $data, 'sitemap_active' );
		$return = array( 'success' => false );

		if ( null === $status ) {
			wp_send_json( $return );
			return;
		}

		$options = self::get_specific_options( 'wds_settings_options' );
		$options['sitemap'] = $status;
		self::update_specific_options( 'wds_settings_options', $options );

		$return['success'] = true;
		wp_send_json( $return );
	}
}
