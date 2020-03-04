<?php
/**
 * Admin Metabox Class
 *
 * @since 3.0.0
 * @version 3.36.1
 */

defined( 'ABSPATH' ) || exit;

// Include all classes for each of the metabox types.
foreach ( glob( LLMS_PLUGIN_DIR . '/includes/admin/post-types/meta-boxes/fields/*.php' ) as $filename ) {
	require_once $filename;
}

/**
 * Admin metabox abstract class.
 *
 * @since 3.0.0
 * @since 3.35.0 Sanitize and verify nonce when saving metabox data.
 * @since 3.36.0 Allow quotes to be saved without being encoded for some special fields that store a shortcode.
 * @since 3.36.1 Improve `save()` method.
 * @since [version] Simplify `save()` by moving logic to sanitize and update posted data to `save_field()`.
 *                Add field sanitize option "no_encode_quotes" which functions like previous "shortcode" but is more semantically accurate.
 */
abstract class LLMS_Admin_Metabox {

	/**
	 * Metabox ID
	 * Define this in extending class's $this->configure() method
	 *
	 * @var string
	 * @since 3.0.0
	 */
	public $id;

	/**
	 * Post Types this metabox should be added to
	 *
	 * Can be a string of a single post type or an indexed array of multiple post types
	 * Define this in extending class's $this->configure() method
	 *
	 * @var array
	 * @since 3.0.0
	 */
	public $screens = array();

	/**
	 * Title of the metabox
	 * This should be a translatable, use __()
	 * Define this in extending class's $this->configure() method
	 *
	 * @var string
	 * @since 3.0.0
	 */
	public $title;

	/**
	 * Capability to check in order to display the metabox to the user
	 *
	 * @var    string
	 * @since  3.13.0
	 */
	public $capability = 'edit_post';

	/**
	 * Optional context to register the metabox with
	 * Accepts anything that can be passed to WP core add_meta_box() function
	 * Options are: 'normal', 'side', 'advanced'
	 * Define this in extending class's $this->configure() method
	 *
	 * @var string
	 * @since 3.0.0
	 */
	public $context = 'normal';

	/**
	 * Optional priority for the metabox
	 * Accepts anything that can be passed to WP core add_meta_box() function
	 * Options are: 'default', 'high', 'low'
	 * Define this in extending class's $this->configure() method
	 *
	 * @var string
	 * @since 3.0.0
	 */
	public $priority = 'default';

	/**
	 * Instance of WP_Post for the current post
	 *
	 * @var obj
	 * @since  3.0.0
	 */
	public $post;

	/**
	 * Meta Key Prefix for all elements in the metabox
	 *
	 * @var string
	 * @since 3.0.0
	 */
	public $prefix = '_llms_';

	/**
	 * Array of error message strings to be displayed after an update attempt
	 *
	 * @var array
	 * @since 3.0.0
	 */
	private $errors = array();

	/**
	 * Option keyname where error options are stored.
	 *
	 * @var string
	 */
	protected $error_opt_key = ''
;
	/**
	 * HTML for the Metabox Content
	 * Content handled by $this->process_fields()
	 *
	 * @var string
	 * @since 3.0.0
	 */
	private $content = '';

	/**
	 * HTML for the Metabox Navigation
	 * Content handled by $this->process_fields()
	 *
	 * @var string
	 * @since 3.0.0
	 */
	private $navigation = '';

	/**
	 * The number of tabs registered to the metabox
	 *
	 * This will be calculated automatically
	 * Navigation will not display unless there's 2 or more tabs
	 *
	 * @var integer
	 * @since  3.0.0
	 */
	private $total_tabs = 0;

	/**
	 * Metabox Version Numbers
	 *
	 * @var  integer
	 */
	private $version = 1;

	/**
	 * Constructor
	 *
	 * Configure the metabox and automatically add required actions.
	 *
	 * @since 3.0.0
 	 * @since [version] Use `$this->error_opt_key()` in favor of hardcoded option name.
	 *
	 * @return void
	 */
	public function __construct() {

		// Allow child classes to configure variables.
		$this->configure();

		// Set the error option key.
		$this->error_opt_key = sprintf( 'lifterlms_metabox_errors%s', $this->id );

		// register the metabox
		add_action( 'add_meta_boxes', array( $this, 'register' ) );

		// register save actions for applicable screens (post types)
		foreach ( $this->get_screens() as $screen ) {
			add_action( 'save_post_' . $screen, array( $this, 'save_actions' ), 10, 1 );
		}

		// display errors
		add_action( 'admin_notices', array( $this, 'output_errors' ) );

		// save errors
		add_action( 'shutdown', array( $this, 'save_errors' ) );

	}

	/**
	 * Add an Error Message
	 *
	 * @since 3.0.0
	 * @since 3.8.0 Unknown.
	 *
	 * @param string $text Error message text.
	 * @return void
	 */
	public function add_error( $text ) {
		$this->errors[] = $text;
	}

