<?php
/**
 * AJAX handlers for WPML Auto Translate Addon.
 *
 * @package WPML_Auto_Translate
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handler class.
 */
class WPML_AT_Ajax {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_cp_wpml_google_auto_translate_get_post_contents', array( $this, 'get_post_contents' ) );
		add_action( 'wp_ajax_cp_wpml_google_auto_translate_save_translation', array( $this, 'save_translation' ) );
		add_action( 'wp_ajax_cp_wpml_google_auto_translate_get_pending_languages', array( $this, 'get_pending_languages' ) );
	}

	/**
	 * Get post contents for translation.
	 */
	public function get_post_contents() {
		check_ajax_referer( WPML_AT_Helper::NONCE_KEY, 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'msg' => 'No permission' ) );
		}

		$ids = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array();
		$ids = array_map( 'intval', $ids );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'msg' => 'No IDs provided' ) );
		}

		$data = array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}

			// Check if Elementor page.
			$elementor_enabled = get_post_meta( $id, '_elementor_edit_mode', true );

			if ( $elementor_enabled && 'builder' === $elementor_enabled && defined( 'ELEMENTOR_VERSION' ) ) {
				$elementor_data = get_post_meta( $id, '_elementor_data', true );

				if ( $elementor_data && '' !== $elementor_data ) {
					$elementor_data_array = array();

					if ( class_exists( '\Elementor\Plugin' ) && property_exists( '\Elementor\Plugin', 'instance' ) ) {
						$elementor_data_array = \Elementor\Plugin::$instance->documents->get( $id )->get_elements_data();
					} else {
						$elementor_data_array = json_decode( $elementor_data, true );
					}

					$data[ $id ] = array(
						'id'              => $id,
						'title'           => get_the_title( $post ),
						'original_content' => $elementor_data_array,
						'content'         => $elementor_data_array,
						'editor_type'     => 'elementor',
					);
					continue;
				}
			}

			// Check for Gutenberg blocks.
			$content    = $post->post_content;
			$has_blocks = has_blocks( $content );

			if ( $has_blocks ) {
				$blocks = parse_blocks( $content );
				$data[ $id ] = array(
					'id'              => $id,
					'title'           => get_the_title( $post ),
					'original_content' => $blocks,
					'content'         => $blocks,
					'editor_type'     => 'block',
				);
			} else {
				$data[ $id ] = array(
					'id'              => $id,
					'title'           => get_the_title( $post ),
					'original_content' => $content,
					'content'         => $content,
					'editor_type'     => 'classic',
				);
			}
		}

		wp_send_json_success( $data );
	}

	/**
	 * Get pending languages (languages without existing translations) for selected posts.
	 */
	public function get_pending_languages() {
		check_ajax_referer( WPML_AT_Helper::NONCE_KEY, 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'msg' => 'No permission' ) );
		}

		$ids = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array();
		$ids = array_map( 'intval', $ids );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'msg' => 'No IDs provided' ) );
		}

		$all_languages = WPML_AT_Helper::get_wpml_languages();

		// Get source language from first post.
		$source_lang = 'en';
		if ( ! empty( $ids ) ) {
			$first_post = get_post( $ids[0] );
			if ( $first_post ) {
				$source_lang = WPML_AT_Helper::get_post_source_language( $ids[0], $first_post->post_type );
			}
		}

		// Get languages that already have translations for all selected posts.
		$languages_with_translations = array();
		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$translations = WPML_AT_Helper::get_post_translations( $post_id, $post->post_type );

			if ( ! empty( $translations ) && is_array( $translations ) ) {
				foreach ( $translations as $lang_code => $translation ) {
					$info = WPML_AT_Helper::extract_translation_info( $translation );

					if ( $info['code'] && $info['code'] !== $source_lang && $info['element_id'] ) {
						if ( ! isset( $languages_with_translations[ $info['code'] ] ) ) {
							$languages_with_translations[ $info['code'] ] = 0;
						}
						$languages_with_translations[ $info['code'] ]++;
					}
				}
			}
		}

		// Filter out languages that have translations for ALL selected posts.
		$pending_languages = array();
		$total_posts       = count( $ids );
		foreach ( $all_languages as $lang ) {
			// Skip source language.
			if ( $lang['code'] === $source_lang ) {
				continue;
			}

			// Only include if translation doesn't exist for all posts.
			if ( ! isset( $languages_with_translations[ $lang['code'] ] ) ||
				$languages_with_translations[ $lang['code'] ] < $total_posts ) {
				$pending_languages[] = $lang;
			}
		}

		wp_send_json_success(
			array(
				'languages'   => $pending_languages,
				'source_lang' => $source_lang,
			)
		);
	}

	/**
	 * Save translation from the table popup interface.
	 */
	public function save_translation() {
		check_ajax_referer( WPML_AT_Helper::NONCE_KEY, 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'msg' => 'No permission' ) );
		}

		$post_id            = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		$target_lang        = isset( $_POST['target_lang'] ) ? sanitize_text_field( $_POST['target_lang'] ) : '';
		$translated_title   = isset( $_POST['translated_title'] ) ? WPML_AT_Helper::decode_unicode_escapes( sanitize_text_field( $_POST['translated_title'] ) ) : '';
		$translated_content = isset( $_POST['translated_content'] ) ? WPML_AT_Helper::decode_unicode_escapes( $_POST['translated_content'] ) : '';
		
		// Preserve HTML in translated_fields - don't sanitize as it may contain HTML tags
		$translated_fields = isset( $_POST['translated_fields'] ) ? $_POST['translated_fields'] : array();
		if ( is_array( $translated_fields ) ) {
			// Process each field to decode Unicode escapes while preserving HTML
			foreach ( $translated_fields as $key => $field ) {
				if ( isset( $field['field_data'] ) && is_string( $field['field_data'] ) ) {
					$translated_fields[ $key ]['field_data'] = WPML_AT_Helper::decode_unicode_escapes( $field['field_data'] );
				}
			}
		}
		
		$translations_map    = isset( $_POST['translations_map'] ) ? json_decode( stripslashes( $_POST['translations_map'] ), true ) : array();
		$editor_type         = isset( $_POST['editor_type'] ) ? sanitize_text_field( $_POST['editor_type'] ) : 'classic';

		// Decode Unicode escapes in translations_map.
		if ( ! empty( $translations_map ) && is_array( $translations_map ) ) {
			foreach ( $translations_map as $key => $value ) {
				$translations_map[ $key ] = WPML_AT_Helper::decode_unicode_escapes( $value );
			}
		}

		if ( ! $post_id || empty( $target_lang ) ) {
			wp_send_json_error( array( 'msg' => 'Missing post ID or target language' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'msg' => 'Post not found' ) );
		}

		// Use translator class to save translation.
		$translator = new WPML_AT_Translator();
		$result     = $translator->save_translation(
			$post,
			$target_lang,
			$translated_title,
			$translated_content,
			$translated_fields,
			$translations_map,
			$editor_type
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'msg' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'msg'           => 'Translation saved successfully',
				'translated_id' => $result,
			)
		);
	}
}

