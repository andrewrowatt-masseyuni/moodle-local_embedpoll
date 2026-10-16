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
 * Voting in embedded polls on the page.
 *
 * @module     local_embedpoll/poll
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import Notification from 'core/notification';

const SEL_WIDGET = '[data-region="filter-embedpoll"]';
const SEL_OPTION = '[data-action="poll-vote"]';

let initialised = false;

/**
 * Initialise click delegation for every poll widget on the page.
 *
 * Delegation is bound to the document so re-rendered widgets keep working.
 */
export const init = () => {
    if (initialised) {
        return;
    }
    initialised = true;
    document.addEventListener('click', handleClick);
};

/**
 * Handle clicks on a poll option, recording the vote and revealing the results.
 *
 * @param {Event} e The click event.
 */
const handleClick = async(e) => {
    const btn = e.target.closest(SEL_OPTION);
    if (!btn || btn.disabled) {
        return;
    }

    const widget = btn.closest(SEL_WIDGET);
    if (!widget) {
        return;
    }

    const pollid = parseInt(widget.dataset.pollid, 10);
    const optionid = parseInt(btn.dataset.optionid, 10);
    if (Number.isNaN(pollid) || Number.isNaN(optionid)) {
        return;
    }

    // Prevent double submissions while the request is in flight.
    const buttons = widget.querySelectorAll(SEL_OPTION);
    buttons.forEach(b => {
        b.disabled = true;
    });

    try {
        const data = await Ajax.call([{
            methodname: 'local_embedpoll_vote',
            args: {pollid, optionid},
        }])[0];

        const {html, js} = await Templates.renderForPromise('local_embedpoll/poll', data);
        await Templates.replaceNode(widget, html, js);

        // Restore keyboard focus to the chosen option in the re-rendered widget,
        // so keyboard and screen reader users are not dropped to the top of the
        // page and hear the option's updated pressed state and result.
        const refreshed = document.querySelector(`${SEL_WIDGET}[data-pollid="${pollid}"]`);
        const chosen = refreshed && refreshed.querySelector(`${SEL_OPTION}[data-optionid="${optionid}"]`);
        if (chosen) {
            chosen.focus();
        }
    } catch (err) {
        buttons.forEach(b => {
            b.disabled = false;
        });
        Notification.exception(err);
    }
};
