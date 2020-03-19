<?php

namespace RRZE\XLIFF;

class Export
{
    protected $xliff_file = [];
    
    protected $helpers;

    /**
     * Initialisierung des Exporters.
     */
    public function __construct()
    {
		$this->helpers = new Helpers();
		
		add_action('admin_post_npBulkActions', function() {
			$action = sanitize_text_field($_POST['np_bulk_action']);
			if ($action !== 'xliff-export') {
				return;
			}
			$post_ids = sanitize_text_field($_POST['post_ids']);
			$post_ids = rtrim($post_ids, ",");
			$post_ids = explode(',', $post_ids);
			$file = $this->get_xliff_file($post_ids);
			$this->send_xliff_download();
			header('Location:' . sanitize_text_field($_POST['page']));
		}, 2);
        
        add_action('admin_init', function() {
            // Bulk-Export-Optionen für alle ausgewählten Beitragstypen anzeigen.
            $post_types = Options::get_options()->rrze_xliff_export_import_post_types;
            foreach ($post_types as $post_type) {
                add_filter("bulk_actions-edit-$post_type", [$this, 'bulk_export_action']);
                add_filter("handle_bulk_actions-edit-$post_type", [$this, 'bulk_export_handler'], 10, 3);
            }
            
            // Download eines einzelnen Exports.
            if ($this->helpers->is_user_capable() && isset($_GET['xliff-export']) && absint($_GET['xliff-export'])) {
                // XLIFF-String holen.
                $xliff_file = $this->get_xliff_file($_GET['xliff-export']);
                
                if (is_wp_error($xliff_file)) {
                    echo $xliff_file->get_error_message();
                    wp_die();
                }

                $this->send_xliff_download();
            }
        });
        
        // AJAX-Aktion für Exportversand via E-Mail.
        add_action( 'wp_ajax_xliff_email_export', function() {
            if ($this->helpers->is_user_capable()) {
                // XLIFF-String holen.
                $xliff_file = $this->get_xliff_file($_POST['xliff_export_post']);
                if (is_wp_error($xliff_file)) {
                    echo $xliff_file->get_error_message();
                    wp_die();
                }
                if (isset($_POST['xliff_export_email_address'])) {
                    $this->send_xliff_download($_POST['xliff_export_email_address'], $_POST['email_export_note']);
                } else {
                    $this->send_xliff_download();
                }

                // Return JSON of notice(s).
                (new Notices())
                    ->admin_notices();
            }
        });
    }
    
    /**
     * Bulk-Action für Mehrfachexport einfügen.
     */
    public function bulk_export_action($bulk_actions)
    {
        if ($this->helpers->is_user_capable()) {
            $bulk_actions['xliff_bulk_export'] = __('Bulk XLIFF export', 'rrze-xliff');
        }
        return $bulk_actions;
    }

    /**
     * Bulk-Mehrfachexport ausführen.
     */
    public function bulk_export_handler($redirect_to, $doaction, $post_ids)
    {
        if ($doaction !== 'xliff_bulk_export' || $this->helpers->is_user_capable() === false) {
            return $redirect_to;
		}
		
		$file = $this->get_xliff_file($post_ids);

        if ((isset($_GET['xliff-bulk-export-choice']) && $_GET['xliff-bulk-export-choice'] === 'xliff-bulk-export-choice-download') || !isset($_GET['xliff-bulk-export-choice'])) {
            $this->send_xliff_download();
        } else {
            if (isset($_GET['xliff-bulk-export-email'])) {
                $this->send_xliff_download($_GET['xliff-bulk-export-email'], $_GET['xliff-bulk-export-note']);
            } else {
                $this->send_xliff_download();
            }
        }
        $redirect_to = add_query_arg('xliff_bulk_export', count($post_ids), $redirect_to);
        return $redirect_to;
    }

