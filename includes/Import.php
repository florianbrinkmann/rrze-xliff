<?php

namespace RRZE\XLIFF;

class Import
{   
	protected $helpers;

	protected $errors = [];

	protected $mlp_api;

	/**
	 * Initialisierung des Importers.
	 */
	public function __construct()
	{
		$this->helpers = new Helpers();

		$this->mlp_api = \Inpsyde\MultilingualPress\resolve(
			\Inpsyde\MultilingualPress\Framework\Api\ContentRelations::class
		);

		add_action('save_post', [$this, 'save_post']);
		add_action('post_edit_form_tag', [$this, 'update_edit_form']);
		
		add_action('current_screen', function ($screen) {
			if ($screen->base === 'toplevel_page_nestedpages') {
				// Prüfen, ob notwendige Sachen für den Import vorhanden sind.
				if (!isset($_FILES['rrze-bulk-import-file']) || !is_user_logged_in() || !current_user_can('edit_posts') || !isset($_POST['rrze_bulk_import_form_nonce']) || !wp_verify_nonce($_POST['rrze_bulk_import_form_nonce'], 'rrze_bulk_import_form')) {
					return;
				}

				$this->import_file($_FILES['rrze-bulk-import-file']);
			}
		});
	}

	/**
	 * Import anstoßen.
	 */
	public function save_post($post_id)
	{
		// Nonce prüfen.
		if (!isset($_POST['rrze_xliff_file_import_nonce']) || !\wp_verify_nonce($_POST['rrze_xliff_file_import_nonce'], 'rrze-xliff/includes/Main')) {
			return;
		}

		// Bei automatischem Speichern nichts tun.
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// Auf Berechtigung prüfen.
		if ($this->helpers->is_user_capable() === false) {
			return;
		}

		if (wp_is_post_revision($post_id)) {
			return;
		}

		if (isset($_FILES['xliff_import_file']) && $_FILES['xliff_import_file']['tmp_name'] !== '') {
			remove_action('save_post', [$this, 'save_post']);
			$import = $this->import_file($post_id, $_FILES['xliff_import_file']);
			if (is_wp_error($import)) {
				Notices::add_notice($import->get_error_message(), 'error');
			} else {
				Notices::add_notice(__('Import successful', 'rrze-xliff'), 'success');
			}
			add_action('save_post', [$this, 'save_post']);
		}
	}

	/**
	 * Importieren einer XLIFF-Datei.
	 * 
	 * @param array $file Der Dateiinhalt.
	 * 
	 * @return \WP_Error|true
	 */
	protected function import_file($file)
	{
		$fh = fopen($file['tmp_name'], 'r');

		if ($fh === false) {
			return new \WP_Error('file_not_found', __('The file was not found.', 'rrze-xliff')); 
		}

		$data = fread($fh, $file['size']);

		fclose($fh);

		unlink($file['tmp_name']);

		// Das Beitrags-HTML in CDATA packen, um den Parser nicht zu irritieren.
		$data = preg_replace( '/<unit id="body">((?:.|\s)*?)<target>/', '<unit id="body">$1<target><![CDATA[', $data );
		$data = preg_replace( '/<target><!\[CDATA\[((?:.|\s)*?)<\/target>/', '<target><![CDATA[$1]]></target>', $data );

		$xml = simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA);

		if (!$xml) {
			return new \WP_Error('load_xml_error', __('The file’s content is no XLIFF.', 'rrze-xliff'));
		}

