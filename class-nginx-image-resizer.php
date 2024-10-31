<?php
/**
 * Plugin Name: Ngx Image Resizer
 * Plugin URI: https://github.com/toannth/ngx-image-resizer
 * Description: Resizes WordPress images on the fly using Nginx Image-Filter.
 * Version: 1.0.0
 * Author: toannth
 * Author URI: https://github.com/toannth
 * Requires at least: 4.4
 * Tested up to: 4.9
 *
 * Text Domain: ngx-image-resizer
 * Domain Path: /languages/
 *
 * @package Ngx_Image_Resizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MP_Image_Resizer class.
 */
class Ngx_Image_Resizer {
	/**
	 * @var Ngx_Image_Resizer The single instance of the class
	 */
	protected static $_instance = null;

	// Allowed extensions must match
	protected static $extensions = array( 'gif', 'jpg', 'jpeg', 'png' );

	// Don't access this directly. Instead, use self::image_sizes() so it's actually populated with something.
	protected static $image_sizes = null;

	/**
	 * Singleton MP_Image_Resizer Instance
	 *
	 * @static
	 * @return object
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );

		if ( is_admin() ) {
			include_once( dirname( __FILE__ ) . '/class-nginx-image-resizer-admin.php' );
		}
	}

	/**
	 * Register actions and filters
	 */
	public function init() {
		// Set up localisation
		$this->load_plugin_textdomain();

		$settings = get_option( 'nir_settings' );

		// Disable generation of intermediate image sizes by default.
		if ( ! isset( $settings['disable_intermediate_sizes'] ) || 1 == $settings['disable_intermediate_sizes'] ) {
			add_filter( 'intermediate_image_sizes_advanced', array( $this, 'disable_intermediate_image_resize' ) );
		}

		add_filter( 'image_downsize', array( $this, 'filter_image_downsize' ), 10, 3 );

		// Images in post content and galleries
		add_filter( 'the_content', array( $this, 'filter_the_content' ), 999 );
		add_filter( 'get_post_galleries', array( $this, 'filter_the_galleries' ), 999 );

		// Responsive image srcset substitution
		add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_srcset_array' ), 10, 5 );
		add_filter( 'wp_calculate_image_sizes', array( $this, 'filter_sizes' ), 1, 2 ); // Early so themes can still easily filter.
	}

	/**
	 * Load Localisation files.
	 *
	 * Note: the first-loaded translation file overrides any following ones if the same translation is present.
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'ngx-image-resizer', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Disable image sizes automatically generated when uploading an image.
	 *
	 * @param array $sizes An associative array of image sizes.
	 */
	public function disable_intermediate_image_resize( $sizes ) {
		return array();
	}

	/**
	 * Filter image downsize, passing image through dynamic resizing server.
	 *
	 * @param bool         $image    The image downsize. Default false.
	 * @param int          $id       Attachment ID for image.
	 * @param array|string $size     Size of image. Image size or array of width and height values (in that order).
	 *                               Default 'medium'.
	 */
	public function filter_image_downsize( $image, $id, $size ) {
		// Check if external thumbnail
		if ( -1 === (int) $id ) {
			if ( get_the_ID() ) {
				$url = get_post_meta( get_the_ID(), '_nir_thumbnail_url', true );
				$image_meta = get_post_meta( get_the_ID(), '_nir_thumbnail_meta', true );
			}
		} else {
			$url = wp_get_attachment_url( $id );
			$image_meta = wp_get_attachment_metadata( $id );
		}

		// Bail if no thumbnail url.
		if ( empty( $url ) ) {
			return $image;
		}

		$orig_size = array();
		if ( isset( $image_meta['width'], $image_meta['height'] ) ) {
			$orig_size = array( $image_meta['width'], $image_meta['height'] );
		}

		return self::get_resized_image_src( $url, $size, $orig_size );
	}

