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

    /**
     * How long a single tab is given before the browser gives up on it.
     *
     * A backstop, not a second copy of the server-side timeout. That timeout
     * bounds one probe, and a collector may make several in sequence — the
     * search tab issues four — so the request can legitimately outlive it
     * several times over. What must not happen is a request that never settles:
     * loadAll() refuses to start a second round while one is in flight, so a
     * hung fetch used to make Refresh and auto-refresh silently do nothing for
     * as long as the tab stayed open.
     *
     * @type {Number}
     */
    var REQUEST_DEADLINE_MS = 90000;

    return function (config, element) {
        var root = element,
            meta = root.querySelector('[data-role="meta"]'),
            // Server-rendered and static for the life of the page.
            tabs = Array.prototype.slice.call(root.querySelectorAll('.magenx-platform-tab')),
            inFlight = false;

        /**
         * Show one tab's panel and move the roving tabindex onto its link.
         *
         * @param {HTMLElement} tabLink
         * @param {Boolean} [moveFocus] True when the keyboard drove this.
         */
        function activate(tabLink, moveFocus) {
            var code = tabLink.getAttribute('data-collector');

            tabs.forEach(function (node) {
                var active = node === tabLink,
                    // The admin theme hangs the selected style off the list
                    // item as well as the link, so both carry the marker.
                    item = node.closest('.admin__page-nav-item');

                node.classList.toggle('_active', active);

                if (item) {
                    item.classList.toggle('_active', active);
                }

                node.setAttribute('aria-selected', active ? 'true' : 'false');
                // Exactly one tab is tabbable, which is what makes the tablist
                // one stop in the page's tab order instead of six.
                node.setAttribute('tabindex', active ? '0' : '-1');
            });

            Array.prototype.forEach.call(root.querySelectorAll('.magenx-platform-panel'), function (node) {
                node.classList.toggle('_active', node.getAttribute('data-panel') === code);
            });

            if (moveFocus) {
                tabLink.focus();
            }
        }

        /**
         * @param {Object} tab
         * @returns {Promise}
         */
        function load(tab) {
            var panel = root.querySelector('.magenx-platform-panel[data-panel="' + tab.code + '"]'),
                controller = typeof window.AbortController === 'function' ? new window.AbortController() : null,
                deadline = null;

            paintDot(root, tab.code, 'pending');

            if (controller) {
                deadline = window.setTimeout(function () {
                    controller.abort();
                }, REQUEST_DEADLINE_MS);
            }

            return fetch(tab.url, {
                credentials: 'same-origin',
                // A probe is a measurement of right now. Answering it from the
                // browser's cache would make Refresh look like it worked while
                // showing the numbers from the previous click.
                cache: 'no-store',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                signal: controller ? controller.signal : undefined
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.json();
            }).then(function (payload) {
                renderPanel(panel, payload);
                paintDot(root, tab.code, payload.status || 'info');
            }).catch(function (error) {
                var reason = error && error.message ? error.message : 'unknown error';

                renderPanel(panel, {
                    status: 'unavailable',
                    summary: error && error.name === 'AbortError'
                        ? $t('This tab did not answer in time and was given up on.')
                        : $t('This tab could not be loaded: %1').replace('%1', reason),
                    sections: []
                });
                paintDot(root, tab.code, 'unavailable');
            }).then(function () {
                if (deadline !== null) {
                    window.clearTimeout(deadline);
                }
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
         *
         * @returns {Promise}
         */
        function loadAll() {
            if (inFlight) {
                return Promise.resolve();
            }

            inFlight = true;

            if (meta) {
                meta.textContent = $t('Collecting…');
            }

            // load() settles every branch itself, including the deadline, so
            // this cannot be left pending — which is what inFlight relies on.
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
                tabLink;

            if (!target || typeof target.closest !== 'function') {
                return;
            }

            if (target.closest('[data-role="refresh"]')) {
                loadAll();

                return;
            }

            tabLink = target.closest('.magenx-platform-tab');

            if (tabLink) {
                activate(tabLink);
            }
        });

        // Without this the roving tabindex above is a trap rather than a
        // convenience: every tab but the active one is removed from the tab
        // order, so arrow keys are the only way left to reach them. Up and
        // Down lead because the strip is vertical; Left and Right are kept as
        // synonyms rather than as a second behaviour.
        root.addEventListener('keydown', function (event) {
            var target = event.target,
                current,
                index,
                next;

            if (!target || typeof target.closest !== 'function') {
                return;
            }

            current = target.closest('.magenx-platform-tab');

            if (!current) {
                return;
            }

            index = tabs.indexOf(current);

            switch (event.key) {
                case 'ArrowUp':
                case 'ArrowLeft':
                    next = tabs[(index - 1 + tabs.length) % tabs.length];
                    break;

                case 'ArrowDown':
                case 'ArrowRight':
                    next = tabs[(index + 1) % tabs.length];
                    break;

                case 'Home':
                    next = tabs[0];
                    break;

                case 'End':
                    next = tabs[tabs.length - 1];
                    break;

                case 'Enter':
                case ' ':
                    // A button would have raised a click here by itself. These
                    // are the admin's own nav anchors, carrying no href so that
                    // selecting a tab cannot push a history entry, and an
                    // anchor without one raises nothing — so activation is
                    // spelled out.
                    next = current;
                    break;

                default:
                    return;
            }

            if (!next) {
                return;
            }

            event.preventDefault();
            activate(next, true);
        });

        loadAll();

        if (config.autoRefresh > 0) {
            // No teardown: the interval dies with the document, and a
            // beforeunload listener registered to clear it would cost the page
            // its place in the browser's back/forward cache for nothing.
            window.setInterval(loadAll, config.autoRefresh * 1000);
        }
    };
});
