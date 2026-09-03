/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 *
 * Fills the Platform Overview panels.
 *
 * Every tab is fetched on its own, all at once: one unresponsive backend then
 * costs one slow panel instead of a page that will not paint. Content is built
 * with DOM nodes rather than innerHTML, because these strings are version
 * banners, queue names and error messages that came back from services outside
 * Magento and must never be parsed as markup.
 */
define(['mage/translate'], function ($t) {
    'use strict';

    /**
     * The badge shown next to a tab's summary line.
     *
     * A switch over literals rather than a lookup table, because Magento's
     * i18n collector extracts $t('...') by scanning for the literal — a
     * $t(variable) is invisible to it and ships untranslated.
     *
     * @param {String} status
     * @returns {String}
     */
    function statusLabel(status) {
        switch (status) {
            case 'ok':
                return $t('OK');

            case 'warn':
                return $t('Attention');

            case 'unavailable':
                return $t('Unavailable');

            case 'error':
                return $t('Problem');

            default:
                return '';
        }
    }

    /**
     * @param {String} tag
     * @param {String} className
     * @param {String} [text]
     * @returns {HTMLElement}
     */
    function el(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }

        return node;
    }

    /**
     * @param {Object} row
     * @returns {HTMLElement}
     */
    function renderRow(row) {
        var item = el('div', 'magenx-platform-row _status-' + (row.status || 'info')),
            label = el('div', 'magenx-platform-row-label', row.label),
            value = el('div', 'magenx-platform-row-value', row.value);

        if (row.hint) {
            label.appendChild(el('span', 'magenx-platform-row-hint', row.hint));
        }

        item.appendChild(label);
        item.appendChild(value);

        return item;
    }

    /**
     * @param {Object} section
     * @returns {HTMLElement}
     */
    function renderSection(section) {
        var card = el('div', 'magenx-platform-card _status-' + (section.status || 'info'));

        card.appendChild(el('h3', 'magenx-platform-card-title', section.label));
        (section.rows || []).forEach(function (row) {
            card.appendChild(renderRow(row));
        });

        return card;
    }

    /**
     * When these numbers were actually measured.
     *
     * The collector reports its own collection time, whether the payload came
     * back off the server-side snapshot cache, and how long the probe took —
     * so a cached tab says so instead of borrowing the browser's clock and
     * claiming to be current.
     *
     * @param {Object} payload
     * @returns {HTMLElement|null}
     */
    function renderFreshness(payload) {
        if (!payload.collected_at) {
            return null;
        }

        if (payload.cached) {
            return el(
                'p',
                'magenx-platform-panel-meta',
                $t('Cached snapshot, collected %1').replace('%1', payload.collected_at)
            );
        }

        return el(
            'p',
            'magenx-platform-panel-meta',
            $t('Collected %1 in %2 ms')
                .replace('%1', payload.collected_at)
                .replace('%2', String(payload.elapsed_ms || 0))
        );
    }

    /**
     * @param {HTMLElement} panel
     * @param {Object} payload
     */
    function renderPanel(panel, payload) {
        var summary = el('div', 'magenx-platform-summary _status-' + (payload.status || 'info')),
            grid = el('div', 'magenx-platform-grid'),
            badge = statusLabel(payload.status),
            freshness;

        panel.textContent = '';

        if (badge) {
            summary.appendChild(el('span', 'magenx-platform-badge', badge));
        }
        summary.appendChild(el('span', 'magenx-platform-summary-text', payload.summary || ''));
        panel.appendChild(summary);

        (payload.sections || []).forEach(function (section) {
            grid.appendChild(renderSection(section));
        });
        panel.appendChild(grid);

        freshness = renderFreshness(payload);

        if (freshness) {
            panel.appendChild(freshness);
        }
    }

    /**
     * @param {HTMLElement} root
     * @param {String} code
     * @param {String} status
     */
    function paintDot(root, code, status) {
        var tab = root.querySelector('.magenx-platform-tab[data-collector="' + code + '"] .magenx-platform-dot');

        if (tab) {
            tab.setAttribute('data-status', status);
        }
    }

    return function (config, element) {
        var root = element,
            meta = root.querySelector('[data-role="meta"]'),
            inFlight = false,
            timer = null;

        /**
         * @param {Object} tab
         * @returns {Promise}
         */
        function load(tab) {
            var panel = root.querySelector('.magenx-platform-panel[data-panel="' + tab.code + '"]');

            paintDot(root, tab.code, 'pending');

            return fetch(tab.url, {
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.json();
            }).then(function (payload) {
                renderPanel(panel, payload);
                paintDot(root, tab.code, payload.status || 'info');

                return payload;
            }).catch(function (error) {
                renderPanel(panel, {
                    status: 'unavailable',
                    summary: $t('This tab could not be loaded: %1').replace('%1', error.message),
                    sections: []
                });
                paintDot(root, tab.code, 'unavailable');
            });
        }

        /**
         * Fires every tab at once — the whole point of splitting the endpoint.
         *
         * A run already in progress is never joined by a second one. Auto
         * refresh and the Refresh button share this entry point, and without
         * the guard a held-down button, or an interval shorter than the
         * slowest backend, would stack a fresh round of probes on top of the
         * round still waiting — turning the dashboard into the load generator
         * the server-side snapshot cache exists to prevent.
         */
        function loadAll() {
            if (inFlight) {
                return Promise.resolve();
            }

            inFlight = true;

            if (meta) {
                meta.textContent = $t('Collecting…');
            }

            return Promise.all((config.tabs || []).map(load)).then(function () {
                inFlight = false;

                if (meta) {
                    // "Fetched", not "Updated": a tab served from the snapshot
                    // cache carries its own, older collection time, which the
                    // panel prints under its cards.
                    meta.textContent = $t('Fetched %1').replace('%1', new Date().toLocaleTimeString());
                }
            }, function () {
                inFlight = false;
            });
        }

        root.addEventListener('click', function (event) {
            var target = event.target,
                tabButton,
                refresh,
                code;

            if (!target || typeof target.closest !== 'function') {
                return;
            }

            tabButton = target.closest('.magenx-platform-tab');
            refresh = target.closest('[data-role="refresh"]');

            if (refresh) {
                loadAll();

                return;
            }

            if (!tabButton) {
                return;
            }

            code = tabButton.getAttribute('data-collector');

            Array.prototype.forEach.call(root.querySelectorAll('.magenx-platform-tab'), function (node) {
                var active = node === tabButton;

                node.classList.toggle('_active', active);
                node.setAttribute('aria-selected', active ? 'true' : 'false');
                node.setAttribute('tabindex', active ? '0' : '-1');
            });

            Array.prototype.forEach.call(root.querySelectorAll('.magenx-platform-panel'), function (node) {
                node.classList.toggle('_active', node.getAttribute('data-panel') === code);
            });
        });

        loadAll();

        if (config.autoRefresh > 0) {
            timer = window.setInterval(loadAll, config.autoRefresh * 1000);
            window.addEventListener('beforeunload', function () {
                window.clearInterval(timer);
            });
        }
    };
});