	/**
	 * Return resized image source.
	 *
	 * @param string       $url        The original image url.
	 * @param array|string $size       Size of image. Image size or array of width and height values (in that order).
	 *                                 Default 'medium'.
	 * @param array        $orig_size  The original image size or array of width and height values (in that order).
	 *                                 Default empty.
	 * @return false|array Returns an array (url, width, height, is_intermediate), or false
	 */
	public static function get_resized_image_src( $url, $size = 'medium', $orig_size = '' ) {
		if ( ! $url ) {
			return false;
		}

		$intermediate = true; // For the fourth array item returned by the image_downsize filter.
		$image_sizes  = self::image_sizes();

		// If an image is requested with a size known to WordPress, use that size's settings.
		if ( is_string( $size ) && array_key_exists( $size, $image_sizes ) ) {
			$width  = $image_sizes[ $size ]['width'];
			$height = $image_sizes[ $size ]['height'];
			$crop   = $image_sizes[ $size ]['crop'];

			// 'full' is a special case: We need consistent data regardless of the requested size.
			if ( 'full' === $size ) {
				$intermediate = false;
				if ( isset( $orig_size[0], $orig_size[1] ) ) {
					list( $width, $height ) = $orig_size;
				}
			} elseif ( isset( $orig_size[0], $orig_size[1] ) ) {
				$image_resized = image_resize_dimensions( $orig_size[0], $orig_size[1], $width, $height, $crop );
				if ( $image_resized ) { // This could be false when the requested image size is larger than the full-size image.
					$width  = $image_resized[4];
					$height = $image_resized[5];
				}
			}

			list( $width, $height ) = image_constrain_size_for_editor( $width, $height, $size, 'display' );
			return array(
				$intermediate ? self::get_resized_image_url( $url, $width, $height, $crop ) : self::get_resized_image_url( $url, 0, 0 ),
				$width,
				$height,
				$intermediate,
			);
		} elseif ( is_array( $size ) ) {
			// Pull width and height values from the provided array, if possible.
			$width  = isset( $size[0] ) ? (int) $size[0] : false;
			$height = isset( $size[1] ) ? (int) $size[1] : false;

			if ( ! $width && ! $height ) {
				$intermediate = false;
			}

			if ( isset( $orig_size[0], $orig_size[1] ) ) {
				$image_resized = image_resize_dimensions( $orig_size[0], $orig_size[1], $width, $height );

				if ( $image_resized ) { // This could be false when the requested image size is larger than the full-size image.
					$width  = $image_resized[4];
					$height = $image_resized[5];
				} else {
					list( $width, $height ) = $orig_size;
				}
			}

			list( $width, $height ) = image_constrain_size_for_editor( $width, $height, $size );
			return array(
				$intermediate ? self::get_resized_image_url( $url, $width, $height, false ) : self::get_resized_image_url( $url, 0, 0 ),
				$width,
				$height,
				$intermediate,
			);
		} // End if().

		return false;
	}

