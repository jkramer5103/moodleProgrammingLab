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
 * Data files of a task in the settings form (e.g. a CSV the program reads).
 *
 * The files live in the draft area of the form (hidden field "datafiles") and are saved
 * with the form. The task assistant uses the same API to create files or to ask the
 * teacher for an upload.
 *
 * @module     mod_mpl/datafiles
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {exception as displayException} from 'core/notification';
import {h, clear, loadStrings, fmt, icon} from 'mod_mpl/dom';

const KEYS = ['df_intro', 'df_upload', 'df_drop', 'df_newtext', 'df_newname', 'df_newcontent', 'df_create', 'df_cancel',
    'df_delete', 'df_deleteconfirm', 'df_preview', 'df_binary', 'df_none', 'df_toolarge', 'df_uploading', 'df_replace',
    'df_limits'];

const call = (methodname, args) => Ajax.call([{methodname, args}])[0];

/**
 * Bytes (ArrayBuffer or Uint8Array) to base64.
 *
 * @param {ArrayBuffer|Uint8Array} buffer
 * @returns {String}
 */
const toBase64 = (buffer) => {
    const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i += 0x8000) {
        binary += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    }
    return window.btoa(binary);
};

const sizeText = (n) => (n < 1024 ? n + ' B' : (n < 1048576 ? (n / 1024).toFixed(1) + ' KB' : (n / 1048576).toFixed(1) + ' MB'));

/**
 * Initialise the widget.
 *
 * @param {HTMLFormElement} form
 * @param {Number} contextid
 * @param {Object} limits {maxfile, maxtotal, maxcount}
 * @returns {Promise<Object|null>} API: list(), putText(name, text), putFile(file), remove(name), onChange(fn), draftitemid
 */
