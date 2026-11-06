// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Teacher feedback on a submission: a general comment and comments below code lines.
 *
 * Teachers click a line number to comment on that line; students see the comments
 * in the read-only code of their attempt.
 *
 * @module     mod_mpl/feedback
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {exception as displayException} from 'core/notification';
import {createEditor} from 'mod_mpl/codemirror';
import {h, clear, loadStrings, fmt, icon} from 'mod_mpl/dom';

const KEYS = ['fb_title', 'fb_general', 'fb_generalplaceholder', 'fb_lineplaceholder', 'fb_save', 'fb_cancel', 'fb_edit',
    'fb_delete', 'fb_deleteconfirm', 'fb_line', 'fb_none', 'fb_saved', 'fb_count'];

/**
 * Initialise the feedback view.
 *
 * @param {HTMLElement} box container above the code (general comment)
 * @param {HTMLElement} editorEl container of the code editor
 * @param {Object} config {code, cmid, submissionid, feedback, canfeedback}
 */
export const init = async(box, editorEl, config) => {
    const S = await loadStrings(KEYS);
    const canEdit = !!config.canfeedback;
    let feedback = config.feedback || [];
    let editing = null; // {line, id} of the comment being written
    let editor = null;

    const save = async(id, line, comment) => {
        try {
            const res = await Ajax.call([{methodname: 'mod_mpl_feedback_save', args: {cmid: config.cmid,
                submissionid: config.submissionid, feedbackid: id || 0, line, comment}}])[0];
            feedback = JSON.parse(res.json);
            editing = null;
            render();
            return true;
        } catch (e) {
            displayException(e);
            return false;
        }
    };

    const form = (line, item) => {
        const area = h('textarea.form-control.form-control-sm', {rows: line ? 2 : 3,
            placeholder: line ? S.fb_lineplaceholder : S.fb_generalplaceholder});
        area.value = item ? item.comment : '';
        const ok = h('button.btn.btn-sm.btn-primary', {type: 'button', onClick: async() => {
            ok.disabled = true;
            if (!await save(item ? item.id : 0, line, area.value)) {
                ok.disabled = false;
            }
        }}, S.fb_save);
        const cancel = h('button.btn.btn-sm.btn-link', {type: 'button', onClick: () => {
            editing = null;
            render();
        }}, S.fb_cancel);
        setTimeout(() => area.focus(), 0);
        area.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                ok.click();
            } else if (e.key === 'Escape') {
                cancel.click();
            }
        });
        return h('div.mpl-fb-form', null, [area, h('div.mpl-fb-actions', null, [ok, cancel])]);
    };

    const card = (item) => {
        if (editing && editing.id === item.id) {
            return h('div.mpl-fb-card', null, form(item.line, item));
        }
        return h('div.mpl-fb-card', null, [
            h('div.mpl-fb-head', null, [icon('comment'), h('span', {text: item.author + ' · ' + item.time}),
                h('span.mpl-toolbar-spacer'),
                canEdit ? h('button.btn.btn-sm.btn-link.p-0.me-2.mr-2', {type: 'button', title: S.fb_edit, onClick: () => {
                    editing = {id: item.id, line: item.line};
                    render();
                }}, icon('pencil')) : null,
                canEdit ? h('button.btn.btn-sm.btn-link.p-0.text-danger', {type: 'button', title: S.fb_delete,
                    onClick: () => {
                        // eslint-disable-next-line no-alert
                        if (window.confirm(S.fb_deleteconfirm)) {
                            save(item.id, item.line, '');
                        }
                    }}, icon('trash')) : null]),
            h('div.mpl-fb-text', {text: item.comment}),
        ]);
    };

    const render = () => {
        // General comments above the code.
        clear(box);
        const general = feedback.filter((f) => !f.line);
        const lines = feedback.filter((f) => f.line);
        if (general.length || canEdit || lines.length) {
            box.appendChild(h('div.mpl-fb-title', null, [icon('comment'), S.fb_title,
                lines.length ? h('span.small.text-muted.ms-2.ml-2', {text: fmt(S.fb_count, lines.length)}) : null]));
        }
        general.forEach((f) => box.appendChild(card(f)));
        if (canEdit) {
            if (editing && editing.line === 0 && !editing.id) {
                box.appendChild(form(0, null));
            } else if (!general.length) {
                box.appendChild(h('button.btn.btn-sm.btn-outline-primary', {type: 'button', onClick: () => {
                    editing = {line: 0, id: 0};
                    render();
                }}, [icon('plus'), S.fb_general]));
            }
        } else if (!general.length && !lines.length) {
            box.hidden = true;
        }

        // Comments below the code lines.
        const byLine = {};
        lines.forEach((f) => {
            (byLine[f.line] = byLine[f.line] || []).push(f);
        });
        if (editing && editing.line && !editing.id) {
            byLine[editing.line] = byLine[editing.line] || [];
        }
        const widgets = Object.keys(byLine).map((line) => {
            const list = byLine[line];
            const dom = h('div.mpl-fb-line', null, [
                list.map(card),
                editing && editing.line === Number(line) && !editing.id ? h('div.mpl-fb-card', null, form(Number(line), null))
                    : null,
            ]);
            const key = JSON.stringify([line, list.map((f) => [f.id, f.comment, f.time]), editing]);
            return {line: Number(line), key, dom};
        });
        editor.setWidgets(widgets);
    };

    editor = createEditor(editorEl, {
        doc: config.code,
        readOnly: true,
        onLineClick: canEdit ? (line) => {
            if (!editing) {
                editing = {line, id: 0};
                render();
            }
        } : null,
    });
    if (canEdit) {
        editorEl.classList.add('mpl-fb-editable');
        editorEl.title = S.fb_line;
    }
    render();
};