	/**
	 * Identify images in post content, pass through dynamic resizing server.
	 *
	 * @param string $content The post content.
	 * @return string The filtered post content.
	 */
	public function filter_the_content( $content ) {
		global $content_width;

		$images = self::parse_images_from_html( $content );

		if ( empty( $images ) ) {
			return $content;
		}

		$image_sizes = self::image_sizes();
		$upload_dir = wp_get_upload_dir();

		foreach ( $images[0] as $index => $tag ) {
			$crop = false;

			// Flag if we need to munge a fullsize URL
			$fullsize_url = false;

			// Start with a clean attachment ID each time
			$attachment_id = false;

			// Identify image source.
			$src = $src_orig = $images['img_url'][ $index ];

			// Check if image URL should be used with reszing server
			if ( ! self::validate_image_url( $src ) ) {
				continue;
			}

			// Find the width and height attributes.
			$width = $height = false;

			// First, check the image tag.
			if ( preg_match( '#width=["|\']?([\d%]+)["|\']?#i', $images['img_tag'][ $index ], $width_string ) ) {
				$width = $width_string[1];
			}

			if ( preg_match( '#height=["|\']?([\d%]+)["|\']?#i', $images['img_tag'][ $index ], $height_string ) ) {
				$height = $height_string[1];
			}

			// Can't pass both a relative width and height, so unset the height in favor of not breaking the horizontal layout.
			if ( false !== strpos( $width, '%' ) && false !== strpos( $height, '%' ) ) {
				$width = $height = false;
			}

			// Detect WP registered image size from HTML class.
			if ( preg_match( '#class=["|\']?[^"\']*size-([^"\'\s]+)[^"\']*["|\']?#i', $images['img_tag'][ $index ], $size ) ) {
				$size = array_pop( $size );

				if ( false === $width && false === $height && 'full' !== $size && array_key_exists( $size, $image_sizes ) ) {
					$width = (int) $image_sizes[ $size ]['width'];
					$height = (int) $image_sizes[ $size ]['height'];
					$crop = $image_sizes[ $size ]['crop'];
				}
			} else {
				unset( $size );
			}

			// WP Attachment ID, if uploaded to this site
			if ( preg_match( '#class=["|\']?[^"\']*wp-image-([\d]+)[^"\']*["|\']?#i', $images['img_tag'][ $index ], $attachment_id ) && self::image_is_local( $src ) ) {
				$attachment_id = intval( array_pop( $attachment_id ) );

				$attachment = get_post( $attachment_id );

				// Basic check on returned post object
				if ( is_object( $attachment ) && ! is_wp_error( $attachment ) && 'attachment' === $attachment->post_type ) {
					$src_per_wp = wp_get_attachment_image_src( $attachment_id, 'full' );
					$fullsize_url = true;

					$src = $src_per_wp[0];

					// Prevent image distortion if a detected dimension exceeds the image's natural dimensions
					if ( ( false !== $width && $width > $src_per_wp[1] ) || ( false !== $height && $height > $src_per_wp[2] ) ) {
						$width = false === $width ? false : min( $width, $src_per_wp[1] );
						$height = false === $height ? false : min( $height, $src_per_wp[2] );
					}

					// If no width and height are found, max out at source image's natural dimensions
					// Otherwise, respect registered image sizes' cropping setting
					if ( false === $width && false === $height ) {
						$width  = $src_per_wp[1];
						$height = $src_per_wp[2];
					} elseif ( isset( $size ) && array_key_exists( $size, $image_sizes ) && isset( $image_sizes[ $size ]['crop'] ) ) {
						$crop = $image_sizes[ $size ]['crop'];
					}
				} else {
					unset( $attachment_id );
					unset( $attachment );
				}
			} // End if()

			// If width is available, constrain to $content_width
			if ( false !== $width && false === strpos( $width, '%' ) && is_numeric( $content_width ) ) {
				if ( $width > $content_width && false !== $height && false === strpos( $height, '%' ) ) {
					$height = round( ( $content_width * $height ) / $width );
					$width  = $content_width;
				} elseif ( $width > $content_width ) {
					$width = $content_width;
				}
			}

			// Set a width if none is found and $content_width is available
			// If width is set in this manner and height is available, use 'resize' instead of 'crop' to prevent skewing
			if ( false === $width && is_numeric( $content_width ) ) {
				$width  = (int) $content_width;
				$height = false;
			}

			// Detect if image source is for a custom-cropped thumbnail and prevent further URL manipulation.
			if ( ! $fullsize_url ) {
				$src = self::strip_image_dimensions_maybe( $src );
				$fullsize_url = true;
			}

			$new_url = self::get_resized_image_url( $src, $width, $height, $crop );
			$new_tag = $tag;
			$new_tag = str_replace( $src_orig, $new_url, $new_tag );

			if ( false !== $width ) {
				$new_tag = preg_replace( '#(?<=\s)(width=["|\']?)[\d%]+(["|\']?)\s?#i', sprintf( '${1}%d${2} ', $width ), $new_tag );
			}

			if ( false !== $height ) {
				$new_tag = preg_replace( '#(?<=\s)(height=["|\']?)[\d%]+(["|\']?)\s?#i', sprintf( '${1}%d${2} ', $height ), $new_tag );
			}

			// Replace original tag with modified version
			$content = str_replace( $tag, $new_tag, $content );
		} // End for

		return $content;
	}

