<?php
/**
 * Translation handler for WPML Auto Translate Addon.
 *
 * @package WPML_Auto_Translate
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translator class.
 */
class WPML_AT_Translator {

	/**
	 * Save translation for a post.
	 *
	 * @param WP_Post $post              Original post object.
	 * @param string  $target_lang        Target language code.
	 * @param string  $translated_title   Translated title.
	 * @param string  $translated_content Translated content.
	 * @param array   $translated_fields  Array of translated fields.
	 * @param array   $translations_map   Translations map for page builders.
	 * @param string  $editor_type        Editor type (classic, block, elementor).
	 * @return int|WP_Error Translated post ID or WP_Error on failure.
	 */
	public function save_translation( $post, $target_lang, $translated_title, $translated_content, $translated_fields, $translations_map, $editor_type ) {
		$source_lang          = WPML_AT_Helper::get_post_source_language( $post->ID, $post->post_type );
		$existing_translation_id = WPML_AT_Helper::get_existing_translation_id( $post->ID, $post->post_type, $target_lang );
		$trid                 = apply_filters( 'wpml_element_trid', null, $post->ID, 'post_' . $post->post_type );

		// Use provided translated title, or fallback to original title.
		$translated_title = ! empty( $translated_title ) ? $translated_title : $post->post_title;

		// Check if we have translations_map (from page builder content with WPML field names).
		if ( ! empty( $translations_map ) && is_array( $translations_map ) ) {
			$translated_fields = array();
			foreach ( $translations_map as $field_name => $translated_text ) {
				// Allow HTML content, so check if field_name exists and translated_text is not null
				if ( ! empty( $field_name ) && null !== $translated_text && '' !== $translated_text ) {
					$translated_fields[] = array(
						'field_type'  => $field_name,
						'field_data'   => base64_encode( $translated_text ),
						'field_format' => 'base64',
					);
				}
			}
		}

		// Handle page builder content with individual field translations.
		if ( ! empty( $translated_fields ) && is_array( $translated_fields ) ) {
			return $this->save_page_builder_translation( $post, $target_lang, $translated_title, $translated_fields, $source_lang, $trid, $existing_translation_id );
		}

		// Handle classic editor, block editor, or Elementor content.
		return $this->save_standard_translation( $post, $target_lang, $translated_title, $translated_content, $editor_type, $source_lang, $trid, $existing_translation_id );
	}

	/**
	 * Save page builder translation with individual field translations.
	 *
	 * @param WP_Post $post                    Original post object.
	 * @param string  $target_lang              Target language code.
	 * @param string  $translated_title         Translated title.
	 * @param array   $translated_fields        Array of translated fields.
	 * @param string  $source_lang              Source language code.
	 * @param int     $trid                     Translation ID.
	 * @param int     $existing_translation_id  Existing translation post ID.
	 * @return int|WP_Error Translated post ID or WP_Error on failure.
	 */
	private function save_page_builder_translation( $post, $target_lang, $translated_title, $translated_fields, $source_lang, $trid, $existing_translation_id ) {
		if ( $existing_translation_id ) {
			wp_update_post(
				array(
					'ID'         => $existing_translation_id,
					'post_title' => $translated_title,
				)
			);
			$translated_post_id = $existing_translation_id;
		} else {
			$new_post_data = array(
				'post_type'   => $post->post_type,
				'post_status' => $post->post_status,
				'post_title'  => $translated_title,
			);

			$translated_post_id = wp_insert_post( $new_post_data );

			if ( $translated_post_id && $trid ) {
				do_action(
					'wpml_set_element_language_details',
					array(
						'element_id'           => $translated_post_id,
						'element_type'         => 'post_' . $post->post_type,
						'trid'                 => $trid,
						'language_code'        => $target_lang,
						'source_language_code' => $source_lang,
					)
				);
			}
		}

		if ( $translated_post_id ) {
			$this->save_string_translations( $translated_post_id, $post->ID, $target_lang, $translated_fields );
			return $translated_post_id;
		}

		return new WP_Error( 'save_failed', 'Failed to create/update translation post' );
	}

