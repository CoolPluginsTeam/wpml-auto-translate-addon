<?php
/**
 * Google Translate Widget integration for WPML Auto Translate Addon.
 *
 * @package WPML_Auto_Translate
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Translate Widget class.
 */
class WPML_AT_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_footer', array( $this, 'load_widget_scripts' ), 100 );
		add_filter( 'admin_body_class', array( $this, 'add_notranslate_class' ) );
	}

	/**
	 * Load Google Translate Widget scripts.
	 */
	public function load_widget_scripts() {
		global $pagenow;

		$is_translation_dashboard = ! empty( $_GET['page'] ) && strpos( $_GET['page'], 'tm/menu/main.php' ) !== false;
		$is_post_list             = 'edit.php' === $pagenow;

		if ( ! $is_translation_dashboard && ! $is_post_list ) {
			return;
		}

		// Add translate="no" attribute to HTML tag.
		wp_add_inline_script(
			'cp-wpml-auto-translate-admin',
			'document.getElementsByTagName("html")[0].setAttribute("translate", "no");'
		);

		$this->output_widget_scripts();
	}

	/**
	 * Output Google Translate widget scripts and styles.
	 */
	private function output_widget_scripts() {
		?>
		<style>
		/* Hide Google Translate Banner */
		.goog-te-banner-frame.skiptranslate {
			display: none !important;
		}
		body {
			top: 0 !important;
		}
		/* Hide the First Option in Google Translate Combo */
		.goog-te-combo option:first-child {
			display: none;
		}
		</style>
		<script>
		window.cpWpmlGTranslateWidget = function(targetLang) {
			if (typeof google === 'undefined' || !google.translate) {
				// Google Translate script not loaded yet, retry
				setTimeout(function() {
					window.cpWpmlGTranslateWidget(targetLang);
				}, 100);
				return;
			}
			
			var defaultlang = targetLang || 'en';
			
			// Map WPML language codes to Google Translate language codes
			var langMap = {
				'kir': 'ky',
				'oci': 'oc',
				'bel': 'be',
				'he': 'iw',
				'snd': 'sd',
				'jv': 'jw',
				'nb': 'no',
				'nn': 'no',
				'pt-br': 'pt',
				'zh-hans': 'zh-CN',
				'zh-hant': 'zh-TW',
				'zh': 'zh-CN'
			};
			
			if (langMap[defaultlang]) {
				defaultlang = langMap[defaultlang];
			} else if (defaultlang.indexOf('-') !== -1) {
				var parts = defaultlang.split('-');
				defaultlang = parts[0];
			}
			
			// Check if widget already initialized
			var $container = jQuery('#google_translate_element');
			if ($container.length && $container.find('.goog-te-combo').length > 0) {
				return; // Already initialized
			}
			
			// Handle Chinese variants
			if (defaultlang === 'zh' || defaultlang === 'zh-CN' || defaultlang === 'zh-hans') {
				new google.translate.TranslateElement(
					{
						pageLanguage: 'en',
						includedLanguages: 'zh-CN,zh-TW',
						defaultLanguage: 'zh-CN',
						multilanguagePage: true
					},
					'google_translate_element'
				);
			} else {
				new google.translate.TranslateElement(
					{
						pageLanguage: 'en',
						includedLanguages: defaultlang,
						defaultLanguage: defaultlang,
						multilanguagePage: true
					},
					'google_translate_element'
				);
			}
		};
		</script>
		<script src='https://translate.google.com/translate_a/element.js'></script>
		<?php
	}

	/**
	 * Add notranslate class to admin body to disable whole page translation.
	 *
	 * @param string $classes Admin body classes.
	 * @return string Modified classes.
	 */
	public function add_notranslate_class( $classes ) {
		global $pagenow;

		$is_translation_dashboard = ! empty( $_GET['page'] ) && strpos( $_GET['page'], 'tm/menu/main.php' ) !== false;
		$is_post_list             = 'edit.php' === $pagenow;

		if ( ! $is_translation_dashboard && ! $is_post_list ) {
			return $classes;
		}

		return "$classes notranslate";
	}
}

