<?php

namespace RRZE\XLIFF;

defined('ABSPATH') || exit;

class Main
{
    protected $helpers;

    /**
     * Main-Klasse wird instanziiert.
     */
    public function __construct()
    {
        new Settings();
        new Export();
        new Import();
        $this->helpers = new Helpers();
        new Notices();

		add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_script']);
		
		add_action('init', [$this, 'register_post_meta']);
        
        add_action('current_screen', function ($screen) {
			if ($screen->base === 'toplevel_page_nestedpages') {
				add_action('admin_enqueue_scripts', [$this, 'enqueue_nestedpages_bulk_export_script']);
				return;
			}

            if ($screen->base != 'post' && $screen->id != "edit-$screen->post_type") {
                return;
            }

            if (! $this->helpers->is_user_capable()) {
                return;
            }

            $post_types = Options::get_options()->rrze_xliff_export_import_post_types;
            if (! in_array($screen->post_type, $post_types)) {
                return;
            }

            if ($screen->base == 'post' && ! $screen->is_block_editor) {
                add_action('admin_enqueue_scripts', [$this, 'enqueue_classic_editor_script']);
                add_action('admin_footer', [$this, 'classic_editor_xliff_templates']);
                add_action('edit_form_top', [$this, 'classic_editor_nonce_field']);
            } elseif ($screen->id == "edit-$screen->post_type") {
                add_action('admin_enqueue_scripts', [$this, 'enqueue_bulk_export_script']);
            }
		});

		/**
		 * Add hint about excluded pages in the nested pages list view.
		 */
		add_filter('the_title', function($title, $post_id, $view = '') {
			if ($view !== 'nestedpages_title') {
				return $title;
			}

			$excluded_from_mass_export = get_post_meta($post_id, 'rrze_xliff_exclude_from_mass_export', true);
			if ($excluded_from_mass_export !== '1') {
				return $title;
			}

			return sprintf(
				'%s <strong>(%s)</strong>',
				$title,
				__('Excluded from mass export', 'rrze-xliff')
			);
		}, 10, 3);
	}
	
	/**
	 * Register post meta.
	 */
	public function register_post_meta() {
		$post_types = Options::get_options()->rrze_xliff_export_import_post_types;
		if (! is_array($post_types) || empty($post_types)) {
			return;
		}

		foreach ($post_types as $post_type) {
			register_post_meta($post_type, 'rrze_xliff_exclude_from_mass_export', [
				'show_in_rest' => true,
				'type' => 'boolean',
				'single' => true,
			]);
		}
	}

    /**
     * Enqueue des Block-Editor-Skripts.
     */
    public function enqueue_block_editor_script()
    {
		$post_type = get_post_type();
		$post_types = Options::get_options()->rrze_xliff_export_import_post_types;
		if (! in_array($post_type, $post_types)) {
			return;
		}
        wp_register_script('rrze-xliff-block-editor-script', plugins_url('assets/dist/js/block-editor-functions.js', plugin_basename(RRZE_PLUGIN_FILE)), ['wp-plugins', 'wp-element', 'wp-edit-post', 'wp-block-serialization-default-parser', 'wp-i18n']);
        wp_localize_script('rrze-xliff-block-editor-script', 'rrzeXliffJavaScriptData', [
            'email_address' => Options::get_options()->rrze_xliff_export_email_address,
            'export_title' => __('Export post as XLIFF', 'rrze-xliff'),
            'download' => __('Download XLIFF file', 'rrze-xliff'),
            'send_via_email' => __('Or send the file to an email address:', 'rrze-xliff'),
            'email_address_label' => __('Email address', 'rrze-xliff'),
            'email_text_label' => __('Email text', 'rrze-xliff'),
            'send_email' => __('Send XLIFF file', 'rrze-xliff'),
            'import' => __('Import', 'rrze-xliff'),
            'xliff' => __('XLIFF:', 'rrze-xliff'),
			'export' => __('Export', 'rrze-xliff'),
			'exclude_from_export_label' => __('Exclude from mass export', 'rrze-xliff'),
        ]);
        wp_enqueue_script('rrze-xliff-block-editor-script');
    }