    /**
     * XLIFF-Datei als Download bereitstellen oder via Mail versenden.
     */
    protected function send_xliff_download($email = '', $body = '')
    {
        // Prüfen ob keine Datei(en) in $this->xliff_file sind.
        if (empty($this->xliff_file)) {
            Notices::add_notice(__('No file was found for download or sending.', 'rrze-xliff'), 'success');
            return;
        }

        $body = preg_replace('/(\r\n|[\r\n])/', '<br>', $body);

        // Entscheiden, ob die Datei heruntergeladen oder per Mail verschickt werden soll.
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			header('Content-Description: File Transfer');
			header('Content-Disposition: attachment; filename=' . $this->xliff_file[0]['filename']);
			header('Content-Type: text/xml; charset=' . get_option('blog_charset'), true);
			echo $this->xliff_file[0]['file_content'];
			exit;
        } else {
            $to = $email;
            $subject = Options::get_options()->rrze_xliff_export_email_subject;
            if ($body === '') {
                $body = __('XLIFF export', 'rrze-xliff');
            }
            // Platzhalter ersetzen. Wenn es um einen Bulk-Export geht, Platzhalter rauslöschen.
            if ($this->xliff_file[0]['post_id'] === null) {
                $subject = str_replace('%%POST_ID%%', '', $subject);
                $subject = str_replace('%%POST_TITLE%%', '', $subject);
            } else {
                $subject = str_replace('%%POST_ID%%', $this->xliff_file[0]['post_id'], $subject);
                $subject = str_replace('%%POST_TITLE%%', get_the_title($this->xliff_file[0]['post_id']), $subject);
            }

            $headers = ['Content-Type: text/html; charset=UTF-8'];
            
			add_action('phpmailer_init', function(&$phpmailer) {
				$phpmailer->AddStringAttachment($this->xliff_file[0]['file_content'], $this->xliff_file[0]['filename']);
			});

			$mail_sent = wp_mail($to, $subject, $body, $headers);
            
            if ($mail_sent === true) {
                Notices::add_notice(__('The export was sent successfully.', 'rrze-xliff'), 'success');
            } else {
                Notices::add_notice(__('There was an error sending the export.', 'rrze-xliff'), 'error');
            }
        }
    }

    /**
     * XLIFF-Markup genieren und dem Array hinzufügen.
	 * 
	 * @param int|array Single or multiple post ids.
     * 
     * @return string|\WP_Error Error bei Fehler, andernfalls Dateistring.
     */
    protected function get_xliff_file($post_id)
    {        
        $source_language_code = \get_bloginfo('language');
        if ($source_language_code == '') {
            return new \WP_Error('no_source_lang_code', __('No source language code set.', 'rrze-xliff'));
        }
		$source_language_code = substr($source_language_code, 0, 2);
		
		$file_blocks = '';
		if (is_array($post_id)) {
			foreach ($post_id as $id) {
				// Check if that is a post to exclude from mass exports.
				$exclude_from_mass_export = get_post_meta($id, 'rrze_xliff_exclude_from_mass_export', true);
				if ($exclude_from_mass_export === '1') {
					continue;
				}
				$file_block = $this->get_xliff_file_block($id);
				if (is_wp_error($file_block) || false === $file_block) {
					continue;
				}

				$file_blocks .= $file_block;
			}
		} else {
			$file_block = $this->get_xliff_file_block($post_id);
			if (is_wp_error($file_block) || false === $file_block) {
				return $file_block;
			}

			$file_blocks .= $file_block;
		}
        
        $file = sprintf(
            '<xliff xmlns="urn:oasis:names:tc:xliff:document:2.0" version="2.0" srcLang="%1$s">
%2$s
</xliff>',
            $source_language_code,
            $file_blocks
        );

        if (is_multisite()) {
            global $current_blog;
            $domain = $current_blog->domain;
            $path = $current_blog->path;
            $blog_id = $current_blog->blog_id;
        } else {
            $site_url = \get_home_url();
            $parsed_url = parse_url($site_url);
            $domain = $parsed_url['host'];
            $path = isset($parsed_url['path']) ? $parsed_url['path'] : '/';
            $blog_id = 1;
        }
        
        $filename = sanitize_file_name(sprintf(
			'%s_%s_%s.xml',
			isset( $_POST['rrze-export-preset-name'] ) && $_POST['rrze-export-preset-name'] !== '' ? sanitize_title( $_POST['rrze-export-preset-name'] ) : $domain,
			date('dmY'),
			date('Hi')
        ));

        array_push($this->xliff_file, [
            'filename' => $filename,
            'file_content' => $file,
            'post_id' => null,
        ]);
        
        return $file;
    }

    /**
     * Get meta data from image object and store it in elements array.
     */
    protected function get_img_data($elements, $img_obj, $img_id_string)
    {
        $alt_text = get_post_meta($img_obj->ID, '_wp_attachment_image_alt', true);
        if ($alt_text !== '') {
            $elements[] = (object) [
                'field_type' => $img_id_string . '_alt_text',
                'field_data' => $alt_text,
                'field_data_translated' => $alt_text, 
            ];
        }

        $caption = $img_obj->post_excerpt;
        if ($caption !== '') {
            $elements[] = (object) [
                'field_type' => $img_id_string . '_caption',
                'field_data' => $caption,
                'field_data_translated' => $caption, 
            ];
        }

        $title = $img_obj->post_title;
        if ($title !== '') {
            $elements[] = (object) [
                'field_type' => $img_id_string . '_title',
                'field_data' => $title,
                'field_data_translated' => $title, 
            ];
        }

        $description = $img_obj->post_content;
        if ($description !== '') {
            $elements[] = (object) [
                'field_type' => $img_id_string . '_description',
                'field_data' => $description,
                'field_data_translated' => $description, 
            ];
        }

        return $elements;
	}
	
	/**
	 * Returns <file> block for the specified post.
	 * 
	 * @param int $post_id ID of the post.
	 * 
	 * @return string The XLIFF file block markup.
	 */
	protected function get_xliff_file_block($post_id)
	{
		$export_post = get_post($post_id, OBJECT);
        if ($export_post === null) {
            return new \WP_Error('no_post', __('The submitted ID for export does not match a post', 'rrze-xliff'));
        }

		// Die ID der Ziel-Site holen.
		$target_site_id = 0;
		$languages = \Inpsyde\MultilingualPress\assignedLanguages();
		foreach ($languages as $site_id => $language) {
			if ($site_id === get_current_blog_id()) {
				continue;
			}
			$target_site_id = $site_id;
			break;
		}
		
		$elements_with_translation = [];

		// Prüfen, ob der Post/Page eine Übersetzung hat.
		$translations = \Inpsyde\MultilingualPress\translationIds($post_id, 'post');
		if (is_array($translations) && !empty($translations)) {
			// Verknüpfte Übersetzung vorhanden.
			foreach ($translations as $site_id => $translated_post_id) {
				// Prüfen, ob $site_id die aktuelle Site ist.
				if ($site_id === get_current_blog_id()) {
					continue;
				}

				$elements_with_translation[] = [
					'attr_name' => "data-translated-post",
					'attr_value' => $translated_post_id
				];

				// Prüfen, ob der Update-Zeitpunkt des übersetzten Beitrags neuer ist als der, der exportiert werden soll.
				$source_post_timestamp = get_post_timestamp($post_id, 'modified');

				switch_to_blog($target_site_id);
				$target_post_timestamp = get_post_timestamp($translated_post_id, 'modified');
				restore_current_blog();
				
				if ($target_post_timestamp > $source_post_timestamp) {
					return false;
				}
			}
		}

		$site_url = get_site_url();
		$pattern = sprintf('|href="(%s[^"]*)"|', $site_url);

		// Alle internen Links aus dem Beitragsinhalt suchen und auf Übersetzungen prüfen.
		$internal_links = preg_match_all($pattern, $export_post->post_content, $matches);

		if ($matches) {
			$search_array = [];
			$replace_array = [];

			// Die gefundenen URLs durchlaufen.
			foreach ($matches[1] as $key => $url) {
				$tmp = url_to_postid($url);

				// Kein Beitrag zuzuordnen, nächste URL.
				if (0 === $tmp) {
					continue;
				}

				$url_query = parse_url($url, PHP_URL_QUERY);
				$url_fragment = parse_url($url, PHP_URL_FRAGMENT);
				array_push($search_array, $matches[0][$key]);
				array_push($replace_array, sprintf(
					'href="{{hohenheim_url_post_id:%d}}%s%s"',
					$tmp,
					$url_query !== null ? sprintf(
						'{{hohenheim_url_query:%s}}',
						$url_query
					) : '',
					$url_fragment !== null ? sprintf(
						'{{hohenheim_url_hash:%s}}',
						$url_fragment
					) : ''
				));
			}

			if (!empty($search_array) && !empty($replace_array) && count($search_array) === count($replace_array)) {
				$export_post->post_content = str_replace($search_array, $replace_array, $export_post->post_content);
			}
		}

        // XLIFF-Markup erstellen.
        $elements = [
            (object) [
                'field_type' => 'title',
                'field_data' => $export_post->post_title,
                'field_data_translated' => $export_post->post_title,
            ],
            (object) [
                'field_type' => 'body',
                'field_data' => $export_post->post_content,
                'field_data_translated' => $export_post->post_content,
            ],
            (object) [
                'field_type' => 'excerpt',
                'field_data' => $export_post->post_excerpt,
                'field_data_translated' => $export_post->post_excerpt,
            ]
        ];

        $post_meta = get_post_meta($post_id);
        foreach ($post_meta as $meta_key => $meta_value) {
			// Metawerte mit Unterstrich überspringen. Werte von The SEO Framework aber integrieren.
            if (strpos($meta_key, '_') === 0 && strpos($meta_key, '_genesis_') !== 0) {
                continue;
            }
            
            if (empty($meta_value)) {
                continue;
            }        
            
            $meta_value = array_map('maybe_unserialize', $meta_value);
            $meta_value = $meta_value[0];
            
            if (empty($meta_value) || is_array($meta_value) || is_numeric($meta_value)) {
                continue;
            }
                    
            $elements[] = (object) [
                'field_type' => '_meta_' . $meta_key,
                'field_data' => $meta_value,
                'field_data_translated' => $meta_value,            
            ];
		}
		
		// Terme/Taxonomien behandeln.
		$taxonomies = get_object_taxonomies($export_post);
		if (is_array($taxonomies) && !empty($taxonomies)) {
			foreach ($taxonomies as $taxonomy) {
				$terms = wp_get_post_terms($post_id, $taxonomy);

				if (!is_array($terms) || empty($terms)) {
					continue;
				}

				foreach ($terms as $term) {
					$translations = \Inpsyde\MultilingualPress\translationIds($term->term_id, 'term');
					if (!is_array($translations) || empty($translations)) {
						// Keine Übersetzung vorhanden, also Wert in XML schreiben.
						$elements[] = (object) [
							'field_type' => "_term_{$term->taxonomy}_translation_term_id_$term->term_id",
							'field_data' => $term->name,
							'field_data_translated' => $term->name,            
						];
						continue;
					}

					// Verknüpfte Übersetzung vorhanden.
					foreach ($translations as $site_id => $term_id) {
						// Prüfen, ob $site_id die aktuelle Site ist.
						if ($site_id === get_current_blog_id()) {
							continue;
						}

						$elements_with_translation[] = [
							'attr_name' => "data-taxonomy-$taxonomy-term_id-$term_id",
							'attr_value' => $term_id
						];
					}
				}
			}
		}

        // Handling des Beitragsbilds.
        $post_thumbnail = get_the_post_thumbnail($post_id);
        if ($post_thumbnail !== '') {
            $post_thumbnail_id = get_post_thumbnail_id($post_id);
            $post_thumbnail_post = get_post($post_thumbnail_id);
            $elements = $this->get_img_data($elements, $post_thumbnail_post, 'post_thumbnail');
        }

        $post_images_ids = [];

        $attached_images = get_attached_media('image', $post_id);

        if (!empty($attached_images)) {
            foreach ($attached_images as $attached_image) {
                // Prüfen, ob das Bild bereits vorgekommen ist.
                if (in_array($attached_image->ID, $post_images_ids)) {
                    continue;
                }

                array_push($post_images_ids, $attached_image->ID);

                $elements = $this->get_img_data($elements, $attached_image, "attached_img_$attached_image->ID");
            }
        }

        $galleries = get_post_galleries($post_id, false);

        if (!empty($galleries)) {
            foreach ($galleries as $gallery) {
                $ids = explode(',', $gallery['ids']);
                if (is_array($ids) && ! empty($ids)) {
                    foreach ($ids as $image_id) {
                        if (in_array($image_id, $post_images_ids)) {
                            continue;
                        }

                        $image = get_post($image_id);

                        if ($image === null) {
                            continue;
                        }

                        array_push($post_images_ids, $image_id);

                        $elements = $this->get_img_data($elements, $image, "gallery_img_$image_id");
                    }
                }
            }
        }

        $translation_units = '';

        foreach ($elements as $element) {
            $field_data = $element->field_data;
            if ($field_data != '') {
                $translation_units .= sprintf(
                    '        <unit id="%1$s">
            <segment>
                <target>%2$s</target>
            </segment>
        </unit>',
                    $element->field_type,
                    $field_data
                );
            }
		}
		
		// Prüfen, ob das Element ein Elternelement hat.
		$parent_id = wp_get_post_parent_id($post_id);
		if ($parent_id) {
			// Prüfen, ob der Elternbeitrag eine Übersetzung hat.
			$translations = \Inpsyde\MultilingualPress\translationIds($parent_id, 'post');
			if (is_array($translations) && !empty($translations)) {
				// Verknüpfte Übersetzung vorhanden.
				foreach ($translations as $site_id => $translated_parent_post_id) {
					// Prüfen, ob $site_id die aktuelle Site ist.
					if ($site_id === get_current_blog_id()) {
						continue;
					}

					$elements_with_translation[] = [
						'attr_name' => "data-translated-parent-post",
						'attr_value' => $translated_parent_post_id
					];
				}
			}
		}

		// Die bereits übersetzten Dinge abfrühstücken.
		$translated_attrs = '';
		if (!empty($elements_with_translation)) {
			foreach ($elements_with_translation as $element_with_translation) {
				$translated_attrs .= sprintf(
					' %s="%s" ',
					$element_with_translation['attr_name'],
					$element_with_translation['attr_value'],
				);
			}
		}

        $file = sprintf(
            '<file id="%d" data-post-type="%s" data-site-id="%d" data-target-site-id="%d" data-post-status="%s" %s %s>
%s
</file>',
			$post_id,
			$export_post->post_type,
			get_current_blog_id(),
			$target_site_id,
			$export_post->post_status,
			$translated_attrs,
			$parent_id ? sprintf(
				' data-parent-post="%d" ',
				$parent_id
			) : '',
            $translation_units
		);
		
		return $file;
	}
}
