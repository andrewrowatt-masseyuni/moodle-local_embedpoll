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
 * The lines the poll form's options are written in.
 *
 * The form still submits its options as text, one per line, from a hidden textarea. This puts a
 * line for each option in the textarea's place, and writes the lines back into it whenever they
 * change. Lines can be added, up to the most options the poll may have, deleted, and dragged into
 * order by their handles. Pressing Enter or Space on a handle offers a list of places to move the
 * line to instead.
 *
 * @module     local_embedpoll/options_editor
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import $ from 'jquery';
import Notification from 'core/notification';
import Pending from 'core/pending';
import SortableList from 'core/sortable_list';
import {getStrings} from 'core/str';
import Templates from 'core/templates';

const component = 'local_embedpoll';

/** @type {number} How many lines a new poll starts with. */
const INITIAL_LINES = 3;

const SELECTORS = {
    editor: '[data-region="local_embedpoll-options"]',
    list: '[data-region="local_embedpoll-optionlist"]',
    line: '[data-region="local_embedpoll-option"]',
    label: '[data-region="local_embedpoll-optionlabel"]',
    handle: '[data-drag-type="move"]',
    add: '[data-action="local_embedpoll-addoption"]',
    full: '[data-region="local_embedpoll-maxoptions"]',
    remove: '[data-action="local_embedpoll-deleteoption"]',
};

/** @type {WeakMap<HTMLElement, {textarea: HTMLTextAreaElement, fieldLabels: HTMLLabelElement[], max: number}>} */
const editors = new WeakMap();

/** @type {SortableList|null} The one that serves every form opened on this page. */
let sortable = null;

/**
 * The lines, in order.
 *
 * While a line is dragged, SortableList adds a copy of it to the list, to follow the pointer. The
 * copy is not a line.
 *
 * @param {HTMLElement} editor
 * @returns {HTMLElement[]}
 */
const getLines = (editor) => [...editor.querySelector(SELECTORS.list).children]
    .filter((line) => line.matches(SELECTORS.line) && !line.classList.contains('sortable-list-is-dragged'));

/**
 * The option a line holds, or its number when it is blank.
 *
 * @param {HTMLElement} line
 * @returns {string}
 */
const lineName = (line) => {
    const input = line.querySelector(SELECTORS.label);
    return input.value.trim() || input.placeholder;
};

/**
 * Write the lines back into the textarea, one per line.
 *
 * When the form comes back with an error, the browser drops the newline straight after <textarea>,
 * so the text starts with one to spare. Without it, a blank first line would be lost, and every
 * option would come back a line higher than it was written. The server ignores blank lines.
 *
 * @param {HTMLElement} editor
 */
const write = (editor) => {
    editors.get(editor).textarea.value = '\n' + getLines(editor)
        .map((line) => line.querySelector(SELECTORS.label).value)
        .join('\n');
};

/**
 * Bring everything that depends on the lines up to date with them.
 *
 * @param {HTMLElement} editor
 */
const update = async(editor) => {
    const {textarea, fieldLabels, max} = editors.get(editor);
    const lines = getLines(editor);

    write(editor);

    const full = lines.length >= max;
    editor.querySelector(SELECTORS.add).hidden = full;
    editor.querySelector(SELECTORS.full).hidden = !full;

    const pending = new Pending('local_embedpoll/options_editor:update');
    let strings;
    try {
        strings = await getStrings(lines.flatMap((line, index) => ['optionn', 'moveoption', 'deleteoption']
            .map((key) => ({key, component, param: index + 1}))));
    } catch (error) {
        Notification.exception(error);
        pending.resolve();
        return;
    }

    // Lines may have come or gone while the strings were fetched. Whatever changed them updates
    // again, so a line no longer in the list is left alone.
    lines.forEach((line, index) => {
        if (!line.isConnected) {
            return;
        }
        const [option, move, remove] = strings.slice(index * 3, index * 3 + 3);

        const input = line.querySelector(SELECTORS.label);
        input.id = `${textarea.id}_${index + 1}`;
        input.placeholder = option;
        input.setAttribute('aria-label', option);
        // Points at the form's error for the options, when there is one.
        const describedby = textarea.getAttribute('aria-describedby');
        if (describedby) {
            input.setAttribute('aria-describedby', describedby);
        }

        const handle = line.querySelector(SELECTORS.handle);
        handle.title = move;
        handle.setAttribute('aria-label', move);

        const button = line.querySelector(SELECTORS.remove);
        button.title = remove;
        button.setAttribute('aria-label', remove);
    });

    // The field's label belonged to the textarea. Clicking it now goes to the first line.
    if (lines.length && lines[0].isConnected) {
        fieldLabels.forEach((label) => {
            label.htmlFor = lines[0].querySelector(SELECTORS.label).id;
        });
    }

    pending.resolve();
};

