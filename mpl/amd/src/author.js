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
 * Task authoring assistant for teachers: describe a task, the AI fills the form.
 *
 * The conversation is kept in the hidden "authorchat" field and saved with the
 * activity, so a teacher can come back later and keep changing the task.
 *
 * @module     mod_mpl/author
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {exception as displayException} from 'core/notification';
import {h, clear, loadStrings, fmt, num} from 'mod_mpl/dom';

const KEYS = [
    'au_title', 'au_intro', 'au_placeholder_new', 'au_placeholder_change', 'au_create', 'au_change', 'au_working',
    'au_undo', 'au_undone', 'au_examples', 'au_example1', 'au_example2', 'au_example3', 'au_example4',
    'au_validation_ok', 'au_validation_wrong', 'au_validation_problems', 'au_check', 'au_you', 'au_ai',
    'au_applied', 'au_error', 'au_collapse', 'au_expand', 'au_cost',
];

const call = (methodname, args) => Ajax.call([{methodname, args}])[0];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Initialise the assistant.
 *
 * @param {HTMLFormElement} form
 * @param {Number} contextid
 * @param {Object} api {getState, applyState}
 * @param {String} supportHtml
 */
export const init = async(form, contextid, api, supportHtml) => {
    const store = form.querySelector('textarea[name="authorchat"]');
    if (!store) {
        return;
    }
    const row = store.closest('.fitem');
    if (row) {
        row.hidden = true;
    }
    const S = await loadStrings(KEYS);
    let chat = [];
    try {
        chat = JSON.parse(store.value || '[]');
    } catch (e) {
        chat = [];
    }
    let snapshot = null;
    let busy = false;

    const save = () => {
        store.value = JSON.stringify(chat.slice(-40));
    };
    const hasTask = () => {
        const st = api.getState();
        return !!((st.reference || '').trim() || (st.tests || []).length);
    };

    const log = h('div.mpl-au-log');
    const input = h('textarea.form-control.mpl-au-input', {rows: 3});
    const button = h('button.btn.btn-primary', {type: 'button'});
    const progress = h('div.mpl-au-progress.small.text-muted');
    const examples = h('div.mpl-au-examples', null, [
        h('span.small.text-muted.me-1.mr-1', {text: S.au_examples}),
        ['au_example1', 'au_example2', 'au_example3', 'au_example4'].map((k) =>
            h('button.btn.btn-sm.btn-outline-secondary.me-1.mr-1.mb-1', {type: 'button', onClick: () => {
                input.value = S[k];
                input.focus();
            }}, S[k])),
    ]);

    const validationBadge = (v) => {
        if (!v) {
            return null;
        }
        const parts = [];
        if (v.reference !== null && v.max !== null) {
            parts.push(fmt(S.au_validation_ok, {score: num(v.reference), max: num(v.max)}));
        }
        const caught = (v.wrong || []).filter((w) => w.score < w.max).length;
        if ((v.wrong || []).length) {
            parts.push(fmt(S.au_validation_wrong, {caught, total: v.wrong.length}));
        }
        return h('div.mpl-au-validation', null, [
            h('div', {text: '✔ ' + parts.join(' · ')}),
            (v.wrong || []).length ? h('ul.small.mb-0', null, v.wrong.map((w) =>
                h('li', {text: (w.score < w.max ? '✔ ' : '✖ ') + w.description + ' → ' + num(w.score) + '/' + num(w.max)}))) : null,
        ]);
    };

    const renderLog = () => {
        clear(log);
        chat.forEach((m) => {
            const item = h('div.mpl-au-msg.mpl-au-' + m.role, null, [
                h('div.mpl-au-who', {text: m.role === 'teacher' ? S.au_you : '🪄 ' + S.au_ai}),
                h('div.mpl-au-text', {text: m.content}),
            ]);
            if (m.role === 'assistant') {
                const badge = validationBadge(m.validation);
                if (badge) {
                    item.appendChild(badge);
                }
                if (m.problems && m.problems.length) {
                    item.appendChild(h('div.alert.alert-warning.py-1.px-2.small.mt-1', null, [
                        h('strong', {text: S.au_validation_problems + ' '}), m.problems.join(' · ')]));
                }
            }
            log.appendChild(item);
        });
        log.hidden = !chat.length;
        examples.hidden = chat.length > 0 || hasTask();
        const change = hasTask();
        button.textContent = '🪄 ' + (change ? S.au_change : S.au_create);
        input.placeholder = change ? S.au_placeholder_change : S.au_placeholder_new;
        log.scrollTop = log.scrollHeight;
    };

    const undo = h('button.btn.btn-link.btn-sm', {type: 'button', hidden: true, onClick: () => {
        if (snapshot) {
            api.applyState(snapshot);
            snapshot = null;
            undo.hidden = true;
            progress.textContent = S.au_undone;
        }
    }}, '↶ ' + S.au_undo);

    const run = async() => {
        const text = input.value.trim();
        if (!text || busy) {
            return;
        }
        busy = true;
        button.disabled = true;
        input.disabled = true;
        chat.push({role: 'teacher', content: text});
        save();
        renderLog();
        progress.textContent = S.au_working;
        const spinner = h('span.spinner-border.spinner-border-sm.me-2.mr-2');
        progress.prepend(spinner);
        try {
            const messages = chat.map((m) => ({role: m.role, content: m.content}));
            const start = JSON.parse((await call('mod_mpl_author_start', {
                contextid, messages: JSON.stringify(messages), state: JSON.stringify(api.getState()),
            })).json);
            let job = start;
            const started = Date.now();
            while (job.status === 'running' && Date.now() - started < 15 * 60 * 1000) {
                await sleep(2500);
                job = JSON.parse((await call('mod_mpl_author_poll', {contextid, jobid: start.id})).json);
                clear(progress);
                progress.append(h('span.spinner-border.spinner-border-sm.me-2.mr-2'),
                    (job.progress || []).slice(-1)[0] || S.au_working);
            }
            if (job.status !== 'done') {
                throw new Error(job.error || S.au_error);
            }
            snapshot = api.getState();
            api.applyState(job.result.task);
            chat.push({role: 'assistant', content: job.result.message || S.au_applied,
                validation: job.result.validation, problems: job.result.problems || []});
            input.value = '';
            undo.hidden = false;
            progress.textContent = S.au_applied;
        } catch (e) {
            chat.pop();
            input.value = text;
            clear(progress);
            if (e && e.errorcode) {
                displayException(e);
            } else {
                progress.appendChild(h('div.alert.alert-danger.py-1.px-2', {text: (e && e.message) || S.au_error}));
            }
        } finally {
            save();
            renderLog();
            busy = false;
            button.disabled = false;
            input.disabled = false;
        }
    };
    button.addEventListener('click', run);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            run();
        }
    });

    const body = h('div.mpl-au-body', null, [
        h('p.mb-2', {text: S.au_intro}),
        examples,
        log,
        input,
        h('div.mpl-au-actions', null, [button, undo, h('span.small.text-muted', {text: S.au_cost})]),
        progress,
        h('div.small.text-muted.mt-2', {text: S.au_check}),
        supportHtml ? h('div.mt-1', {html: true}) : null,
    ]);
    if (supportHtml) {
        body.lastChild.innerHTML = supportHtml;
    }
    const panel = h('details.mpl-au', {open: !hasTask() || chat.length > 0}, [
        h('summary.mpl-au-summary', {text: '🪄 ' + S.au_title}),
        body,
    ]);
    form.prepend(panel);
    renderLog();
};
