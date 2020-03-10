/* global rrzeXliffJavaScriptData */
/**
 * Funktionen für den Massenexport.
 */
(function(){
	// Import-Button einfügen.
	const nestedPagesTools = document.querySelector('.nestedpages-tools');
	if (nestedPagesTools) {
		nestedPagesTools.innerHTML = rrzeXliffJavaScriptData.import_form + nestedPagesTools.innerHTML;
	}
	// Preset-Select-Element zu dem Listen-Header hinzufügen.
	const listHeader = document.querySelector('.nestedpages-list-header');
	if (listHeader && rrzeXliffJavaScriptData.preset_select_markup !== '') {
		listHeader.innerHTML += rrzeXliffJavaScriptData.preset_select_markup;
		const presetsSelect = listHeader.querySelector('#export-presets');
		if (presetsSelect) {
			const npBulkForm = document.querySelector( 'form.np-bulk-form' );
			if ( npBulkForm ) {
				npBulkForm.innerHTML += rrzeXliffJavaScriptData.preset_name_hidden_field;
			}
			const hiddenPresetNameField = document.getElementById( 'rrze-export-preset-name' );
			presetsSelect.addEventListener('change', function(e) {
				const selected = e.target.selectedOptions[0];
				if (!selected) {
					return;
				}

				if (hiddenPresetNameField) {
					hiddenPresetNameField.value = selected.label;
				}

				if (selected.value === '') {
					return;
				}

				// Uncheck all boxes that are checked.
				const bulkCheckboxes = document.querySelectorAll('.np-bulk-checkbox [type="checkbox"]:checked');
				for (let bulkCheckbox of bulkCheckboxes) {
					bulkCheckbox.checked = false;
				}

				// Search Nested Pages list item.
				const matchingListItem = document.getElementById(`menuItem_${selected.value}`);

				// Get the top page row entry for that element.
				let topPageRow = matchingListItem;
				while(topPageRow.parentNode.closest('.page-row')) {
					topPageRow = topPageRow.parentNode.closest('.page-row');
				}

				// Open the tree.
				openAllChildren(topPageRow);

				// Select all children of the dropdown select page.
				checkAllChildren(matchingListItem);
			});
		}
	}

    const nestedPagesBulkSelect = document.querySelector('#np_bulk');
    if (nestedPagesBulkSelect) {
		// Option für XLIFF-Export einfügen.
		const exportOption = document.createElement('option'),
			exportOptionText = document.createTextNode(rrzeXliffJavaScriptData.dropdown_menu_label);

		exportOption.setAttribute('value', 'xliff-export');
		exportOption.appendChild(exportOptionText);
		nestedPagesBulkSelect.appendChild(exportOption);
	}
	
	const viewButtons = document.querySelectorAll('.nestedpages .row .action-buttons .np-view-button');
	if (viewButtons) {
		for (let viewButton of viewButtons) {
			const button = document.createElement('button');

			button.appendChild(document.createTextNode(rrzeXliffJavaScriptData.select_tree_button_label));
			button.classList.add('np-btn', 'hohenheim-select-tree-button');
			viewButton.parentNode.insertBefore(button, viewButton);

			button.addEventListener('click', function(e) {
				// Get parent .row.
				const row = this.closest('.page-row');
				checkAllChildren(row);
				openAllChildren(row);
			});
		}
	}

	/**
	 * Open all child rows from a given row.
	 * 
	 * @param {Node} row 
	 */
	function openAllChildren(row) {
		const childToggles = row.querySelectorAll('.child-toggle a');
		for (let childToggle of childToggles) {
			if (!childToggle.classList.contains('open')) {
				childToggle.click();
			}
		}
	}
	
	/**
	 * Check all children of a given row.
	 * 
	 * @param {Node} row 
	 */
	function checkAllChildren(row) {
		const bulkCheckboxes = row.querySelectorAll('.np-bulk-checkbox [type="checkbox"]');
		for (let bulkCheckbox of bulkCheckboxes) {
			if (bulkCheckbox.checked === false) {
				bulkCheckbox.click();
			}
		}
	}
})();