	/**
	 * Resize images in galleries.
	 */
	public function filter_the_galleries( $galleries ) {
		if ( empty( $galleries ) || ! is_array( $galleries ) ) {
			return $galleries;
		}

		// Pass by reference, so we can modify them in place.
		foreach ( $galleries as &$this_gallery ) {
			if ( is_string( $this_gallery ) ) {
				$this_gallery = self::filter_the_content( $this_gallery );
			}
		}
		unset( $this_gallery ); // break the reference.

		return $galleries;
	}

	/**
	 * Filters an array of image `srcset` values, replacing each URL with its resized equivalent.
	 *
	 * @param array $sources An array of image urls and widths.
	 * @return array An array of resized image urls and widths.
	 */
	public function filter_srcset_array( $sources = array(), $size_array = array(), $image_src = '', $image_meta = array(), $attachment_id = 0 ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}

		foreach ( $sources as $i => $source ) {
			if ( ! self::validate_image_url( $source['url'] ) ) {
				continue;
			}

			$url = $source['url'];
			list( $width, $height ) = self::parse_dimensions_from_filename( $url );

			// It's quicker to get the full size with the data we have already, if available
			if ( ! empty( $attachment_id ) ) {
				$url = wp_get_attachment_url( $attachment_id );
			} else {
				$url = self::strip_image_dimensions_maybe( $url );
			}

			if ( 'w' === $source['descriptor'] ) {
				if ( ! $height || ( $source['value'] != $width ) ) {
					$width = $source['value'];
				}
			}

			$sources[ $i ]['url'] = self::get_resized_image_url( $url, $width, $height, true );
		}