    /**
     * Enqueue des Block-Editor-Skripts.
     */
    public function enqueue_classic_editor_script()
    {
        wp_enqueue_style('rrze-xliff-classic-editor-style', plugins_url('assets/dist/css/classic-editor.css', plugin_basename(RRZE_PLUGIN_FILE)), [], null);

        wp_register_script('rrze-xliff-classic-editor-script', plugins_url('assets/dist/js/classic-editor-functions.js', plugin_basename(RRZE_PLUGIN_FILE)), [], null, true);
        wp_localize_script('rrze-xliff-classic-editor-script', 'rrzeXliffJavaScriptData', [
            'post_id' => get_the_ID(),
            'nonce' => wp_create_nonce('xliff_export'),
            'dropdown_menu_label' => __('XLIFF Export/Import', 'rrze-xliff'),
            'export' => __('Export', 'rrze-xliff'),
            'import' => __('Import', 'rrze-xliff'),
        ]);
        wp_enqueue_script('rrze-xliff-classic-editor-script');
    }

    /**
     * Einbinden des Skripts für den Bulk-Export.
     */
    public function enqueue_bulk_export_script()
    {
        wp_register_script('rrze-xliff-bulk-export', plugins_url('assets/dist/js/bulk-export-functions.js', plugin_basename(RRZE_PLUGIN_FILE)), [], false, true);
        wp_localize_script('rrze-xliff-bulk-export', 'rrzeXliffJavaScriptData', [
            'email_address' => Options::get_options()->rrze_xliff_export_email_address
        ]);
        wp_enqueue_script('rrze-xliff-bulk-export');
    }

    /**
     * Einbinden des Skripts für den Bulk-Export.
     */
    public function enqueue_nestedpages_bulk_export_script()
    {
		// Build preset options select markup.
		$saved_settings = Options::get_options()->rrze_xliff_export_presets;

		$saved_presets_markup = '';
		if ($saved_settings && is_array($saved_settings) && !empty($saved_settings)) {
			$saved_presets_markup = sprintf('<select id="export-presets"><option value="">%s</option>', __('Export presets', 'rrze-xliff'));
			foreach ($saved_settings as $saved_setting) {
				$preset_page = get_post($saved_setting);

				// Remove setting if page does not exist.
				if ($preset_page === null) {
					unset($saved_settings[(int) $saved_setting]);
					continue;
				}

				$saved_presets_markup .= sprintf(
					'<option value="%s">%s</option>',
					$saved_setting,
					$preset_page->post_title
				);
			}
			$saved_presets_markup .= '</select>';
		}

        wp_register_script('rrze-xliff-nestedpages-bulk-export', plugins_url('assets/dist/js/nestedpages-bulk-export-functions.js', plugin_basename(RRZE_PLUGIN_FILE)), [], false, true);
		wp_localize_script('rrze-xliff-nestedpages-bulk-export', 'rrzeXliffJavaScriptData', [
			'dropdown_menu_label' => __('Bulk XLIFF export', 'rrze-xliff'),
			'select_tree_button_label' => __('Select page tree', 'rrze-xliff'),
			'preset_select_markup' => $saved_presets_markup,
			'preset_name_hidden_field' => '<input type="hidden" id="rrze-export-preset-name" name="rrze-export-preset-name" type="text">',
			'import_form' => sprintf(
				'<form id="rrze-bulk-import-form" method="post" enctype="multipart/form-data">
				%s
					<label for="rrze-bulk-import-file">%s</label>
					<input type="file" name="rrze-bulk-import-file" id="rrze-bulk-import-file" accept=".xml,.xliff" required="required">
					<input type="submit" value="%s">
				</form>',
				wp_nonce_field('rrze_bulk_import_form', 'rrze_bulk_import_form_nonce', true, false),
				__('Select file to import', 'rrze-xliff'),
				__('Import', 'rrze-xliff')

			)
        ]);
		wp_enqueue_script('rrze-xliff-nestedpages-bulk-export');
    }

