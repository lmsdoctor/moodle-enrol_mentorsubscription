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
 *   - "Add mentee" button → ModalFactory dialog with user-ID input → AJAX add_mentee
 *
 * @module     enrol_mentorsubscription/mentor_dashboard
 * @copyright  2026 LMS Doctor <info@lmsdoctor.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import ModalFactory from 'core/modal_factory';
import ModalEvents from 'core/modal_events';
import {get_strings as getStrings} from 'core/str';
import * as Templates from 'core/templates';

/** @type {HTMLElement} Root dashboard element. */
let dashboardRoot = null;

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Initialise the mentor dashboard interactions.
 * Called from dashboard.php via $PAGE->requires->js_call_amd(..., 'init').
 */
export const init = () => {
    dashboardRoot = document.querySelector('[data-region="mentor-dashboard"]');
    if (!dashboardRoot) {
        return;
    }

    bindToggleSwitches();
    bindAddMenteeButton();
};

// ---------------------------------------------------------------------------
// Toggle switch — activate / deactivate a mentee
// ---------------------------------------------------------------------------

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
            args: {menteeid, is_active: isActive},
        }])[0]
        .done((result) => {
            if (!result.success) {
                // Revert the toggle on failure (e.g. limit reached).
                toggle.checked = !toggle.checked;
                updateBadge(toggle, isActive === 1 ? 0 : 1);

                getStrings([
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
 * @param {HTMLInputElement} toggle  The checkbox element.
 * @param {number}           isActive  1 = active, 0 = inactive.
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

    getStrings([
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
 * @param {number} activeCount  New active count.
 * @param {number} maxMentees   Subscription limit.
 */
const updateCounter = (activeCount, maxMentees) => {
    const countEl = dashboardRoot.querySelector('[data-region="active-count"]');
    if (countEl) {
        countEl.textContent = `${activeCount} / ${maxMentees}`;
    }
    const bar = dashboardRoot.querySelector('.progress-bar');
    if (bar && maxMentees > 0) {
        const pct = Math.round((activeCount / maxMentees) * 100);
        bar.style.width = `${pct}%`;
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

// ---------------------------------------------------------------------------
// Add mentee modal
// ---------------------------------------------------------------------------

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
    getStrings([
        {key: 'dashboard_add_mentee', component: 'enrol_mentorsubscription'},
        {key: 'save',                 component: 'core'},
        {key: 'cancel',               component: 'core'},
    ]).then(([title]) => {
        return ModalFactory.create({
            type:  ModalFactory.types.SAVE_CANCEL,
            title,
            body:  buildAddMenteeForm(),
            large: false,
        });
    }).then((modal) => {
        modal.getRoot().on(ModalEvents.save, () => handleAddMenteeSubmit(modal));
        modal.show();
        return modal;
    }).catch(Notification.exception);
};

/**
 * Build a simple inline form HTML for the modal body.
 *
 * @returns {string} HTML string.
 */
const buildAddMenteeForm = () => {
    return `
        <div class="form-group mb-3">
            <label for="mentorsub-new-menteeid" class="form-label">User ID</label>
            <input type="number" min="1"
                   id="mentorsub-new-menteeid"
                   class="form-control"
                   placeholder="e.g. 42"
                   required />
            <div id="mentorsub-add-error" class="invalid-feedback"></div>
        </div>`;
};

/**
 * Submit the "add mentee" AJAX call from the modal.
 *
 * @param {Object} modal  Core modal instance.
 */
const handleAddMenteeSubmit = (modal) => {
    const input   = modal.getRoot()[0].querySelector('#mentorsub-new-menteeid');
    const menteeid = parseInt(input ? input.value : '0', 10);

    if (!menteeid || menteeid <= 0) {
        if (input) {
            input.classList.add('is-invalid');
        }
        return;
    }

    Ajax.call([{
        methodname: 'enrol_mentorsubscription_add_mentee',
        args: {menteeid},
    }])[0]
    .done((result) => {
        if (result.success) {
            modal.hide();
            // Reload page to show new mentee card.
            window.location.reload();
        } else {
            const errDiv = modal.getRoot()[0].querySelector('#mentorsub-add-error');
            if (errDiv) {
                errDiv.textContent = result.message;
            }
            if (input) {
                input.classList.add('is-invalid');
            }
        }
    })
    .fail((ex) => {
        modal.hide();
        Notification.exception(ex);
    });
};
