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
 * Small DOM and string helpers for mod_mpl.
 *
 * @module     mod_mpl/dom
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';

/**
 * Create an element.
 *
 * @param {String} tag e.g. "div.cls1.cls2"
 * @param {Object|null} attrs attributes, "on*" are event handlers, "text"/"html" set content
 * @param {Array|Node|String} children
 * @returns {HTMLElement}
 */
export const h = (tag, attrs = null, children = []) => {
    const [name, ...classes] = tag.split('.');
    const el = document.createElement(name || 'div');
    if (classes.length) {
        el.className = classes.join(' ');
    }
    Object.entries(attrs || {}).forEach(([key, value]) => {
        if (value === null || value === undefined || value === false) {
            return;
        }
        if (key.startsWith('on') && typeof value === 'function') {
            el.addEventListener(key.substring(2).toLowerCase(), value);
        } else if (key === 'text') {
            el.textContent = value;
        } else if (key === 'class') {
            el.className = (el.className + ' ' + value).trim();
        } else if (key === 'value') {
            el.value = value;
        } else if (key === 'checked' || key === 'selected' || key === 'disabled') {
            el[key] = !!value;
        } else if (key === 'dataset') {
            Object.assign(el.dataset, value);
        } else {
            el.setAttribute(key, value === true ? '' : value);
        }
    });
    append(el, children);
    return el;
};

/**
 * Append children (arrays, strings, nodes; null is skipped).
 *
 * @param {HTMLElement} el
 * @param {Array|Node|String} children
 * @returns {HTMLElement}
 */
export const append = (el, children) => {
    (Array.isArray(children) ? children : [children]).flat(Infinity).forEach((child) => {
        if (child === null || child === undefined || child === false) {
            return;
        }
        el.appendChild(typeof child === 'string' || typeof child === 'number'
            ? document.createTextNode(String(child)) : child);
    });
    return el;
};

/**
 * Remove all children.
 *
 * @param {HTMLElement} el
 */
export const clear = (el) => {
    while (el.firstChild) {
        el.removeChild(el.firstChild);
    }
};

/**
 * Load strings of mod_mpl into an object.
 *
 * @param {Array<String>} keys
 * @returns {Promise<Object>}
 */
export const loadStrings = async(keys) => {
    const values = await getStrings(keys.map((key) => ({key, component: 'mod_mpl'})));
    const out = {};
    keys.forEach((key, i) => {
        out[key] = values[i];
    });
    return out;
};

/**
 * Replace {$a} / {$a->x} placeholders.
 *
 * @param {String} text
 * @param {*} a
 * @returns {String}
 */
export const fmt = (text, a) => {
    if (typeof a === 'object' && a !== null) {
        return text.replace(/\{\$a->(\w+)\}/g, (m, k) => (a[k] !== undefined ? a[k] : m));
    }
    return text.replace(/\{\$a\}/g, a);
};

/**
 * Format a number the German/English way (max 2 decimals).
 *
 * @param {Number} n
 * @returns {String}
 */
export const num = (n) => {
    const v = Math.round((Number(n) || 0) * 100) / 100;
    return v.toLocaleString(document.documentElement.lang || undefined, {maximumFractionDigits: 2});
};