	/**
	 * Save standard translation (classic, block, or Elementor).
	 *
	 * @param WP_Post $post                   Original post object.
	 * @param string  $target_lang             Target language code.
	 * @param string  $translated_title        Translated title.
	 * @param string  $translated_content      Translated content.
	 * @param string  $editor_type             Editor type.
	 * @param string  $source_lang             Source language code.
	 * @param int     $trid                    Translation ID.
	 * @param int     $existing_translation_id Existing translation post ID.
	 * @return int|WP_Error Translated post ID or WP_Error on failure.
	 */
	private function save_standard_translation( $post, $target_lang, $translated_title, $translated_content, $editor_type, $source_lang, $trid, $existing_translation_id ) {
		$translated_body = '';
		$elementor_data  = '';

		$translated_content = WPML_AT_Helper::decode_unicode_escapes( $translated_content );

		if ( 'elementor' === $editor_type && ! empty( $translated_content ) ) {
			$decoded_elementor = json_decode( wp_unslash( $translated_content ), true );
			if ( is_array( $decoded_elementor ) ) {
				$elementor_data = wp_json_encode( $decoded_elementor, JSON_UNESCAPED_UNICODE );
			} else {
				$elementor_data = wp_unslash( $translated_content );
			}
			$translated_body = '';
		} elseif ( 'block' === $editor_type && ! empty( $translated_content ) ) {
			// Decode the JSON blocks data
			$decoded_blocks = json_decode( wp_unslash( $translated_content ), true );
			
			if ( is_array( $decoded_blocks ) && ! empty( $decoded_blocks ) ) {
				// Ensure HTML content in innerHTML is preserved
				$decoded_blocks = $this->preserve_block_html( $decoded_blocks );
				
				// Serialize blocks back to block markup
				$translated_body = serialize_blocks( $decoded_blocks );
			} else {
				// Fallback: if JSON decode fails, try to use as-is
				$translated_body = wp_kses_post( $translated_content );
			}
		} else {
			$translated_body = wp_kses_post( $translated_content );
		}

		if ( $existing_translation_id ) {
			$update_data = array(
				'ID'         => $existing_translation_id,
				'post_title' => $translated_title,
			);

			if ( 'elementor' !== $editor_type ) {
				$update_data['post_content'] = $translated_body;
			}

			wp_update_post( $update_data );

			if ( 'elementor' === $editor_type && ! empty( $elementor_data ) ) {
				$this->save_elementor_data( $existing_translation_id, $elementor_data );
			}

			return $existing_translation_id;
		}

		$new_post_data = array(
			'post_type'   => $post->post_type,
			'post_status' => $post->post_status,
			'post_title'  => $translated_title,
		);

		if ( 'elementor' !== $editor_type ) {
			$new_post_data['post_content'] = $translated_body;
		}

		$new_post_id = wp_insert_post( $new_post_data );

		if ( $new_post_id && $trid ) {
			do_action(
				'wpml_set_element_language_details',
				array(
					'element_id'           => $new_post_id,
					'element_type'         => 'post_' . $post->post_type,
					'trid'                 => $trid,
					'language_code'        => $target_lang,
					'source_language_code' => $source_lang,
				)
			);

			if ( 'elementor' === $editor_type && ! empty( $elementor_data ) ) {
				$this->save_elementor_data( $new_post_id, $elementor_data );
			}

			return $new_post_id;
		}

		return new WP_Error( 'save_failed', 'Failed to create translation post' );
	}