		// Die (eventuell) mehreren Posts loopen.
		foreach ($xml->file as $post_data) {
			$post_array = [];

			$post_data_attr = $post_data->attributes();
			
			$source_site_id = (int) $post_data_attr['data-site-id'];
			$target_site_id = (int) $post_data_attr['data-target-site-id'];

			$post_array['post_status'] = (string) $post_data_attr['data-post-status'];

			$post_int_attr_keys = [
				'data-translated-post' => 'ID',
				'data-translated-parent-post' => 'post_parent',
			];

			foreach ($post_data_attr as $post_attr => $attr_value) {
				if (isset($post_int_attr_keys[$post_attr])) {
					$post_array[$post_int_attr_keys[$post_attr]] = (int) $attr_value;
				}
			}

			$post_array['post_type'] = (string) $post_data_attr['data-post-type'];

			// Prüfen, ob kein übersetzter übergeordneter Beitrag eingetragen ist, aber der exportierte Beitrag einen hat.
			if (!isset($post_array['post_parent']) && isset($post_data_attr['data-parent-post'])) {
				$parent_post_id = $this->get_parent_post_id((int) $post_data_attr['data-parent-post'], $source_site_id, $target_site_id);
				if ($parent_post_id) {
					$post_array['post_parent'] = $parent_post_id;

					// Prüfen, ob der Elternbeitrag wiederum einen Elternbeitrag hatte.
					$tmp = (int) $post_data_attr['data-parent-post'];
					do {
						switch_to_blog($source_site_id);
						$source_parent_post_id = wp_get_post_parent_id($tmp);
	
						if ($source_parent_post_id) {
							restore_current_blog();
							$target_parent_post_id = $this->get_parent_post_id($source_parent_post_id, $source_site_id, $target_site_id);
	
							if ($target_parent_post_id) {
								// @todo: Set translation rerelationship for new posts.
								switch_to_blog($target_site_id);
								wp_update_post(
									[
										'ID' => $parent_post_id,
										'post_parent' => $target_parent_post_id,
									]
								);
	
								$parent_post_id = $target_parent_post_id;
								$tmp = $source_parent_post_id;
								restore_current_blog();
								continue;
							}
						}
	
						$tmp = false;
						restore_current_blog();
					} while ($tmp !== false);
				}
			}

			foreach ($post_data as $unit) {
				$attr = $unit->attributes();
				if ((string) $attr['id'] === 'title') {
					$post_array['post_title'] = (string) $unit->segment->target;
				} elseif ((string) $attr['id'] === 'body') {
					$post_array['post_content'] = (string) $unit->segment->target;
				} elseif ((string) $attr['id'] === 'excerpt') {
					$post_array['post_excerpt'] = (string) $node->target;
				} elseif (strpos((string) $attr['id'], '_meta_') === 0) {
					$meta_key = (string) substr((string) $attr['id'], strlen('_meta_'));
					$meta_value = (string) $unit->segment->target;
					if (!empty($meta_value) && !is_numeric($meta_value)) {
						if (isset($post_array['meta_input'][$meta_key])) {
							$post_array['meta_input'][$meta_key] = $meta_value;
							continue;
						}
						$post_meta_array[$meta_key] = $meta_value;
					} 
				}
			}

			// Zur Ziel-Site wechseln.
			switch_to_blog($target_site_id);
	
			// Beitrag einfügen/aktualisieren.
			$target_post_id = wp_insert_post($post_array);
			if (!$target_post_id) {
				restore_current_blog();
				array_push($this->errors, new \WP_Error('post_update_error', sprintf(__('An unknown error occurred. The import of the source post with the ID %d failed.', 'rrze-xliff'), (int) $post_data_attr['id'])));
				continue;
			}

			// Beitrag mit Urpsungsbeitrag verknüpfen, wenn neuer Beitrag.
			if (!isset($post_array['ID'])) {
				$this->mlp_api->createRelationship(
					[
						$source_site_id => $post_data_attr['id'],
						$target_site_id => $target_post_id,
					],
					'post'
				);
			}

			// Doppelte Post-Meta-Keys einfügen.
			if (isset($post_meta_array)) {
				foreach ($post_meta_array as $meta_key => $meta_value) {
					add_post_meta($target_post_id, $meta_key, $meta_value);
				}
			}
	
			restore_current_blog();
		}

		if (!empty($this->errors)) {
			return $this->errors;
		}
		return true;
	}

	/**
	 * Get translated ID of parent post. If no translation exists, it creates a post.
	 * 
	 * @param int $source_parent_post_id The ID of the source parent post.
	 * @param int $source_site_id        The ID of the source site.
	 * 
	 * @return int|bool ID of parent post or false.
	 */
	protected function get_parent_post_id($source_parent_post_id, $source_site_id, $target_site_id)
	{
		switch_to_blog($source_site_id);

		$post_type = get_post_type($source_parent_post_id);

		// Prüfen, ob der ursprüngliche übergeordnete Beitrag inzwischen doch eine Übersetzung hat.
		$translations = \Inpsyde\MultilingualPress\translationIds($source_parent_post_id, 'post');
		if (is_array($translations) && !empty($translations)) {
			foreach ($translations as $site_id => $translated_parent_post_id) {
				// Prüfen, ob $site_id die aktuelle Site ist.
				if ($site_id === get_current_blog_id()) {
					continue;
				}

				$parent_post_id = $translated_parent_post_id;
			}
		}

		// Prüfen, ob keine Übersetzung vorhanden war.
		if (!isset($parent_post_id)) {
			// Den Titel des übergeordneten Beitrags holen und als Entwurf speichern.
			$post_parent_title = get_the_title($source_parent_post_id);
		}

		switch_to_blog($target_site_id);

		if (!isset($parent_post_id) && isset($post_parent_title)) {
			$parent_post_id = wp_insert_post([
				'post_title' => $post_parent_title,
				'post_status' => 'draft',
				'post_type' => $post_type,
			]);
		}

		restore_current_blog();

		if (!$parent_post_id) {
			return false;
		}

		// Verbindung zwischen neuem Beitrag und dem auf der Urpsungsseite.
		$this->mlp_api->createRelationship(
			[
				$source_site_id => $source_parent_post_id,
				$target_site_id => $parent_post_id,
			],
			'post'
		);

		return (int) $parent_post_id;
	}

	/**
	 * Anpassung des Formulars des Classic Editors.
	 */
	public function update_edit_form()
	{
		echo ' enctype="multipart/form-data"';
	}
	
	/**
	 * Einbinden des Skripts für den Bulk-Export.
	 */
	public function enqueue_bulk_export_script()
	{
		global $current_screen;
		if ($current_screen->id === 'edit-post') {
			wp_enqueue_script('rrze-xliff-bulk-export', plugins_url('assets/dist/js/bulk-export-functions.js', plugin_basename(RRZE_PLUGIN_FILE)), [], false, true);
		}
	}
}
