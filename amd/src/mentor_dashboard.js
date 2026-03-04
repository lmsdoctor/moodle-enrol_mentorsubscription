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
 * AMD module: mentor_dashboard
 *
 * Drives all interactive behaviour on the mentor dashboard:
 *   - Toggle switch → AJAX call to toggle_mentee_status → update badge + counter
 *   - "Add mentee" button → ModalFactory dialog with autocomplete search → AJAX add_mentee
 *
 * Written in AMD define() format so it works both when Moodle serves amd/src
 * directly (developer / cachejs=false mode) and when it serves amd/build.
 *
 * @module     enrol_mentorsubscription/mentor_dashboard
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(
'enrol_mentorsubscription/mentor_dashboard',
['core/ajax', 'core/notification', 'core/modal_factory', 'core/modal_events', 'core/str'],
function(Ajax, Notification, ModalFactory, ModalEvents, Str) {

    /** @type {HTMLElement} Root dashboard element. */
    let dashboardRoot = null;

    // -----------------------------------------------------------------------
    // Toggle switch — activate / deactivate a mentee
    // -----------------------------------------------------------------------

    const bindToggleSwitches = () => {
        dashboardRoot.addEventListener('change', (e) => {
            const toggle = e.target.closest('.enrol-mentorsub-status-toggle');
            if (!toggle) {
                return;
            }

            const menteeid = parseInt(toggle.dataset.menteeid, 10);
            const isActive = toggle.checked ? 1 : 0;

            // Optimistically update badge while the request is in flight.
            updateBadge(toggle, isActive);

            Ajax.call([{
                methodname: 'enrol_mentorsubscription_toggle_mentee_status',
                args: {menteeid: menteeid, is_active: isActive},
            }])[0]
            .done((result) => {
                if (!result.success) {
                    // Revert the toggle on failure (e.g. limit reached).
                    toggle.checked = !toggle.checked;
                    updateBadge(toggle, isActive === 1 ? 0 : 1);

                    Str.get_strings([
                        {key: 'error_limit_reached', component: 'enrol_mentorsubscription'},
                    ]).then(([limitMsg]) => {
                        Notification.alert('', limitMsg);
                        return null;
                    }).catch(Notification.exception);
                } else {
                    updateCounter(result.active_count, result.max_mentees);
                }
            })
            .fail((ex) => {
                // Revert on network error.
                toggle.checked = !toggle.checked;
                updateBadge(toggle, isActive === 1 ? 0 : 1);
                Notification.exception(ex);
            });
        });
    };

    /**
     * Update the active/inactive status badge next to a mentee toggle.
     *
     * @param {HTMLInputElement} toggle   The checkbox element.
     * @param {number}           isActive 1 = active, 0 = inactive.
     */
    const updateBadge = (toggle, isActive) => {
        const card  = toggle.closest('[data-menteeid]');
        if (!card) {
            return;
        }
        const badge = card.querySelector('.badge');
        if (!badge) {
            return;
        }

        Str.get_strings([
            {key: 'mentee_active',   component: 'enrol_mentorsubscription'},
            {key: 'mentee_inactive', component: 'enrol_mentorsubscription'},
        ]).then(([activeStr, inactiveStr]) => {
            if (isActive) {
                badge.textContent = activeStr;
                badge.classList.replace('badge-secondary', 'badge-success');
            } else {
                badge.textContent = inactiveStr;
                badge.classList.replace('badge-success', 'badge-secondary');
            }
            return null;
        }).catch(Notification.exception);
    };

    /**
     * Update the active mentee counter in the subscription summary card.
     *
     * @param {number} activeCount New active count.
     * @param {number} maxMentees  Subscription limit.
     */
    const updateCounter = (activeCount, maxMentees) => {
        const countEl = dashboardRoot.querySelector('[data-region="active-count"]');
        if (countEl) {
            countEl.textContent = activeCount + ' / ' + maxMentees;
        }
        const bar = dashboardRoot.querySelector('.progress-bar');
        if (bar && maxMentees > 0) {
            const pct = Math.round((activeCount / maxMentees) * 100);
            bar.style.width = pct + '%';
            bar.setAttribute('aria-valuenow', activeCount);
            bar.classList.toggle('bg-danger',  activeCount >= maxMentees);
            bar.classList.toggle('bg-success', activeCount <  maxMentees);
        }
        // Show/hide the limit-reached card.
        const limitCard = dashboardRoot.querySelector('[data-region="limit-reached-card"]');
        if (limitCard) {
            limitCard.classList.toggle('d-none', activeCount < maxMentees);
        }
        // Show/hide the "Add mentee" button.
        const addBtn = dashboardRoot.querySelector('[data-action="add-mentee"]');
        if (addBtn) {
            addBtn.classList.toggle('d-none', activeCount >= maxMentees);
        }
    };

    // -----------------------------------------------------------------------
    // Add mentee modal — user-search autocomplete
    // -----------------------------------------------------------------------

    /** Debounce timer for the search input. */
    let searchTimeout = null;

    const bindAddMenteeButton = () => {
        dashboardRoot.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-action="add-mentee"]');
            if (!btn) {
                return;
            }
            e.preventDefault();
            openAddMenteeModal();
        });
    };

    const openAddMenteeModal = () => {
        Str.get_strings([
            {key: 'dashboard_add_mentee',               component: 'enrol_mentorsubscription'},
            {key: 'dashboard_mentee_search_placeholder', component: 'enrol_mentorsubscription'},
            {key: 'dashboard_mentee_noresults',          component: 'enrol_mentorsubscription'},
            {key: 'dashboard_mentee_select_required',    component: 'enrol_mentorsubscription'},
        ]).then(([title, placeholder, noResults, selectRequired]) => {
            return ModalFactory.create({
                type:  ModalFactory.types.SAVE_CANCEL,
                title: title,
                body:  buildAddMenteeForm(placeholder),
                large: false,
            }).then((modal) => {
                modal.getRoot().on(ModalEvents.save, () => handleAddMenteeSubmit(modal, selectRequired));
                modal.show();
                setupMenteeSearch(modal, noResults);
                return modal;
            });
        }).catch(Notification.exception);
    };

    /**
     * Build the modal body HTML containing the mentee search form.
     *
     * @param {string} placeholder Localised placeholder for the search input.
     * @returns {string} HTML string.
     */
    const buildAddMenteeForm = (placeholder) => {
        return '<div class="form-group mb-3" style="position:relative;">' +
            '<input type="text" id="mentorsub-mentee-search" class="form-control"' +
            ' placeholder="' + placeholder + '" autocomplete="off" />' +
            '<input type="hidden" id="mentorsub-new-menteeid" />' +
            '<ul id="mentorsub-search-results" class="list-group mt-1"' +
            ' style="position:absolute;width:100%;z-index:9999;max-height:220px;overflow-y:auto;"></ul>' +
            '<div id="mentorsub-add-error" class="text-danger small mt-1 d-none"></div>' +
            '</div>';
    };

    /**
     * Wire up the live-search behaviour inside the add-mentee modal.
     *
     * @param {Object} modal     Core modal instance.
     * @param {string} noResults Localised "no users found" string.
     */
    const setupMenteeSearch = (modal, noResults) => {
        const root        = modal.getRoot()[0];
        const searchInput = root.querySelector('#mentorsub-mentee-search');
        const idInput     = root.querySelector('#mentorsub-new-menteeid');
        const resultsList = root.querySelector('#mentorsub-search-results');

        if (!searchInput) {
            return;
        }

        searchInput.addEventListener('input', () => {
            idInput.value = ''; // Reset selection when user edits the query.
            const q = searchInput.value.trim();
            clearTimeout(searchTimeout);

            if (q.length < 2) {
                resultsList.innerHTML = '';
                return;
            }

            searchTimeout = setTimeout(
                () => runUserSearch(q, resultsList, searchInput, idInput, noResults),
                300
            );
        });
    };

    /**
     * Execute the AJAX user search and render the dropdown results.
     *
     * @param {string}      q           Search query (>= 2 chars).
     * @param {HTMLElement} resultsList <ul> element to populate.
     * @param {HTMLElement} searchInput Text input for the query.
     * @param {HTMLElement} idInput     Hidden input storing the selected user ID.
     * @param {string}      noResults   Localised "no users found" label.
     */
    const runUserSearch = (q, resultsList, searchInput, idInput, noResults) => {
        Ajax.call([{
            methodname: 'enrol_mentorsubscription_search_users',
            args: {query: q},
        }])[0]
        .done((users) => {
            resultsList.innerHTML = '';

            if (!users.length) {
                const empty = document.createElement('li');
                empty.className   = 'list-group-item text-muted py-2 small';
                empty.textContent = noResults;
                resultsList.appendChild(empty);
                return;
            }

            users.forEach((user) => {
                const item = document.createElement('li');
                item.className    = 'list-group-item list-group-item-action py-2 small';
                item.style.cursor = 'pointer';
                item.textContent  = user.label;

                // mousedown fires before the input's blur event so the dropdown
                // stays open long enough for the selection to register.
                item.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    idInput.value     = user.value;
                    searchInput.value = user.label;
                    searchInput.classList.remove('is-invalid');
                    resultsList.innerHTML = '';
                });

                resultsList.appendChild(item);
            });
        })
        .fail(Notification.exception);
    };

    /**
     * Submit the selected mentee via the add_mentee AJAX endpoint.
     *
     * @param {Object} modal          Core modal instance.
     * @param {string} selectRequired Localised "please select a user" error text.
     */
    const handleAddMenteeSubmit = (modal, selectRequired) => {
        const root        = modal.getRoot()[0];
        const idInput     = root.querySelector('#mentorsub-new-menteeid');
        const searchInput = root.querySelector('#mentorsub-mentee-search');
        const errDiv      = root.querySelector('#mentorsub-add-error');
        const menteeid    = parseInt(idInput ? idInput.value : '0', 10);

        if (!menteeid || menteeid <= 0) {
            if (searchInput) {
                searchInput.classList.add('is-invalid');
            }
            if (errDiv) {
                errDiv.textContent = selectRequired;
                errDiv.classList.remove('d-none');
            }
            return;
        }

        Ajax.call([{
            methodname: 'enrol_mentorsubscription_add_mentee',
            args: {menteeid: menteeid},
        }])[0]
        .done((result) => {
            if (result.success) {
                modal.hide();
                window.location.reload();
            } else {
                if (errDiv) {
                    errDiv.textContent = result.message;
                    errDiv.classList.remove('d-none');
                }
            }
        })
        .fail((ex) => {
            modal.hide();
            Notification.exception(ex);
        });
    };

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    return {
        /**
         * Initialise the mentor dashboard interactions.
         * Called from dashboard/index.php via $PAGE->requires->js_call_amd(..., 'init').
         */
        init: () => {
            dashboardRoot = document.querySelector('[data-region="mentor-dashboard"]');
            if (!dashboardRoot) {
                return;
            }
            bindToggleSwitches();
            bindAddMenteeButton();
        },
    };
});