	/**
	 * This function allows extending classes to configure required class properties
	 * $this->id, $this->title, and $this->screens should be configured in this function
	 *
	 * @return void
	 * @since  3.0.0
	 */
	abstract public function configure();

	/**
	 * Retrieve stored metabox errors.
	 *
	 * @since [version]
	 *
	 * @return string[]
	 */
	public function get_errors() {
		return get_option( $this->error_opt_key, array() );
	}

	/**
	 * This function is where extending classes can configure all the fields within the metabox
	 * The function must return an array which can be consumed by the "output" function
	 *
	 * @return array
	 */
	abstract public function get_fields();

	/**
	 * Normalizes $this->screens to ensure it's an array
	 *
	 * @since 3.0.0
	 * @since [version] Remove unnecessary `else` condition.
	 *
	 * @return array
	 */
	private function get_screens() {
		if ( is_string( $this->screens ) ) {
			return array( $this->screens );
		}
		return $this->screens;
	}

	/**
	 * Determine if any errors have been added to the metabox.
	 *
	 * @since Unknown
	 *
	 * @return boolean
	 */
	public function has_errors() {
		return count( $this->errors ) ? true : false;
	}

	/**
	 * Generate and output the HTML for the metabox
	 *
	 * @since Unknown
	 *
	 * @return void
	 */
	public function output() {

		// etup html for nav and content
		$this->process_fields();

		// output the html
		echo '<div class="llms-mb-container">';
		// only show tabbed navigation when there's more than 1 tab
		if ( $this->total_tabs > 1 ) {
			echo '<nav class="llms-nav-tab-wrapper"><ul class="tabs llms-nav-items">' . $this->navigation . '</ul></nav>';
		}
		do_action( 'llms_metabox_before_content', $this->id );
		echo $this->content;
		do_action( 'llms_metabox_after_content', $this->id );
		echo '</div>';
		wp_nonce_field( 'lifterlms_save_data', 'lifterlms_meta_nonce' );

	}

	/**
	 * Display the messages as a WP Admin Notice
	 *
	 * @return  void
	 * @since  3.0.0
	 */
	public function output_errors() {

		$errors = $this->get_errors();

		if ( empty( $errors ) ) {
			return;
		}

		foreach ( $errors as $error ) {
			echo '<div id="lifterlms_errors" class="error"><p>' . $error . '</p></div>';
		}

		delete_option( $this->error_opt_key );

	}

	/**
	 * Process fields to setup navigation and content with minimal PHP loops
	 * called by $this->output before actually outputting html
	 *
	 * @return   void
	 * @since    3.0.0
	 * @version  3.16.14
	 */
	private function process_fields() {

		/**
		 * Add a filter so extending classes don't have to
		 * so we don't have too many filters running
		 */
		$fields = apply_filters( 'llms_metabox_fields_' . str_replace( '-', '_', $this->id ), $this->get_fields() );

		$this->total_tabs = count( $fields );

		foreach ( $fields as $i => $tab ) {

			$i++;
			$current = 1 === $i ? ' llms-active' : '';

			$this->navigation .= '<li class="llms-nav-item tab-link ' . $current . '" data-tab="' . $this->id . '-tab-' . $i . '"><span class="llms-nav-link">' . $tab['title'] . '</span></li>';

			$this->content .= '<div id="' . $this->id . '-tab-' . $i . '" class="tab-content' . $current . '"><ul>';

			foreach ( $tab['fields'] as $field ) {

				$name = ucfirst(
					strtr(
						preg_replace_callback(
							'/(\w+)/',
							function( $m ) {
								return ucfirst( $m[1] );
							},
							$field['type']
						),
						'-',
						'_'
					)
				);

				$field_class_name = str_replace( '{TOKEN}', $name, 'LLMS_Metabox_{TOKEN}_Field' );
				$field_class      = new $field_class_name( $field );
				ob_start();
				$field_class->Output();
				$this->content .= ob_get_clean();
				unset( $field_class );
			}

			$this->content .= '</ul></div>';

		}

	}

	/**
	 * Register the Metabox using WP Functions
	 * This is called automatically by constructor
	 * Utilizes class properties for registration
	 *
	 * @return   void
	 * @since    3.0.0
	 * @version  3.13.0
	 */
	public function register() {

		global $post;
		$this->post = $post;

		if ( current_user_can( $this->capability, $this->post->ID ) ) {

			add_meta_box( $this->id, $this->title, array( $this, 'output' ), $this->get_screens(), $this->context, $this->priority );

		}

	}

