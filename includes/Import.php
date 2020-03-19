<?php

namespace RRZE\XLIFF;

class Import
{   
	protected $helpers;

	/**
	 * Array von \WP_Error-Objekten, die während des Exports aufgetreten sind.
	 * 
	 * @var \WP_Error[]
	 */
	protected $errors = [];

	protected $info = [];

	/**
	 * Array von Post-IDs, in denen es noch Platzhalter gibt.
	 */
	protected $posts_with_placeholders_left = [];

	/**
	 * Array von Beiträgen, in denen es noch Links zu Inhalten der Ursprungs-Site gibt,
	 * weil zum Zeitpunkt des Imports noch keine Übersetzung vorhanden war.
	 * Schlüssel ist die ID des Inhalts, in dem der Link vorkommt, Wert ein Array von Arrays
	 * nach dem folgenden Schema:
	 * [
	 *		'permalink' => $permalink,
	 *		'id' => $source_post_id,
	 * ]
	 *
	 * @var array
	 */
	protected $posts_with_links_to_source_content_left = [];

	/**
	 * Array of post relationships. Key is ID on source site, value the ID on the target site.
	 * 
	 * @var array
	 */
	protected $post_translation_relationships = [];

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

				$import = $this->import_file($_FILES['rrze-bulk-import-file']);
				if ($import === false) {
					foreach ($this->errors as $error) {
						Notices::add_notice($error->get_error_message(), 'error');
					}
				} else {
					Notices::add_notice(__('Import successful', 'rrze-xliff'), 'success');

					if (!empty((array) $this->info)) {
						foreach($this->info as $info) {
							Notices::add_notice($info);
						}
					}
				}
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
			if ($import === false) {
				foreach ($this->errors as $error) {
					Notices::add_notice($error->get_error_message(), 'error');
				}
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
	 * @return boolean true on success, false when there were errors.
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
		$data = str_replace('<target>', '<target><![CDATA[', $data);
		$data = str_replace('</target>', ']]></target>', $data);

		$xml = simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA);

		if (!$xml) {
			return new \WP_Error('load_xml_error', __('The file’s content is no XLIFF.', 'rrze-xliff'));
		}

		$posts_with_link_placeholders_left = [];