export const init = async(form, contextid, limits = {}) => {
    const root = form.querySelector('[data-region="mpl-datafiles"]');
    const hidden = form.querySelector('input[name="datafiles"]');
    if (!root || !hidden) {
        return null;
    }
    const S = await loadStrings(KEYS);
    const draftitemid = parseInt(hidden.value, 10) || 0;
    let files = [];
    const listeners = [];
    const open = new Set();

    const changed = () => listeners.forEach((fn) => fn(files));

    const refresh = async() => {
        files = JSON.parse((await call('mod_mpl_datafile_list', {contextid, draftitemid})).json);
        render();
        changed();
    };

    const put = async(name, base64) => {
        files = JSON.parse((await call('mod_mpl_datafile_put', {contextid, draftitemid, filename: name,
            content: base64})).json);
        render();
        changed();
        return files;
    };

    const api = {
        draftitemid,
        list: () => files,
        onChange: (fn) => listeners.push(fn),
        putText: (name, text) => put(name, toBase64(new TextEncoder().encode(String(text)))),
        putFile: async(file) => {
            if (limits.maxfile && file.size > limits.maxfile) {
                throw new Error(fmt(S.df_toolarge, {name: file.name, max: sizeText(limits.maxfile)}));
            }
            const buffer = await file.arrayBuffer();
            return put(file.name, toBase64(buffer));
        },
        remove: async(name) => {
            files = JSON.parse((await call('mod_mpl_datafile_delete', {contextid, draftitemid, filename: name})).json);
            render();
            changed();
        },
    };

    const status = h('div.small.mpl-df-status');
    const upload = async(list) => {
        for (const file of Array.from(list || [])) {
            status.textContent = fmt(S.df_uploading, file.name);
            try {
                await api.putFile(file);
                status.textContent = '';
            } catch (e) {
                status.textContent = '';
                if (e && e.errorcode) {
                    displayException(e);
                } else {
                    status.appendChild(h('div.alert.alert-danger.py-1.px-2', {text: (e && e.message) || String(e)}));
                }
            }
        }
    };
    const picker = h('input', {type: 'file', multiple: true, hidden: true, onChange: (e) => {
        upload(e.target.files);
        e.target.value = '';
    }});
    let newForm = null;

    const render = () => {
        clear(root);
        root.appendChild(h('div.small.text-muted.mb-2', {text: S.df_intro}));
        const list = h('div.mpl-df-list');
        if (!files.length) {
            list.appendChild(h('div.small.text-muted', {text: S.df_none}));
        }
        files.forEach((f) => {
            const isOpen = open.has(f.name);
            list.appendChild(h('div.mpl-file', null, [
                h('div.mpl-file-head', null, [
                    icon('file'), h('strong.mpl-tb-mono', {text: f.name}), h('span.small.text-muted', {text: f.sizetext}),
                    h('span.mpl-toolbar-spacer'),
                    f.text ? h('button.btn.btn-sm.btn-link', {type: 'button', onClick: () => {
                        if (isOpen) {
                            open.delete(f.name);
                        } else {
                            open.add(f.name);
                        }
                        render();
                    }}, S.df_preview) : h('span.small.text-muted', {text: S.df_binary}),
                    h('button.btn.btn-sm.mpl-iconbtn.btn-outline-danger', {type: 'button', title: S.df_delete,
                        'aria-label': S.df_delete, onClick: async() => {
                            // eslint-disable-next-line no-alert
                            if (window.confirm(fmt(S.df_deleteconfirm, f.name))) {
                                try {
                                    await api.remove(f.name);
                                } catch (e) {
                                    displayException(e);
                                }
                            }
                        }}, icon('trash')),
                ]),
                isOpen && f.text ? h('pre.mpl-pre.mpl-file-preview', {text: f.preview || ''}) : null,
            ]));
        });
        root.appendChild(list);
        const drop = h('div.mpl-df-drop', null, [
            h('button.btn.btn-sm.btn-outline-primary', {type: 'button', onClick: () => picker.click()},
                [icon('upload'), S.df_upload]),
            h('button.btn.btn-sm.btn-outline-secondary', {type: 'button', onClick: () => {
                newForm = newForm ? null : {name: '', content: ''};
                render();
            }}, [icon('plus'), S.df_newtext]),
            h('span.small.text-muted', {text: S.df_drop}),
        ]);
        ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => {
            e.preventDefault();
            drop.classList.add('mpl-df-over');
        }));
        ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('mpl-df-over')));
        drop.addEventListener('drop', (e) => {
            e.preventDefault();
            upload(e.dataTransfer.files);
        });
        root.appendChild(drop);
        if (newForm) {
            const name = h('input.form-control.form-control-sm.mpl-tb-mono', {type: 'text', placeholder: S.df_newname,
                value: newForm.name, onInput: (e) => {
                    newForm.name = e.target.value;
                }});
            const content = h('textarea.form-control.form-control-sm.mpl-tb-mono', {rows: 6, spellcheck: 'false',
                placeholder: S.df_newcontent, onInput: (e) => {
                    newForm.content = e.target.value;
                }});
            content.value = newForm.content;
            root.appendChild(h('div.mpl-df-new', null, [name, content, h('div.mpl-fb-actions', null, [
                h('button.btn.btn-sm.btn-primary', {type: 'button', onClick: async() => {
                    if (!newForm.name.trim()) {
                        name.focus();
                        return;
                    }
                    try {
                        await api.putText(newForm.name.trim(), newForm.content);
                        newForm = null;
                        render();
                    } catch (e) {
                        displayException(e);
                    }
                }}, S.df_create),
                h('button.btn.btn-sm.btn-link', {type: 'button', onClick: () => {
                    newForm = null;
                    render();
                }}, S.df_cancel),
            ])]));
        }
        root.appendChild(status);
        if (limits.maxtotal) {
            root.appendChild(h('div.small.text-muted', {text: fmt(S.df_limits, {file: sizeText(limits.maxfile),
                total: sizeText(limits.maxtotal), count: limits.maxcount})}));
        }
        root.appendChild(picker);
    };

    render();
    if (draftitemid) {
        try {
            await refresh();
        } catch (e) {
            displayException(e);
        }
    }
    return api;
};