		return $sources;
	}

	/**
	 * Filters an array of image `sizes` values, using $content_width instead of image's full size.
	 *
	 * @param array $sizes An array of media query breakpoints.
	 * @param array $size  Width and height of the image
	 * @return array An array of media query breakpoints.
	 */
	public function filter_sizes( $sizes, $size ) {
		global $content_width;

		if ( ! doing_filter( 'the_content' ) ) {
			return $sizes;
		}

		if ( ! $content_width ) {
			$content_width = 1000;
		}

		if ( ( is_array( $size ) && $size[0] < $content_width ) ) {
			return $sizes;
		}

		return sprintf( '(max-width: %1$dpx) 100vw, %1$dpx', $content_width );
	}

	/**
	 * Set a post thumbnail.
	 *
	 * @param int|WP_Post $post Post ID or post object. Default is global $post.
	 * @param string      $url  Thumbnail url to set.
	 * @param array       $size Optional. Size of image. Image size or array of width and height values (in that order).
	 *                          Default false.
	 * @return int|bool True on success, false on failure.
	 */
	public static function set_post_thumbnail_url( $post, $url, $size = false ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}

		$post_id = $post->ID;
		if ( ! empty( $url ) ) {
			update_post_meta( $post_id, '_nir_thumbnail_url', esc_url_raw( $url ) );
			update_post_meta( $post_id, '_thumbnail_id', -1 );

			if ( ! isset( $size[0], $size[1] ) ) {
				// Try to retrieve the image dimensions from it's header.
				$size = self::get_image_dimensions( $url );
			}

			if ( ! empty( $size[0] ) && ! empty( $size[1] ) ) {
				update_post_meta( $post_id, '_nir_thumbnail_meta', array(
					'width'  => absint( $size[0] ),
					'height' => absint( $size[1] ),
				) );
			} else {
				delete_post_meta( $post_id, '_nir_thumbnail_meta' );
			}
		} else {
			delete_post_meta( $post_id, '_nir_thumbnail_url' );
			delete_post_meta( $post_id, '_nir_thumbnail_meta' );
			delete_post_meta( $post_id, '_thumbnail_id', '-1' );
		}

		return true;
	}

	/**
	 * Try to determine height and width from strings WP appends to resized image filenames.
	 *
	 * @param string $src The image URL.
	 * @return array An array consisting of width and height.
	 */
	public static function parse_dimensions_from_filename( $src ) {
		$width_height_string = array();

		if ( preg_match( '#-(\d+)x(\d+)\.(?:' . implode( '|', self::$extensions ) . '){1}$#i', $src, $width_height_string ) ) {
			$width  = (int) $width_height_string[1];
			$height = (int) $width_height_string[2];

			if ( $width && $height ) {
				return array( $width, $height );
			}
		}

		return array( false, false );
	}

	/**
	 * Match all images and any relevant <a> tags in a block of HTML.
	 *
	 * @param string $content Some HTML.
	 * @return array An array of $images matches, where $images[0] is
	 *         an array of full matches, and the link_url, img_tag,
	 *         and img_url keys are arrays of those matches.
	 */
	public static function parse_images_from_html( $content ) {
		$images = array();

		if ( preg_match_all( '#(?:<a[^>]+?href=["|\'](?P<link_url>[^\s]+?)["|\'][^>]*?>\s*)?(?P<img_tag><img[^>]*?\s+?src=["|\'](?P<img_url>[^\s]+?)["|\'].*?>){1}(?:\s*</a>)?#is', $content, $images ) ) {
			foreach ( $images as $key => $unused ) {
				// Simplify the output as much as possible, mostly for confirming test results.
				if ( is_numeric( $key ) && $key > 0 ) {
					unset( $images[ $key ] );
				}
			}

			return $images;
		}

		return array();
	}

	/**
	 * Strip image dimensions from image URL.
	 *
	 * @param string $src The image URL
	 * @return string
	 **/
	protected static function strip_image_dimensions_maybe( $src ) {
		$stripped_src = $src;

		$upload_dir = wp_get_upload_dir();

		// Build URL, first removing WP's resized string so we pass the original image to resizer
		if ( self::image_is_local( $src ) && preg_match( '#(-\d+x\d+)\.(' . implode( '|', self::$extensions ) . '){1}$#i', $src, $src_parts ) ) {
			$stripped_src = str_replace( $src_parts[1], '', $src );

			// Extracts the file path to the image minus the base url
			$file_path = substr( $stripped_src, strlen( $upload_dir['baseurl'] ) );

			if ( file_exists( $upload_dir['basedir'] . $file_path ) ) {
				$src = $stripped_src;
			}
		}

		return $src;
	}

	/**
	 * Return the resized image url for an image.
	 *
	 * @param string $url    The original image url.
	 * @param int    $width  The thumbnail width in pixels.
	 * @param int    $height The thumbnail height in pixels.
	 * @param bool   $crop   Optional. Whether to crop images to specified width and height or resize. Default false.
	 */
	public static function get_resized_image_url( $url, $width, $height, $crop = false ) {
		$width  = absint( $width );
		$height = absint( $height );

		$upload_dir = wp_get_upload_dir();
		$is_local = self::image_is_local( $url );

		$safe_image = strstr( $upload_dir['baseurl'], parse_url( $upload_dir['baseurl'], PHP_URL_PATH ), true ) . '/safe_image';

		$args = array(
			'url'  => ! $is_local ? rawurlencode( $url ) : '',
			'w'    => $width ? $width : '',
			'h'    => $height ? $height : '',
			'crop' => $crop ? 1 : '',
		);

		$settings = get_option( 'nir_settings' );
		$secure_link = ! empty( $settings['secure_link'] ) ? $settings['secure_link'] : '';

		// Calculate hash checksum
		if ( ! empty( $args ) && ! empty( $secure_link ) ) {
			$uri = wp_parse_url( $is_local ? $url : $safe_image, PHP_URL_PATH );

			$find = array( '%uri%', '%w%', '%h%', '%crop%', '%url%' );
			$replace = array( $uri, $args['w'], $args['h'], $args['crop'], $args['url'] );
			$secure_link = str_replace( $find, $replace, $secure_link );
			$hash = base64_encode( md5( $secure_link, true ) );

			$args['d'] = rtrim( strtr( $hash, array( '+' => '-', '/' => '_' ) ), '=' );
		}

		foreach ( $args as $key => $val ) {
			if ( empty( $val ) ) {
				unset( $args[ $key ] );
			}
		}

		$resized_url = add_query_arg( $args, $is_local ? $url : $safe_image );
		$resized_url = set_url_scheme( $resized_url );

		return $resized_url;
	}

	/**
	 * Check if a url is a local image.
	 *
	 * @param string $url The image URL.
	 * @return bool
	 */
	public static function image_is_local( $url ) {
		$upload_dir = wp_get_upload_dir();

		// Remove protocol
		$base_url = preg_replace( '/^(https?:)/i', '', $upload_dir['baseurl'] );
		$url      = preg_replace( '/^(https?:)/i', '', $url );

		return false !== strpos( $url, $base_url );
	}

	/**
	 * Check if a url is a image.
	 *
	 * @param string $url The image url to be checked.
	 * @return bool
	 */
	public static function validate_image_url( $url ) {
		$parsed_url = parse_url( $url );

		if ( ! $parsed_url ) {
			return false;
		}

		// Parse URL and ensure needed keys exist, since the array returned by `parse_url` only includes the URL components it finds.
		$url_info = wp_parse_args( $parsed_url, array(
			'scheme' => null,
			'host'   => null,
			'port'   => null,
			'path'   => null,
		) );

		// Bail if no host is found.
		if ( is_null( $url_info['host'] ) ) {
			return false;
		}

		// Bail if no path is found.
		if ( is_null( $url_info['path'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Provide an array of available image sizes and corresponding dimensions.
	 * Similar to get_intermediate_image_sizes() except that it includes image sizes' dimensions, not just their names.
	 *
	 * @global $wp_additional_image_sizes
	 * @uses get_option
	 * @return array
	 */
	protected static function image_sizes() {
		if ( is_null( self::$image_sizes ) ) {
			global $_wp_additional_image_sizes;

			// Populate an array matching the data structure of $_wp_additional_image_sizes so we have a consistent structure for image sizes.
			$images = array(
				'thumb'  => array(
					'width'  => intval( get_option( 'thumbnail_size_w' ) ),
					'height' => intval( get_option( 'thumbnail_size_h' ) ),
					'crop'   => (bool) get_option( 'thumbnail_crop' ),
				),
				'medium' => array(
					'width'  => intval( get_option( 'medium_size_w' ) ),
					'height' => intval( get_option( 'medium_size_h' ) ),
					'crop'   => false,
				),
				'large'  => array(
					'width'  => intval( get_option( 'large_size_w' ) ),
					'height' => intval( get_option( 'large_size_h' ) ),
					'crop'   => false,
				),
				'full'   => array(
					'width'  => false,
					'height' => false,
					'crop'   => false,
				),
			);

			// Compatibility mapping as found in wp-includes/media.php.
			$images['thumbnail'] = $images['thumb'];

			// Update class variable, merging in $_wp_additional_image_sizes if any are set.
			if ( is_array( $_wp_additional_image_sizes ) && ! empty( $_wp_additional_image_sizes ) ) {
				self::$image_sizes = array_merge( $images, $_wp_additional_image_sizes );
			} else {
				self::$image_sizes = $images;
			}
		} // End if ( is_null( $image_sizes ) )

		return is_array( self::$image_sizes ) ? self::$image_sizes : array();
	}

	/**
	 * Return the image dimensions for a remote image url.
	 *
	 * @param string $url The image url.
	 * @return array The image dimensions
	 */
	public static function get_image_dimensions( $url ) {
		if ( empty( $url ) ) {
			return false;
		}

		$info = (array) pathinfo( parse_url( $url, PHP_URL_PATH ) );
		$ext = isset( $info['extension'] ) ? strtolower( $info['extension'] ) : '';

		switch ( $ext ) {
			case 'png':
				$range = 23;
				break;
			case 'gif':
				$range = 9;
				break;
			default:
				$range = 2047;
		}

		while ( true ) {
			$args = array(
				'httpversion' => '1.1',
				'headers'     => array(
					'Accept' => '*/*',
					'Range'  => 'bytes=0-' . $range,
				),
				'user-agent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.12; rv:55.0) Gecko/20100101 Firefox/55.0',
			);

			$response = wp_remote_get( $url, $args );
			$response_code = wp_remote_retrieve_response_code( $response );
			$data = wp_remote_retrieve_body( $response );
			if ( ( 200 === $response_code || 206 === $response_code ) && $data ) {
				switch ( $ext ) {
					case 'png':
						return self::get_png_dimensions( $data );
					case 'gif':
						return self::get_gif_dimensions( $data );
					default:
						$size = self::get_jpeg_dimensions( $data );
						if ( $size || 32767 === $range ) {
							return $size;
						} else {
							$range = 32767;
							continue;
						}
				}
			} else {
					return false;
			}
		}
	}

	/**
	 * Get PNG image dimensions from first 24 bytes of a PNG image.
	 *
	 * @param bitstream $data The image header buffer.
	 * @return array
	 */
	public static function get_png_dimensions( $data ) {
		// The identity for a PNG is 8Bytes (64bits)long.
		$id = substr( $data, 0, 8 );
		// Make sure we get PNG.
		if ( "\x89PNG\x0d\x0a\x1a\x0a" !== $id ) {
			return false;
		}

		$data = substr( $data, 16, 8 );
		if ( 8 === strlen( $data ) ) {
			$size = unpack( 'N2', $data );
			return array( $size[1], $size[2] );
		}

		return false;
	}

	/**
	 * Get GIF image dimensions from first 10 bytes of a GIF image
	 *
	 * @param bitstream $data The image header buffer.
	 * @return array
	 */
	public static function get_gif_dimensions( $data ) {
		// The identity for a GIF is 6bytes (48Bits)long.
		$id = substr( $data, 0, 6 );
		// Make sure we get GIF 87a or 89a.
		if ( 'GIF87a' !== $id && 'GIF89a' !== $id ) {
			return false;
		}

		$data = substr( $data, 6, 4 );
		if ( 4 === strlen( $data ) ) {
			$size = unpack( 'v2', $data );
			return array( $size[1], $size[2] );
		}

		return false;
	}

	/**
	 * Get JPEG image dimensions from header of a JPEG image
	 *
	 * @param bitstream $data The image header buffer.
	 * @return array
	 */
	public static function get_jpeg_dimensions( $data ) {
		$id = substr( $data, 0, 2 );
		if ( "\xff\xd8" !== $id ) {
			return false;
		}

		$length = strlen( $data );
		$i = 4;
		$data = substr( $data, $i );
		$block = unpack( 'nlength', $data );
		$data = substr( $data, $block['length'] );
		$i += $block['length'];

		while ( $data ) { // iterate through the blocks until we find the start of frame marker (FFC0)
			$block = unpack( 'Cblock/Ctype/nlength', $data ); // Get info about the block.
			if ( 255 !== $block['block'] ) { // We should be at the start of a new block.
				break;
			}
			if ( 192 !== $block['type'] && 194 !== $block['type'] ) { // C0 || C2
				$data = substr( $data, $block['length'] + 2 ); // Next block.
				$i += ( $block['length'] + 2 );
			} else { // We're at the FFC0 block
				$data = substr( $data, 5 ); // Skip FF C0 Length(2) precision(1).
				$i += 5;
				if ( strlen( $data ) >= 4 ) {
					$size = unpack( 'n2', $data );
					return array( $size[2], $size[1] );
				} else {
					// data is truncated?
					return false;
				}
			}
		}

		return false;
	}
}

Ngx_Image_Resizer::instance();
