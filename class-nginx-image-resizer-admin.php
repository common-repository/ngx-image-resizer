<?php
/**
 * Nginx Image Resizer Admin.
 *
 * @class    Ngx_Image_Resizer_Admin
 * @package  Ngx_Image_Resizer/Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ngx_Image_Resizer_Admin.
 */
class Ngx_Image_Resizer_Admin {
	/**
	 * The plugin settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings = get_option( 'nir_settings', array(
			'enable_external_thumbnail'  => 0,
			'disable_intermediate_sizes' => 1,
			'secure_link'                => '',
		) );

		if ( 1 == $this->settings['enable_external_thumbnail'] ) {
			add_filter( 'admin_post_thumbnail_html', array( __CLASS__, 'add_thumbnail_url_field' ), 30 );
			add_action( 'save_post', array( __CLASS__, 'save_thumbnail_url_field' ), 10, 2 );
		}

		add_action( 'admin_init', array( $this, 'settings_init' ) );
	}

	/**
	 * Init the settings.
	 */
	public function settings_init() {
		// Add the settings section on the media settings page
		add_settings_section(
			'nir_settings',
			__( 'Ngx Image Resizer', 'ngx-image-resizer' ),
			array( $this, 'settings_section' ),
			'media'
		);

		// registering the call to the settings validation handler
		register_setting( 'media', 'nir_settings', array( $this, 'settings_validate' ) );
	}

	/**
	 * Show the settings.
	 */
	public function settings_section() {
		$thumnails_support = current_theme_supports( 'post-thumbnails' );
		?>
		<table class="form-table">
			<tr>
				<td class="td-full">
					<p>
						<label for="nir_disable_intermediate_sizes">
							<input type="checkbox" id="nir_disable_intermediate_sizes" name="nir_settings[disable_intermediate_sizes]" value="1" <?php checked( $this->settings['disable_intermediate_sizes'] ); ?> />
							<?php esc_html_e( 'Disable generation of intermediate image sizes', 'ngx-image-resizer' ); ?>
						</label>
					</p>
					<p>
						<label for="nir_enable_external_thumbnail">
							<input type="checkbox" id="nir_enable_external_thumbnail" name="nir_settings[enable_external_thumbnail]" value="1" <?php checked( $this->settings['enable_external_thumbnail'] ); ?> <?php disabled( ! $thumnails_support ); ?> />
							<?php esc_html_e( 'Enable external thumbnail', 'ngx-image-resizer' ); ?>
						</label>
					</p>
					<?php
					if ( ! $thumnails_support ) {
						echo '<p class="description">' . esc_html__( 'The current theme does not support featured images.', 'ngx-image-resizer' ) . '</p>';
					}
					?>
					<p>
						<label for="nir_secure_link"><?php esc_html_e( 'Nginx secure link format', 'ngx-image-resizer' ); ?></label>
						<input type="text" id="nir_secure_link" name="nir_settings[secure_link]" value="<?php echo esc_attr( $this->settings['secure_link'] ); ?>" />
					</p>
					<p class="description"><?php esc_html_e( 'Leave it empty to disable secure link. Available tags: %uri%, %w%, %h%, %url%.', 'ngx-image-resizer' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Validate the settings.
	 */
	public function settings_validate( $settings ) {
		$settings['disable_intermediate_sizes'] = ! empty( $settings['disable_intermediate_sizes'] ) ? 1 : 0;
		$settings['enable_external_thumbnail']  = ! empty( $settings['enable_external_thumbnail'] ) ? 1 : 0;
		$settings['secure_link']                = ! empty( $settings['secure_link'] ) ? sanitize_text_field( $settings['secure_link'] ) : '';

		return $settings;
	}

	/**
	 * Add thumbnail URL field to featured image meta box.
	 *
	 * @param string $html Admin post thumbnail HTML markup.
	 */
	public static function add_thumbnail_url_field( $html ) {
		global $post;

		$url = '';
		if ( -1 === (int) get_post_thumbnail_id( $post->ID ) ) {
			$url = get_post_meta( $post->ID, '_nir_thumbnail_url', true ) ? : '';
		}

		$html .= '<input type="hidden" name="nir_thumbnail_url_nonce" value="' . wp_create_nonce( 'save_thumbnail_url' ) . '" />';
		$html .= '<p class="howto">' . esc_html__( 'Or enter URL for external image', 'ngx-image-resizer' ) . '</p>';
		$html .= '<input type="url" id="nir_thumbnail_url" name="nir_thumbnail_url" value="' . esc_url( $url ) . '" autocomplete="off" class="widefat" />';
		if ( $url ) {
			$html .= '<p><img src="' . esc_url( Ngx_Image_Resizer::get_resized_image_url( $url, 0, 0 ) ) . '" /></p>';
			$html .= '<p class="howto">' . esc_html__( 'Leave url blank to remove', 'ngx-image-resizer' ) . '</p>';
		}

		return $html;
	}

	/**
	 * Save the thumbnail URL meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_thumbnail_url_field( $post_id, $post ) {
		// $post_id and $post are required
		if ( empty( $post_id ) || empty( $post ) ) {
			return;
		}

		// Dont' save meta for revisions or autosaves
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || is_int( wp_is_post_revision( $post ) ) || is_int( wp_is_post_autosave( $post ) ) ) {
			return;
		}

		// Check the post being saved == the $post_id to prevent triggering this call for other save_post events
		if ( empty( $_POST['post_ID'] ) || $_POST['post_ID'] != $post_id ) {
			return;
		}

		// Check the nonce
		if ( empty( $_POST['nir_thumbnail_url_nonce'] ) || ! wp_verify_nonce( $_POST['nir_thumbnail_url_nonce'], 'save_thumbnail_url' ) ) {
			return;
		}

		// Check user has permission to edit
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$url = ! empty( $_POST['nir_thumbnail_url'] ) ? trim( $_POST['nir_thumbnail_url'] ) : '';

		Ngx_Image_Resizer::set_post_thumbnail_url( $post, $url );
	}
}

new Ngx_Image_Resizer_Admin();