/**
 * Add a blank line at the end, if the poll may have another option, and go to it.
 *
 * @param {HTMLElement} editor
 */
const addLine = async(editor) => {
    const {max} = editors.get(editor);
    if (getLines(editor).length >= max) {
        return;
    }

    const pending = new Pending('local_embedpoll/options_editor:add');
    try {
        const {html, js} = await Templates.renderForPromise('local_embedpoll/options_editor_line', {label: ''});
        // Another click may have added a line while this one was rendered.
        if (getLines(editor).length < max) {
            const list = editor.querySelector(SELECTORS.list);
            Templates.appendNodeContents(list, html, js);
            const line = list.lastElementChild;
            await update(editor);
            line.querySelector(SELECTORS.label).focus();
        }
    } catch (error) {
        Notification.exception(error);
    }
    pending.resolve();
};

/**
 * Delete a line, and go to the line that takes its place.
 *
 * The last line left is emptied rather than deleted, so that there is always somewhere to type.
 *
 * @param {HTMLElement} editor
 * @param {HTMLElement} line
 */
const deleteLine = (editor, line) => {
    const lines = getLines(editor);
    if (lines.length === 1) {
        const input = line.querySelector(SELECTORS.label);
        input.value = '';
        input.focus();
        update(editor);
        return;
    }

    const index = lines.indexOf(line);
    line.remove();
    const next = getLines(editor)[Math.min(index, lines.length - 2)];
    next.querySelector(SELECTORS.label).focus();
    update(editor);
};

/**
 * Make every list of lines on the page sortable.
 *
 * SortableList listens on the body for as long as the page lives, and finds its list by selector
 * when a drag starts. So one serves every form opened on the page, where a new one for each form
 * would leave another listener behind every time.
 */
const makeSortable = () => {
    if (sortable) {
        return;
    }

    sortable = new SortableList(SELECTORS.list);
    // For the list of places to move a line to, offered from the keyboard.
    sortable.getElementName = (element) => Promise.resolve(lineName(element[0]));

    $(document).on(SortableList.EVENTS.DROP, SELECTORS.line, (event) => {
        const editor = event.currentTarget.closest(SELECTORS.editor);
        if (editor && editors.has(editor)) {
            update(editor);
        }
    });
};

/**
 * Handle a click on Add option, or on a line's delete button.
 *
 * @param {HTMLElement} editor
 * @param {Event} event
 */
const handleClick = (editor, event) => {
    if (event.target.closest(SELECTORS.add)) {
        addLine(editor);
        return;
    }

    const remove = event.target.closest(SELECTORS.remove);
    if (remove) {
        deleteLine(editor, remove.closest(SELECTORS.line));
    }
};

/**
 * Handle Enter in a line.
 *
 * Enter would submit the form. In the textarea these lines replace, it started a new line, so it
 * goes to the next line instead, adding one at the end if the poll may have another option.
 *
 * @param {HTMLElement} editor
 * @param {KeyboardEvent} event
 */
const handleKeydown = (editor, event) => {
    if (event.key !== 'Enter' || event.isComposing || !event.target.matches(SELECTORS.label)) {
        return;
    }
    event.preventDefault();

    const lines = getLines(editor);
    const next = lines[lines.indexOf(event.target.closest(SELECTORS.line)) + 1];
    if (next) {
        next.querySelector(SELECTORS.label).focus();
    } else {
        addLine(editor);
    }
};

/**
 * Put lines in place of the options textarea.
 *
 * @param {string} id The textarea's data-local-embedpoll-options attribute.
 * @param {number} max The most options this poll may have.
 */
export const init = async(id, max) => {
    const textarea = document.querySelector(`textarea[data-local-embedpoll-options="${id}"]`);
    if (!textarea) {
        return;
    }

    const pending = new Pending('local_embedpoll/options_editor:init');
    makeSortable();

    const options = textarea.value.split(/\r\n|\r|\n/);
    while (options.length < Math.min(INITIAL_LINES, max)) {
        options.push('');
    }

    let html;
    let js;
    try {
        ({html, js} = await Templates.renderForPromise('local_embedpoll/options_editor', {
            lines: options.map((label) => ({label})),
        }));
    } catch (error) {
        Notification.exception(error);
        pending.resolve();
        return;
    }

    textarea.insertAdjacentHTML('afterend', html);
    Templates.runTemplateJS(js);
    const editor = textarea.nextElementSibling;
    editors.set(editor, {textarea, fieldLabels: [...textarea.labels], max});

    editor.addEventListener('click', (event) => handleClick(editor, event));
    editor.addEventListener('keydown', (event) => handleKeydown(editor, event));
    editor.addEventListener('input', () => write(editor));

    await update(editor);

    // The form came back with an error in its options, and would have focused the textarea.
    if (textarea.hasAttribute('autofocus')) {
        getLines(editor)[0].querySelector(SELECTORS.label).focus();
    }

    pending.resolve();
};
