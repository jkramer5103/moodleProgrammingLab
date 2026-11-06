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
 * CodeMirror 6 bundle (Python) for mod_mpl. Built with tooling/ (rollup).
 *
 * @module     mod_mpl/codemirror
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {EditorState, Compartment, StateField, StateEffect, RangeSetBuilder} from '@codemirror/state';
import {
    EditorView, keymap, lineNumbers, highlightActiveLine, highlightActiveLineGutter,
    drawSelection, highlightSpecialChars, rectangularSelection, placeholder as placeholderExt,
    Decoration, WidgetType,
} from '@codemirror/view';
import {defaultKeymap, history, historyKeymap, indentWithTab} from '@codemirror/commands';
import {
    indentOnInput, bracketMatching, foldGutter, foldKeymap, syntaxHighlighting,
    defaultHighlightStyle, indentUnit,
} from '@codemirror/language';
import {closeBrackets, closeBracketsKeymap, autocompletion, completionKeymap} from '@codemirror/autocomplete';
import {searchKeymap, highlightSelectionMatches} from '@codemirror/search';
import {python} from '@codemirror/lang-python';

const theme = EditorView.theme({
    '&': {fontSize: '14px', border: '1px solid #ced4da', borderRadius: '6px', backgroundColor: '#fff'},
    '&.cm-focused': {outline: '2px solid rgba(15, 108, 191, 0.35)'},
    '.cm-scroller': {fontFamily: 'SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace', lineHeight: '1.5'},
    '.cm-gutters': {backgroundColor: '#f8f9fa', borderRight: '1px solid #e9ecef', borderRadius: '6px 0 0 6px'},
    '.cm-content': {padding: '6px 0'},
});

/**
 * A DOM node shown below a line (teacher comments).
 */
class BlockWidget extends WidgetType {
    constructor(dom, key) {
        super();
        this.dom = dom;
        this.key = key;
    }

    eq(other) {
        return other.key === this.key;
    }

    toDOM() {
        return this.dom;
    }

    ignoreEvent() {
        return true;
    }
}

const setWidgets = StateEffect.define();

/**
 * Block widgets below lines: [{line, key, dom}].
 */
const widgetField = StateField.define({
    create: () => Decoration.none,
    update(deco, tr) {
        deco = deco.map(tr.changes);
        tr.effects.forEach((e) => {
            if (e.is(setWidgets)) {
                const builder = new RangeSetBuilder();
                const doc = tr.state.doc;
                e.value.slice().sort((a, b) => a.line - b.line).forEach((w) => {
                    const line = doc.line(Math.max(1, Math.min(doc.lines, w.line)));
                    builder.add(line.to, line.to, Decoration.widget({widget: new BlockWidget(w.dom, w.key), block: true,
                        side: 1}));
                });
                deco = builder.finish();
            }
        });
        return deco;
    },
    provide: (f) => EditorView.decorations.from(f),
});

const normaliseText = (text) => String(text || '').replace(/\r\n/g, '\n').trim();

/**
 * Exam mode: only allow pasting text that was copied inside this editor.
 *
 * @param {Function} onBlocked called with the number of blocked characters
 * @returns {Extension}
 */
const pasteGuard = (onBlocked) => {
    const copied = new Set();
    const remember = (view) => {
        view.state.selection.ranges.forEach((r) => {
            const text = normaliseText(view.state.sliceDoc(r.from, r.to));
            if (text) {
                copied.add(text);
            }
        });
        if (copied.size > 200) {
            copied.delete(copied.values().next().value);
        }
        return false;
    };
    const allowed = (text) => {
        const t = normaliseText(text);
        return !t || copied.has(t) || [...copied].some((c) => c.includes(t));
    };
    return EditorView.domEventHandlers({
        copy: (e, view) => remember(view),
        cut: (e, view) => remember(view),
        paste: (e) => {
            const text = e.clipboardData ? e.clipboardData.getData('text/plain') : '';
            if (allowed(text)) {
                return false;
            }
            e.preventDefault();
            onBlocked(text.length);
            return true;
        },
        drop: (e) => {
            const text = e.dataTransfer ? e.dataTransfer.getData('text/plain') : '';
            if (allowed(text) && !(e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length)) {
                return false;
            }
            e.preventDefault();
            onBlocked(text.length);
            return true;
        },
    });
};

