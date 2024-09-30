// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * User tours configuration.
 *
 * @module      tool_usertours/configurationtour
 * @copyright   2024 The Open University
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
const ANY_VALUE = "__ANYVALUE__";

export const init = () => {
    // Initialize the category filter
    initConfigurationCategoryFilter();
};

/**
 * Initialize the category filter for the configuration page.
 */
const initConfigurationCategoryFilter = () => {
    const categorySelect = document.querySelector("[name='filter_category[]']");
    const excludeSelect = document.querySelector("[name='filter_exclude_category[]']");

    if (categorySelect && excludeSelect) {
        // Add event listeners to update the exclude categories when the include categories change.
        categorySelect.addEventListener("change", () => {
            updateExcludeCategories(categorySelect, excludeSelect);
        });

        // Initialize the exclude categories based on the selected include categories.
        updateExcludeCategories(categorySelect, excludeSelect);
    }
};

/**
 * Adjust the height of a select element based on the number of options.
 *
 * @param {HTMLSelectElement} select
 */
const adjustHeight = (select) => {
    select.size = Math.min(select.options.length || 1, 10);
};

/**
 * Update the exclude categories based on the selected include categories.
 *
 * @param {HTMLSelectElement} categorySelect
 * @param {HTMLSelectElement} excludeSelect
 */
const updateExcludeCategories = (categorySelect, excludeSelect) => {
    // Get the selected categories and update the 'Any' option.
    const selectedCategories = new Set(Array.from(categorySelect.selectedOptions).map(option => option.value));

    // Get the selected exclude categories and create a map of options.
    const excludeSelected = new Set(Array.from(excludeSelect.selectedOptions).map(option => option.value));
    const excludeOptions = new Map();

    Array.from(categorySelect.options)
        .filter(option => option.value !== ANY_VALUE)
        .forEach(option => {
            // Check if the option is a child of any selected category.
            const isChild = Array.from(selectedCategories).some(selected => {
                const selectedOption = categorySelect.querySelector(`option[value="${selected}"]`);
                return option.text.startsWith(selectedOption.text + " / ");
            });

            // Include the option if it's a child of a selected category.
            if (isChild) {
                excludeOptions.set(option.value, option.text);
            }
        });

    // Update the exclude categories select element.
    excludeSelect.innerHTML = Array.from(excludeOptions)
        .sort(([, a], [, b]) => a.localeCompare(b))
        .map(([key, value]) =>
            `<option value="${key}" ${excludeSelected.has(key) ? 'selected' : ''}>${value}</option>`
        ).join('');

    // Adjust the height of the select elements.
    adjustHeight(excludeSelect);
};