    /**
     * HTML-Templates für Import und Export, die im Classic Editor genutzt werden.
     */
    public function classic_editor_xliff_templates()
    {
        printf(
            '<div class="components-modal__screen-overlay rrze-xliff-export-modal-wrapper" style="display: none">
                <div>
                    <div>
                        <div class="components-modal__frame" role="dialog" aria-labelledby="components-modal-header-0" tabindex="-1">
                            <div tabindex="0" class="components-modal__content">
                                <div class="components-modal__header">
                                    <div class="components-modal__header-heading-container">
                                        <h1 id="components-modal-header-0" class="components-modal__header-heading">%s</h1>
                                    </div>
                                    <button type="button" aria-label="%s" class="components-button components-icon-button close-xliff-modal">
                                        <svg aria-hidden="true" role="img" focusable="false" class="dashicon dashicons-no-alt" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><path d="M14.95 6.46L11.41 10l3.54 3.54-1.41 1.41L10 11.42l-3.53 3.53-1.42-1.42L8.58 10 5.05 6.47l1.42-1.42L10 8.58l3.54-3.53z"></path></svg>
                                    </button>
                                </div>
                                <p><a href="%s" class="button">%s</a></p>
                                <p><strong>%s</strong></p>
                                <p>
                                    <label style="display: block" for="xliff_export_email_address">%s</label>
                                    <input type="email" value="%s" id="xliff_export_email_address" name="xliff_export_email_address">
                                    <label style="display: block" for="xliff_export_email_note">%s</label>
                                    <textarea name="xliff_export_email_note" id="xliff_export_email_note" style="width: 100%%;"></textarea>
                                </p>
                                <div class="xliff-export-notices">

                                </div>
                                <p><button class="button" id="xliff-export-email-address-link">%s</button></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>',
            __('Export post as XLIFF', 'rrze-xliff'),
            __('Close dialog', 'rrze-xliff'),
            trailingslashit(get_admin_url()) . '?xliff-export=' . get_the_ID(),
            __('Download XLIFF file', 'rrze-xliff'),
            __('Or send the file to an email address:', 'rrze-xliff'),
            __('Email address', 'rrze-xliff'),
            Options::get_options()->rrze_xliff_export_email_address,
            __('Email text', 'rrze-xliff'),
            __('Send XLIFF file', 'rrze-xliff')
        );

        printf(
            '<div class="components-modal__screen-overlay rrze-xliff-import-modal-wrapper" style="display: none">
                <div>
                    <div>
                        <div class="components-modal__frame" role="dialog" aria-labelledby="components-modal-header-0" tabindex="-1">
                            <div tabindex="0" class="components-modal__content">
                                <div class="components-modal__header">
                                    <div class="components-modal__header-heading-container">
                                        <h1 id="components-modal-header-0" class="components-modal__header-heading">%s</h1>
                                    </div>
                                    <button type="button" aria-label="%s" class="components-button components-icon-button close-xliff-modal">
                                        <svg aria-hidden="true" role="img" focusable="false" class="dashicon dashicons-no-alt" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><path d="M14.95 6.46L11.41 10l3.54 3.54-1.41 1.41L10 11.42l-3.53 3.53-1.42-1.42L8.58 10 5.05 6.47l1.42-1.42L10 8.58l3.54-3.53z"></path></svg>
                                    </button>
                                </div>
                                <p>
                                    <label style="display: block" for="xliff_import_file">%s</label>
                                    <input type="file" id="xliff_import_file" name="xliff_import_file" accept=".xliff,.xml" form="post">
                                </p>
                                <p><button class="button" id="xliff_import_button" type="submit" form="post">%s</button></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>',
            __('Import', 'rrze-xliff'),
            __('Close dialog', 'rrze-xliff'),
            __('Choose XLIFF file to import', 'rrze-xliff'),
            __('Import XLIFF file', 'rrze-xliff')
        );
    }

    /**
     * Nonce für Import-Aktion ausgeben.
     */
    public function classic_editor_nonce_field()
    {
        wp_nonce_field('rrze-xliff/includes/Main', 'rrze_xliff_file_import_nonce');
	}
}
