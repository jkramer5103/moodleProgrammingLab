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
 * Exam mode: Safe Exam Browser check, logging of window changes, teacher helpers.
 *
 * @module     mod_mpl/exam
 * @copyright  2026 Moodle Programming Lab
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {loadStrings} from 'mod_mpl/dom';

const call = (methodname, args) => Ajax.call([{methodname, args}])[0];

/**
 * Log an exam event; failures are ignored (the exam must go on).
 *
 * @param {Number} cmid
 * @param {String} event
 * @param {String} detail
 * @param {Number} duration
 */
export const log = (cmid, event, detail = '', duration = 0) => {
    call('mod_mpl_exam_log', {cmid, event, detail: String(detail).slice(0, 200), duration: Math.round(duration)})
        .catch(() => null);
};

/**
 * Record when the student leaves the exam window (other tab, other program) and for how long.
 *
 * @param {Number} cmid
 * @param {Function} onReturn called with the seconds outside after coming back
 */
export const watchFocus = (cmid, onReturn) => {
    let leftAt = null;
    const leave = (why) => {
        if (leftAt === null) {
            leftAt = {time: Date.now(), why};
        }
    };
    const back = () => {
        if (leftAt === null) {
            return;
        }
        const seconds = (Date.now() - leftAt.time) / 1000;
        const why = leftAt.why;
        leftAt = null;
        // Very short blurs happen when e.g. a dialog of the page itself opens.
        if (seconds >= 1) {
            log(cmid, 'left', why, seconds);
            if (onReturn) {
                onReturn(seconds);
            }
        }
    };
    document.addEventListener('visibilitychange', () => (document.hidden ? leave('tab') : back()));
    window.addEventListener('blur', () => {
        // Focus moving into an iframe of the page is not leaving the page.
        setTimeout(() => {
            if (!document.hasFocus() && !(document.activeElement && document.activeElement.tagName === 'IFRAME')) {
                leave('window');
            }
        }, 50);
    });
    window.addEventListener('focus', back);
    window.addEventListener('pagehide', () => {
        if (leftAt !== null) {
            const seconds = (Date.now() - leftAt.time) / 1000;
            if (seconds >= 1) {
                log(cmid, 'left', leftAt.why + ' (closed)', seconds);
            }
        }
    });
};

/**
 * SEB gate page: use the SEB JavaScript API when SEB did not send the key headers.
 *
 * @param {Number} cmid
 */
export const sebCheck = async(cmid) => {
    const S = await loadStrings(['seb_checking', 'seb_jsfailed']);
    const box = document.querySelector('[data-region="mpl-seb-jscheck"]');
    const seb = window.SafeExamBrowser;
    if (!seb || !seb.security) {
        return;
    }
    if (box) {
        box.textContent = S.seb_checking;
    }
    const validate = async() => {
        try {
            const res = await call('mod_mpl_seb_validate', {cmid, url: window.location.href.replace(/#.*$/, ''),
                configkey: seb.security.configKey || '', browserexamkey: seb.security.browserExamKey || ''});
            if (res.ok) {
                window.location.reload();
                return;
            }
        } catch (e) {
            // Fall through to the message.
        }
        if (box) {
            box.textContent = S.seb_jsfailed;
        }
    };
    const empty = (k) => !k || k === ':';
    if (empty(seb.security.configKey) && empty(seb.security.browserExamKey) && seb.security.updateKeys) {
        seb.security.updateKeys(validate);
    } else {
        validate();
    }
};

/**
 * Copy buttons ([data-mpl-copy]) on the teacher's exam box.
 */
export const initCopy = () => {
    document.querySelectorAll('[data-mpl-copy]').forEach((btn) => {
        btn.addEventListener('click', async() => {
            const text = btn.dataset.mplCopy;
            try {
                await navigator.clipboard.writeText(text);
            } catch (e) {
                const area = document.createElement('textarea');
                area.value = text;
                document.body.appendChild(area);
                area.select();
                document.execCommand('copy');
                area.remove();
            }
            const label = btn.textContent;
            btn.textContent = '✓';
            setTimeout(() => {
                btn.textContent = label;
            }, 1500);
        });
    });
};

/**
 * Reload the exam monitor regularly (checkbox #mpl-autorefresh).
 *
 * @param {Number} seconds
 */
export const autoRefresh = (seconds) => {
    const box = document.getElementById('mpl-autorefresh');
    const key = 'mod_mpl_autorefresh';
    if (box) {
        box.checked = window.localStorage.getItem(key) !== '0';
        box.addEventListener('change', () => window.localStorage.setItem(key, box.checked ? '1' : '0'));
    }
    setInterval(() => {
        if ((!box || box.checked) && !document.hidden) {
            window.location.reload();
        }
    }, seconds * 1000);
};