	/**
	 * Preserve HTML content in block innerHTML and innerContent.
	 *
	 * @param array $blocks Array of block data.
	 * @return array Processed blocks with preserved HTML.
	 */
	private function preserve_block_html( $blocks ) {
		if ( ! is_array( $blocks ) ) {
			return $blocks;
		}

		foreach ( $blocks as &$block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			// Preserve innerHTML if it exists (contains HTML like <strong>, <a>, etc.)
			if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
				// Decode any Unicode escapes while preserving HTML structure
				$block['innerHTML'] = WPML_AT_Helper::decode_unicode_escapes( $block['innerHTML'] );
			}

			// Preserve innerContent array (contains HTML strings and null placeholders for inner blocks)
			// This is critical for serialize_blocks() to work correctly
			if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				foreach ( $block['innerContent'] as &$content ) {
					// Process string content (null values are placeholders for inner blocks)
					if ( is_string( $content ) ) {
						// Decode Unicode escapes while preserving HTML tags
						$content = WPML_AT_Helper::decode_unicode_escapes( $content );
					}
				}
				unset( $content );
			} elseif ( isset( $block['innerHTML'] ) && ! isset( $block['innerContent'] ) ) {
				// If innerContent doesn't exist but innerHTML does, create innerContent
				// This ensures serialize_blocks() has the data it needs
				$block['innerContent'] = array( $block['innerHTML'] );
			}

			// Recursively process inner blocks
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->preserve_block_html( $block['innerBlocks'] );
			}
		}
		unset( $block );

		return $blocks;
	}

	/**
	 * Save Elementor data for a post.
	 *
	 * @param int    $post_id        Post ID.
	 * @param string $elementor_data JSON encoded Elementor data.
	 */
	private function save_elementor_data( $post_id, $elementor_data ) {
		if ( ! empty( $elementor_data ) ) {
			update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
			update_post_meta( $post_id, '_elementor_data', $elementor_data );
			update_post_meta( $post_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '0.0.0' );
			update_post_meta( $post_id, '_elementor_pro_version', defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '0.0.0' );
		}
	}

	/**
	 * Save individual string translations for page builder content.
	 *
	 * @param int   $translated_post_id Translated post ID.
	 * @param int   $original_post_id   Original post ID.
	 * @param string $target_lang        Target language code.
	 * @param array  $translated_fields  Array of translated fields.
	 */
	private function save_string_translations( $translated_post_id, $original_post_id, $target_lang, $translated_fields ) {
		if ( ! class_exists( 'WPML_TM_Page_Builders_Field_Wrapper' ) ) {
			return;
		}

		$fields_to_save = array();

		foreach ( $translated_fields as $field ) {
			$field_type = isset( $field['field_type'] ) ? $field['field_type'] : '';
			$field_data = isset( $field['field_data'] ) ? $field['field_data'] : '';

			// Check field_type exists, but allow field_data to be empty string (for HTML-only content)
			if ( empty( $field_type ) || null === $field_data || false === $field_data ) {
				continue;
			}

			$wrapper   = new WPML_TM_Page_Builders_Field_Wrapper( $field_type );
			$string_id = $wrapper->get_string_id();

			if ( ! $string_id ) {
				continue;
			}

			$decoded_data = $field_data;
			
			// Decode base64 if needed
			if ( isset( $field['field_format'] ) && 'base64' === $field['field_format'] ) {
				$decoded = base64_decode( $field_data, true ); // Use strict mode
				
				// Only use decoded data if base64_decode was successful
				if ( false !== $decoded ) {
					$decoded_data = $decoded;
				} else {
					// If base64 decode fails, try using the original data
					// This handles cases where data might not be base64 encoded
					$decoded_data = $field_data;
				}
			}

			// Decode Unicode escapes while preserving HTML
			$decoded_data = WPML_AT_Helper::decode_unicode_escapes( $decoded_data );

			// Ensure we have a string (even if empty, to preserve HTML structure)
			if ( ! is_string( $decoded_data ) ) {
				$decoded_data = (string) $decoded_data;
			}

			// Save the translation - WPML will handle HTML content properly
			do_action(
				'wpml_add_string_translation',
				$string_id,
				$target_lang,
				$decoded_data,
				10, // TRANSLATION_COMPLETE.
				0,  // translator_id.
				''  // translation_service.
			);

			$fields_to_save[ $field_type ] = array(
				'field_type' => $field_type,
				'data'       => $decoded_data,
			);
		}

		if ( ! empty( $fields_to_save ) ) {
			do_action( 'wpml_pb_finished_adding_string_translations', $translated_post_id, $original_post_id, $fields_to_save );
		}
	}
}