	/**
	 * Save field data
	 * Loops through fields and saves the data to postmeta
	 * Called by $this->save_actions()
	 *
	 * This function is dumb. If the fields need to output error messages or do validation
	 * Override this method and create a custom save method to accommodate the validations or conditions
	 *
	 * @since 3.0.0
	 * @since 3.14.1 Unknown.
	 * @since 3.35.0 Added nonce verification before processing data; only access `$_POST` data via `llms_filter_input()`.
	 * @since 3.36.0 Allow quotes when sanitizing some special fields that store a shortcode.
	 * @since 3.36.1 Check metabox capability during saves.
	 *               Return an `int` depending on return condition.
	 *               Automatically add `FILTER_REQUIRE_ARRAY` flag when sanitizing a `multi` field.
	 * @since [version] Move field sanitization and updates to the `save_field()` method.
	 *
	 * @param int $post_id WP Post ID of the post being saved.
	 * @return int `-1` When no user or user is missing required capabilities or when there's no or invalid nonce.
	 *             `0` during inline saves or ajax requests or when no fields are found for the metabox.
	 *             `1` if fields were found. This doesn't mean there weren't errors during saving.
	 */
	protected function save( $post_id ) {

		if ( ! llms_verify_nonce( 'lifterlms_meta_nonce', 'lifterlms_save_data' ) || ! current_user_can( $this->capability, $post_id ) ) {
			return -1;
		}

		// Return early during quick saves and ajax requests.
		if ( ( isset( $_POST['action'] ) && 'inline-save' === $_POST['action'] ) || llms_is_ajax() ) {
			return 0;
		}

		// Get all defined fields.
		$fields = $this->get_fields();

		if ( ! is_array( $fields ) ) {
			return 0;
		}

		// Loop through the fields.
		foreach ( $fields as $group => $data ) {

			// Find the fields in each tab.
			if ( isset( $data['fields'] ) && is_array( $data['fields'] ) ) {

				// Loop through the fields.
				foreach ( $data['fields'] as $field ) {

					// Don't save things that don't have an ID.
					if ( isset( $field['id'] ) ) {
						$this->save_field( $post_id, $field );
					}
				}
			}

		}

		return 1;

	}

	/**
	 * Save a metabox field.
	 *
	 * @since [version]
	 *
	 * @param  int   $post_id WP_Post ID.
	 * @param  array $field   Metabox field array.
	 * @return boolean
	 */
	protected function save_field( $post_id, $field ) {

		$val = '';

		// Get the posted value & sanitize it.
		if ( isset( $_POST[ $field['id'] ] ) ) {

			$filters = array(
				FILTER_SANITIZE_STRING,
			);

			if ( isset( $field['sanitize'] ) && in_array( $field['sanitize'], array( 'shortcode', 'no_encode_quotes' ), true ) ) {
				$filters[] = FILTER_FLAG_NO_ENCODE_QUOTES;
			} elseif ( ! empty( $field['multi'] ) ) {
				$filters[] = FILTER_REQUIRE_ARRAY;
			}

			$val = call_user_func_array( 'llms_filter_input', array_merge( array( INPUT_POST, $field['id'] ), $filters ) );

		}

		return update_post_meta( $post_id, $field['id'], $val ) ? true : false;

	}

	/**
	 * Allows extending classes to perform additional save methods before the default save
	 *
	 * Called before `$this->save()` during `$this->save_actions()`.
	 *
	 * @since 3.0.0
	 *
	 * @param int $post_id WP Post ID of the post being saved.
	 * @return void
	 */
	protected function save_before( $post_id ) {}

	/**
	 * Allows extending classes to perform additional save methods after the default save
	 *
	 * Called after `$this->save()` during `$this->save_actions()`.
	 *
	 * @since 3.0.0
	 *
	 * @param int $post_id WP Post ID of the post being saved.
	 * @return void
	 */
	protected function save_after( $post_id ) {}

	/**
	 * Perform Save Actions
	 *
	 * Triggers actions for before and after save and calls the save method which actually saves metadata.
	 *
	 * This is called automatically on save_post_{$post_type} for all screens defined in `$this->screens`.
	 *
	 * @since 3.0.0
	 *
	 * @param int $post_id WP Post ID of the post being saved.
	 * @return void
	 */
	public function save_actions( $post_id ) {

		// Prevent save action from running multiple times on a single load.
		if ( isset( $this->_saved ) ) {
			return;
		}

		$this->post = get_post( $post_id );

		$this->_saved = true;
		do_action( 'llms_metabox_before_save_' . $this->id, $post_id, $this );
		$this->save_before( $post_id );
		$this->save( $post_id );
		$this->save_after( $post_id );
		do_action( 'llms_metabox_after_save_' . $this->id, $post_id, $this );
	}

	/**
	 * Save messages to the database
	 *
	 * @since 3.0.0
	 * @since [version] Use `$this->error_opt_key()` in favor of hardcoded option name.
	 *                Only save errors if errors have been added.
	 *
	 * @return void
	 */
	public function save_errors() {
		if ( $this->has_errors() ) {
			update_option( $this->error_opt_key, $this->errors );
		}
	}

}
