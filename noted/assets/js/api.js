/**
 * notedApi — shared REST client + pub/sub sync layer.
 *
 * Reads `rest_root`, `rest_nonce`, and `current_post_id` from
 * window.notedConfig (populated by wp_localize_script on the `noted-api`
 * handle). Authenticates via the standard `X-WP-Nonce` header.
 *
 * Mutations (add / edit / delete / pin) automatically dispatch a
 * `noted:changed` event on `window`. Use {@link notedApi.subscribe} to
 * react. Cross-tab sync is provided where the BroadcastChannel API is
 * available.
 *
 * @namespace window.notedApi
 */
(function (window) {
    const config = window.notedConfig || {};

    const EVENT_NAME = 'noted:changed';

    let broadcastChannel = null;
    try {
        if (typeof BroadcastChannel !== 'undefined') {
            broadcastChannel = new BroadcastChannel('noted-sync');
        }
    } catch (_) {
        // BroadcastChannel is unavailable (older browsers, sandboxed
        // contexts). Same-window events still fire, so we don't escalate.
    }

    /**
     * Translate a key when wp.i18n is available, otherwise return the
     * original string. The shared API loads ahead of consumers that bring
     * `wp-i18n` in as a dependency, so we cannot rely on it being defined.
     *
     * @param {string} text
     * @returns {string}
     */
    function translate(text) {
        if (window.wp && window.wp.i18n && typeof window.wp.i18n.__ === 'function') {
            return window.wp.i18n.__(text, 'noted');
        }
        return text;
    }

    /**
     * Build the request URL by appending any query parameters that are not
     * null/undefined/empty.
     *
     * @param {string} path
     * @param {Object|undefined} query
     * @returns {string}
     */
    function buildUrl(path, query) {
        let url = (config.rest_root || '') + path.replace(/^\//, '');
        if (!query) {
            return url;
        }
        const params = new URLSearchParams();
        Object.keys(query).forEach(function (key) {
            const value = query[key];
            if (value === undefined || value === null || value === '') {
                return;
            }
            params.append(key, value);
        });
        const queryString = params.toString();
        if (!queryString) {
            return url;
        }
        return url + (url.indexOf('?') >= 0 ? '&' : '?') + queryString;
    }

    /**
     * Issue a REST request to the noted/v1 namespace and parse the JSON
     * response. Non-2xx responses reject with an Error carrying the
     * server's error message + code.
     *
     * @param {string} method HTTP verb.
     * @param {string} path   Path relative to the noted/v1 namespace.
     * @param {Object} [body] JSON body (sent on non-GET requests).
     * @param {Object} [query] Query parameters appended to the URL.
     * @returns {Promise<any>} Parsed JSON response.
     */
    function request(method, path, body, query) {
        const url = buildUrl(path, query);
        const options = {
            method: method,
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': config.rest_nonce || '',
                'Accept': 'application/json',
            },
        };

        if (body !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }

        return fetch(url, options).then(function (response) {
            return response.json().then(function (data) {
                if (response.ok) {
                    return data;
                }
                const message = (data && data.message) || translate('Request failed');
                const error = new Error(message);
                error.code = data && data.code;
                error.data = data;
                throw error;
            });
        });
    }

    /**
     * Broadcast a change event to every other surface in this window
     * (and to other tabs of this browser, where supported).
     *
     * @param {Object} [detail] Optional detail payload (e.g. action: 'add').
     */
    function notify(detail) {
        const payload = detail || {};
        try {
            window.dispatchEvent(new CustomEvent(EVENT_NAME, { detail: payload }));
        } catch (_) {
            // CustomEvent isn't available in some older browsers — fall
            // back to the deprecated createEvent path so the in-window
            // notification still fires.
            const fallbackEvent = document.createEvent('Event');
            fallbackEvent.initEvent(EVENT_NAME, false, false);
            window.dispatchEvent(fallbackEvent);
        }
        if (broadcastChannel) {
            try {
                broadcastChannel.postMessage(payload);
            } catch (_) {
                // Cross-tab delivery is best-effort; failures here are
                // silently ignored so in-window listeners still fire.
            }
        }
    }

    /**
     * Subscribe to change notifications. Returns an unsubscribe function.
     *
     * @param {(detail: Object) => void} callback
     * @returns {() => void}
     */
    function subscribe(callback) {
        function onWindowEvent(event) {
            callback((event && event.detail) || {});
        }
        function onChannelMessage(event) {
            callback((event && event.data) || {});
        }
        window.addEventListener(EVENT_NAME, onWindowEvent);
        if (broadcastChannel) {
            broadcastChannel.addEventListener('message', onChannelMessage);
        }
        return function unsubscribe() {
            window.removeEventListener(EVENT_NAME, onWindowEvent);
            if (broadcastChannel) {
                broadcastChannel.removeEventListener('message', onChannelMessage);
            }
        };
    }

    /**
     * Wrap a mutation so its resolved value also triggers a notify().
     *
     * @param {string} action
     * @param {Function} fn
     * @returns {Function}
     */
    function mutating(action, fn) {
        return function () {
            return fn.apply(null, arguments).then(function (data) {
                notify({ action: action });
                return data;
            });
        };
    }

    const api = {
        /** Raw config object (read-only). */
        config: config,

        /**
         * Resolve the current post ID from localized config.
         *
         * @returns {number} Post ID or 0 if not on a singular edit screen.
         */
        currentPostId: function () {
            return parseInt(config.current_post_id || 0, 10) || 0;
        },

        /**
         * True if the current user can add / edit / delete notes.
         *
         * @returns {boolean}
         */
        canEdit: function () {
            return !!config.can_edit;
        },

        /**
         * GET /notes — list notes (optionally scoped).
         *
         * @param {('global'|'post'|'')} scope            Scope filter.
         * @param {number}               [attachedPostId] Required when scope==="post".
         * @returns {Promise<Array<Object>>}
         */
        fetchNotes: function (scope, attachedPostId) {
            const normalizedScope = scope === 'post' ? 'post' : 'global';
            const query = { scope: normalizedScope };
            if (normalizedScope === 'post') {
                query.post_id = attachedPostId || 0;
            }
            return request('GET', 'notes', undefined, query);
        },

        notify: notify,
        subscribe: subscribe,
    };

    /**
     * POST /notes — create a note.
     */
    api.addNote = mutating('add', function (title, description, attachedPostId) {
        return request('POST', 'notes', {
            title: title,
            description: description,
            attached_post_id: attachedPostId || 0,
        });
    });

    /**
     * PUT /notes/{id} — update a note's title and body.
     */
    api.editNote = mutating('edit', function (noteId, title, description) {
        return request('PUT', `notes/${noteId}`, {
            title: title,
            description: description,
        });
    });

    /**
     * DELETE /notes/{id}.
     */
    api.deleteNote = mutating('delete', function (noteId) {
        return request('DELETE', `notes/${noteId}`);
    });

    /**
     * PUT /notes/{id} — toggle the pinned flag.
     */
    api.pinNote = mutating('pin', function (noteId, pinned) {
        return request('PUT', `notes/${noteId}`, { pinned: !!pinned });
    });

    window.notedApi = api;
})(window);