		// Die (eventuell) mehreren Posts loopen.
		foreach ($xml->file as $post_data) {
			$post_array = [];

			$post_data_attr = $post_data->attributes();

			$source_post_id = (int) $post_data_attr['id'];
			
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
					$post_content = (string) $unit->segment->target;

					// Links im Beitragsinhalt prüfen und aktualisieren.
					preg_match_all('/{{hohenheim_url_post_id:(\d+)}}/', $post_content, $url_post_id_matches);
					if ($url_post_id_matches) {
						$post_content = $this->replace_link_placeholders($post_content, $source_post_id, $url_post_id_matches, $source_site_id, $target_site_id);
					}
					
					$post_array['post_content'] = $post_content;
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

			$this->post_translation_relationships[$source_post_id] = $target_post_id;

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

		switch_to_blog($target_site_id);

		// Prüfen, ob wir noch Beiträge mit Links auf Inhalte der Urpsunrgssite haben.
		if ($this->posts_with_links_to_source_content_left) {
			$tmp = $this->posts_with_links_to_source_content_left;
			$tmp_relationship = $this->post_translation_relationships;
			$this->posts_with_links_to_source_content_left = [];
			$this->post_translation_relationships = [];
			foreach ($tmp as $replacement_post_id => $links) {
				$translated_post_id = isset($tmp_relationship[$replacement_post_id]) ? $tmp_relationship[$replacement_post_id] : null;

				// Prüfen, ob es keine ID für einen übersetzten Post gibt.
				if ($translated_post_id === null) {
					array_push($this->errors, new \WP_Error('missing_error_link_post', sprintf(__('The translation of the post with the ID %d should contain one or more links to content on the source site, but the post does not exist.', 'rrze-xliff'), (int) $source_post_id)));
					continue;
				}

				$translated_post = get_post($translated_post_id);

				// Prüfen, ob es ein Problem beim Holen des Beitrags gab.
				if (!$translated_post) {
					array_push($this->errors, new \WP_Error('error_getting_translated_post', sprintf(__('The post with the ID %d should contain one or more links to content on the source site, but the post does not exist.', 'rrze-xliff'), (int) $translated_post_id)));
					continue;
				}

				$translated_post_content = $translated_post->post_content;
				foreach ($links as $link) {
					$target_post_id = isset($tmp_relationship[$link['id']]) ? $tmp_relationship[$link['id']] : null;

					// Wenn kein übersetzter Beitrag vorhanden ist, fügen wir einen Eintrag in das jetzt wieder leere Array hinzu.
					// Der Hinweis daraus wird später generiert.
					if ($target_post_id === null) {
						$this->posts_with_links_to_source_content_left[$translated_post_id][] = [];
						continue;
					}

					$translated_post_content = $this->replace_link_placeholder($translated_post_content, $translated_post_id, $link['permalink'], $link['id'], $source_site_id, $target_post_id, $target_site_id);					
					wp_update_post(
						[
							'ID' => $translated_post_id,
							'post_content' => $translated_post_content,
						]
					);
				}
			}

			// Prüfen, ob wir immer noch Links haben, die nicht ersetzt werden konnten.
			if ($this->posts_with_links_to_source_content_left) {
				foreach ($this->posts_with_links_to_source_content_left as $replacement_post_id => $links) {
					$post_with_wrong_links_left = get_post($replacement_post_id);
					
					// Prüfen, ob es ein Problem beim Holen des Beitrags gab.
					if (!$post_with_wrong_links_left) {
						array_push($this->errors, new \WP_Error('error_getting_translated_post', sprintf(__('The post with the ID %d should contain one or more links to content on the source site, but the post does not exist.', 'rrze-xliff'), (int) $replacement_post_id)));
						continue;
					}

					$this->info[] = sprintf( /* translators: 1=linked title of content */
						__('The post „%s“ contains one or more links that point to content on the source site.', 'rrze-xliff'),
						sprintf(
							'<a href="%s" target="_blank">%s</a>',
							get_the_permalink($post_with_wrong_links_left),
							$post_with_wrong_links_left->post_title
						)
					);
				}
			}
		}

		// Prüfen, ob wir noch Beiträge mit Platzhaltern haben.
		if (!empty($posts_with_link_placeholders_left)) {			
			// Wir haben noch Beiträge mit Link-Platzhaltern.
			foreach ($posts_with_link_placeholders_left as $key => $link_error_post_id) {
				$link_error_post = get_post($link_error_post_id);

				// Prüfen, ob wir einen Beitrag zurückbekommen haben.
				if (!$link_error_post) {
					unset($posts_with_link_placeholders_left[$key]);
					array_push($this->errors, new \WP_Error('missing_error_link_post', sprintf(__('The post with the ID %d should contain link placeholders that were not replaced, but it does not exist.', 'rrze-xliff'), (int) $link_error_post_id)));
					continue;
				}
				
				preg_match_all('/{{hohenheim_url_post_id:(\d+)}}/', $link_error_post->post_content, $url_post_id_matches);
				
				if (!$url_post_id_matches) {
					unset($posts_with_link_placeholders_left[$key]);
					continue;
				}

				$error_string = sprintf( /* translators: 1=linked title of content, 2=number of placeholders */
					_n(
						'In the post „%1$s“ is %2$d link placeholder left that can neither be replaced with the link to a English content nor to a German content. The <code>href</code> attribute begins with <code>{{hohenheim_url_post_id:</code>.',
						'In the post „%1$s“ are %2$d link placeholders left that can neither be replaced with the link to a English content nor to a German content. The <code>href</code> attribute begins with <code>{{hohenheim_url_post_id:</code>.',
						count($url_post_id_matches[0]),
						'rrze-xliff'
					),
					sprintf(
						'<a href="%s" target="_blank">%s</a>',
						get_the_permalink($link_error_post),
						$link_error_post->post_title
					),
					count($url_post_id_matches[0])
				);

				array_push($this->errors, new \WP_Error('link_placeholder_left', $error_string));
			}
		}

		restore_current_blog();

		if (!empty($this->errors)) {
			return false;
		}
		
		return true;
	}