/**
 * Create a Python editor.
 *
 * @param {HTMLElement} parent element to render into
 * @param {Object} options
 * @param {String} options.doc initial content
 * @param {Boolean} [options.readOnly]
 * @param {Function} [options.onChange] called with the new content
 * @param {String} [options.minHeight] CSS height, e.g. "300px"
 * @param {String} [options.maxHeight]
 * @param {String} [options.placeholder]
 * @param {Function} [options.onLineClick] called with the line number when a line number is clicked
 * @param {Function} [options.onBlockedPaste] exam mode: only text copied inside the editor may be pasted
 * @returns {{getValue: Function, setValue: Function, focus: Function, view: EditorView, setReadOnly: Function,
 *     setWidgets: Function}}
 */
export const createEditor = (parent, options = {}) => {
    const readOnly = new Compartment();
    const sizes = {};
    if (options.minHeight) {
        sizes['.cm-content, .cm-gutter'] = {minHeight: options.minHeight};
    }
    if (options.maxHeight) {
        sizes['.cm-scroller'] = {maxHeight: options.maxHeight, overflow: 'auto'};
    }
    const extensions = [
        options.onLineClick ? lineNumbers({domEventHandlers: {
            mousedown: (view, line) => {
                options.onLineClick(view.state.doc.lineAt(line.from).number);
                return true;
            },
        }}) : lineNumbers(),
        highlightActiveLineGutter(),
        highlightSpecialChars(),
        history(),
        foldGutter(),
        drawSelection(),
        EditorState.allowMultipleSelections.of(true),
        indentOnInput(),
        syntaxHighlighting(defaultHighlightStyle, {fallback: true}),
        bracketMatching(),
        closeBrackets(),
        autocompletion(),
        rectangularSelection(),
        highlightActiveLine(),
        highlightSelectionMatches(),
        indentUnit.of('    '),
        EditorState.tabSize.of(4),
        keymap.of([
            ...closeBracketsKeymap, ...defaultKeymap, ...searchKeymap, ...historyKeymap,
            ...foldKeymap, ...completionKeymap, indentWithTab,
        ]),
        python(),
        theme,
        EditorView.theme(sizes),
        readOnly.of([EditorState.readOnly.of(!!options.readOnly), EditorView.editable.of(!options.readOnly)]),
        EditorView.updateListener.of((update) => {
            if (update.docChanged && options.onChange) {
                options.onChange(update.state.doc.toString());
            }
        }),
    ];
    if (options.placeholder) {
        extensions.push(placeholderExt(options.placeholder));
    }
    extensions.push(widgetField);
    if (options.onBlockedPaste) {
        extensions.push(pasteGuard(options.onBlockedPaste));
    }
    const view = new EditorView({
        state: EditorState.create({doc: options.doc || '', extensions}),
        parent,
    });
    // The editor may be created inside a collapsed form section or hidden tab:
    // re-measure line heights once it becomes visible or fonts have loaded.
    if (window.IntersectionObserver) {
        new IntersectionObserver((entries) => {
            if (entries.some((e) => e.isIntersecting)) {
                view.requestMeasure();
            }
        }).observe(view.dom);
    }
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(() => view.requestMeasure());
    }
    const editor = {
        view,
        getValue: () => view.state.doc.toString(),
        setValue: (text) => view.dispatch({changes: {from: 0, to: view.state.doc.length, insert: text || ''}}),
        focus: () => view.focus(),
        setReadOnly: (flag) => view.dispatch({effects: readOnly.reconfigure([
            EditorState.readOnly.of(flag), EditorView.editable.of(!flag)])}),
        setWidgets: (widgets) => view.dispatch({effects: setWidgets.of(widgets)}),
    };
    // Reachable from the DOM (browser tests, debugging): element.mplEditor.
    parent.mplEditor = editor;
    return editor;
};

/**
 * Replace a textarea by an editor that keeps the textarea in sync (for forms).
 *
 * @param {HTMLTextAreaElement} textarea
 * @param {Object} options
 * @returns {Object} editor
 */
export const fromTextarea = (textarea, options = {}) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'mpl-cm-wrapper';
    textarea.parentNode.insertBefore(wrapper, textarea);
    textarea.style.display = 'none';
    return createEditor(wrapper, Object.assign({}, options, {
        doc: textarea.value,
        onChange: (value) => {
            textarea.value = value;
            textarea.dispatchEvent(new Event('change', {bubbles: true}));
            if (options.onChange) {
                options.onChange(value);
            }
        },
    }));
};