	/**
	 * Einen Platzhalter mit einem Link ersetzen.
	 * 
	 * @param string $subject             Der String, in dem ersetzt wird.
	 * @param int    $replacement_post_id Die ID der des Inhalts, in dem ersetzt wird.
	 * @param array  $matches             Die Treffer eines preg_match_all()-Aufrufs.
	 * @param int    $source_site_id      Die ID der Site, von der der Inhalt ist.
	 * @param int    $target_site_id      Die ID der Site, zu der die Inhalte importiert werden.
	 * 
	 * @return string Der String mit ersetztem Platzhalter.
	 */
	protected function replace_link_placeholders($subject, $replacement_post_id, $matches, $source_site_id, $target_site_id)
	{
		if (!array($matches) || !array($matches[1])) {
			return $subject;
		}

		switch_to_blog($source_site_id);

		foreach ($matches[1] as $key => $post_id_match) {
			// Prüfen, ob es eine Übersetzung zu dem Beitrag aus dem Export gibt.
			$translations = \Inpsyde\MultilingualPress\translationIds($post_id_match, 'post');
			if (!is_array($translations) || empty($translations)) {
				// Es gibt keine Übersetzung, also ersetzen wir den Platzhalter mit einem Link auf den Inhalt der Urprungssite.
				$subject = $this->replace_link_placeholder($subject, $replacement_post_id, $matches[0][$key], $post_id_match, $source_site_id);
				continue;
			}

			foreach ($translations as $site_id => $translated_post_id) {
				// Prüfen, ob $site_id die aktuelle Site ist.
				if ($site_id === get_current_blog_id()) {
					continue;
				}

				// Wenn wir eine Seite haben, ersetzen wir den Platzhalter mit dem Link. Falls nicht, mit dem von der Ursprungssite.
				$subject = $this->replace_link_placeholder($subject, $replacement_post_id, $matches[0][$key], $post_id_match, $source_site_id, $translated_post_id, $target_site_id);
			}
		}
		restore_current_blog();

		return $subject;
	}

	/**
	 * Einen Platzhalter mit einem Link ersetzen.
	 * 
	 * @param string $subject             Der String, in dem ersetzt wird.
	 * @param int    $replacement_post_id Die ID des Inhalts, in dem ersetzt wird.
	 * @param string $search              Der zu ersetzende String.
	 * @param int    $source_post_id      Die ID des Inhalts auf der Ursprungssite.
	 * @param int    $source_site_id      Die ID der Site, von der der Inhalt ist.
	 * @param int    $target_post_id      Die ID des Inhalts auf der Zielsite.
	 * @param int    $target_site_id      Die ID der Site, auf die importiert wird.
	 * 
	 * @return string Der String mit ersetztem Platzhalter.
	 */
	protected function replace_link_placeholder($subject, $replacement_post_id, $search, $source_post_id, $source_site_id, $target_post_id = 0, $target_site_id = 0)
	{
		switch_to_blog($target_site_id);
		$permalink = get_the_permalink($target_post_id);
		restore_current_blog();

		if ($permalink) {
			return str_replace($search, $permalink, $subject);
		}

		// Kein Link gefunden, wir versuchen den Platzhalter mit dem Link auf den Inhalt der Quellsite zu ersetzen.
		switch_to_blog($source_site_id);
		$permalink = get_the_permalink($source_post_id);
		restore_current_blog();

		// Wieder kein Glück, wie geben den Inhalt unverändert zurück.
		if (!$permalink) {
			array_push($this->posts_with_placeholders_left, $replacement_post_id);
			return $subject;
		}

		$this->posts_with_links_to_source_content_left[$replacement_post_id][] = [
			'permalink' => $permalink,
			'id' => $source_post_id,
		];

		// Link ersetzen und Inhalt zurückgeben.
		return str_replace($search, $permalink, $subject);
	}

	/**
	 * ID des übersetzten Beitrags bekommen. Wenn keine Übersetzung existiert, wird eine angelegt.
	 * 
	 * @param int $source_parent_post_id Die ID des Ursprungsbeitrags.
	 * @param int $source_site_id        Die ID der Ursprungssite.
	 * 
	 * @return int|bool ID des neuen Posts oder false.
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

				return $translated_parent_post_id;
			}
		}

		// Keine Übersetzung vorhanden.
		// Den Titel des übergeordneten Beitrags holen und als Entwurf speichern.
		$post_parent_title = get_the_title($source_parent_post_id);

		switch_to_blog($target_site_id);

		$parent_post_id = wp_insert_post([
			'post_title' => $post_parent_title,
			'post_status' => 'draft',
			'post_type' => $post_type,
		]);

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
