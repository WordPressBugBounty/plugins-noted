/**
 * Noted! — Classic editor meta box handler.
 *
 * Renders the post-scoped notes list in-place (no page reload). Mutations
 * trigger {@link notedApi.notify} so the floating panel stays in sync,
 * and the meta box itself subscribes for changes coming from other
 * surfaces (block editor, other tabs).
 *
 * @module classic-metabox
 */
(function () {
    const translate =
        window.wp && window.wp.i18n && typeof window.wp.i18n.__ === 'function'
            ? window.wp.i18n.__
            : function (text) {
                  return text;
              };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /**
     * Wire up the document-level click delegate and cross-surface
     * subscription. Called exactly once on script load.
     */
    function init() {
        document.addEventListener('click', onClick);

        if (window.notedApi && typeof window.notedApi.subscribe === 'function') {
            window.notedApi.subscribe(refreshAll);
        }

        // We only flip view-only mode when notedConfig says so explicitly.
        // A missing config indicates a script-loading problem, not a
        // permission downgrade, so we leave the UI editable in that case.
        if (window.notedConfig && window.notedConfig.can_edit === false) {
            document.querySelectorAll('.noted-metabox').forEach(function (box) {
                box.classList.add('is-view-only');
            });
        }
    }

    /**
     * Toggle expansion on a card and keep aria-expanded in sync with the
     * `.is-expanded` class.
     *
     * @param {HTMLElement} card
     */
    function toggleExpanded(card) {
        if (!card || card.classList.contains('is-editing')) {
            return;
        }
        const expanded = !card.classList.contains('is-expanded');
        card.classList.toggle('is-expanded', expanded);
        const titleButton = card.querySelector('.noted-card__head .noted-card__title');
        if (titleButton && titleButton.tagName === 'BUTTON') {
            titleButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
    }

    /**
     * Dispatch a click inside any meta box to the relevant handler.
     *
     * @param {MouseEvent} event
     */
    function onClick(event) {
        const box = event.target.closest('.noted-metabox');
        if (!box) {
            return;
        }

        if (event.target.matches('.noted-metabox-add-btn')) {
            handleAdd(box);
            return;
        }
        if (event.target.matches('.noted-metabox-edit')) {
            event.preventDefault();
            startEditing(event.target.closest('.noted-card'));
            return;
        }
        if (event.target.matches('.noted-metabox-save')) {
            event.preventDefault();
            saveEditing(event.target.closest('.noted-card'));
            return;
        }
        if (event.target.matches('.noted-metabox-cancel')) {
            event.preventDefault();
            const card = event.target.closest('.noted-card');
            if (card) {
                refreshBox(card.closest('.noted-metabox'));
            }
            return;
        }
        if (event.target.matches('.noted-metabox-delete')) {
            event.preventDefault();
            handleDelete(event.target);
            return;
        }
        if (event.target.matches('.noted-metabox-pin')) {
            event.preventDefault();
            handlePin(event.target);
            return;
        }

        const head = event.target.closest('.noted-card__head');
        if (!head) {
            return;
        }
        if (event.target.closest('.noted-card__actions')) {
            return;
        }
        toggleExpanded(head.closest('.noted-card--collapsible'));
    }

    /**
     * Submit the add-note form for a single meta box.
     *
     * @param {HTMLElement} box
     */
    function handleAdd(box) {
        const titleField       = box.querySelector('.noted-metabox-title');
        const descriptionField = box.querySelector('.noted-metabox-description');
        const title       = titleField.value;
        const description = descriptionField.value;
        if (!title && !description) {
            return;
        }

        const postId = parseInt(box.dataset.postId, 10) || 0;
        window.notedApi
            .addNote(title, description, postId)
            .then(function () {
                titleField.value       = '';
                descriptionField.value = '';
                refreshBox(box);
            })
            .catch(logError);
    }

    /**
     * Swap a read-only card into an inline edit form.
     *
     * @param {HTMLElement} card
     */
    function startEditing(card) {
        if (!card || card.classList.contains('is-editing')) {
            return;
        }
        const noteId   = card.dataset.noteId;
        const title    = card.dataset.title || '';
        const markdown = card.dataset.markdown || '';

        card.classList.add('is-editing', 'is-expanded');

        const previousTitle = card.querySelector('.noted-card__title');
        const bodyElement   = card.querySelector('.noted-card__body');

        // Swap the toggle button for a span — putting an <input> inside
        // a <button> is invalid HTML, and edit mode disables the
        // accordion toggle anyway.
        const titleSpan = document.createElement('span');
        titleSpan.className = 'noted-card__title';
        titleSpan.innerHTML = `<input type="text" class="noted-metabox-edit-title widefat" value="${escapeAttr(title)}">`;
        previousTitle.replaceWith(titleSpan);

        const saveLabel   = escapeText(translate('Save', 'noted'));
        const cancelLabel = escapeText(translate('Cancel', 'noted'));

        bodyElement.innerHTML = `
            <textarea class="noted-metabox-edit-body widefat" rows="5">${escapeText(markdown)}</textarea>
            ${markdownDocHelpBelow()}
            <div class="noted-card__edit-actions">
                <button type="button" class="button button-small button-primary noted-metabox-save"
                    data-note-id="${noteId}">${saveLabel}</button>
                <button type="button" class="button button-small button-link noted-metabox-cancel">${cancelLabel}</button>
            </div>
        `;
    }

    /**
     * Persist the inline edit and refresh the parent meta box.
     *
     * @param {HTMLElement} card
     */
    function saveEditing(card) {
        if (!card) {
            return;
        }
        const noteId = parseInt(card.dataset.noteId, 10);
        const title  = card.querySelector('.noted-metabox-edit-title').value;
        const body   = card.querySelector('.noted-metabox-edit-body').value;
        const box    = card.closest('.noted-metabox');
        window.notedApi
            .editNote(noteId, title, body)
            .then(function () {
                refreshBox(box);
            })
            .catch(logError);
    }

    /**
     * Confirm and delete a note.
     *
     * @param {HTMLElement} deleteButton
     */
    function handleDelete(deleteButton) {
        if (!confirm(translate('Delete this note?', 'noted'))) {
            return;
        }
        const noteId = parseInt(deleteButton.dataset.noteId, 10);
        const box    = deleteButton.closest('.noted-metabox');
        window.notedApi
            .deleteNote(noteId)
            .then(function () {
                refreshBox(box);
            })
            .catch(logError);
    }

    /**
     * Toggle the pinned state on a note.
     *
     * @param {HTMLElement} pinButton
     */
    function handlePin(pinButton) {
        const noteId    = parseInt(pinButton.dataset.noteId, 10);
        const card      = pinButton.closest('.noted-card');
        const wasPinned = card ? card.classList.contains('is-pinned') : false;
        const box       = pinButton.closest('.noted-metabox');
        window.notedApi
            .pinNote(noteId, !wasPinned)
            .then(function () {
                refreshBox(box);
            })
            .catch(logError);
    }

    /**
     * Refresh every meta box on the page.
     */
    function refreshAll() {
        document.querySelectorAll('.noted-metabox').forEach(refreshBox);
    }

    /**
     * Re-fetch notes for one meta box and re-render its list, preserving
     * any currently-expanded cards.
     *
     * @param {HTMLElement} box
     */
    function refreshBox(box) {
        if (!box) {
            return;
        }
        const postId = parseInt(box.dataset.postId, 10) || 0;

        const expandedIds = new Set();
        box.querySelectorAll('.noted-card.is-expanded').forEach(function (card) {
            const id = parseInt(card.dataset.noteId, 10);
            if (Number.isFinite(id) && id > 0) {
                expandedIds.add(id);
            }
        });

        window.notedApi
            .fetchNotes('post', postId)
            .then(function (notes) {
                renderList(box.querySelector('.noted-metabox-list'), notes || []);
                expandedIds.forEach(function (noteId) {
                    const card = box.querySelector(`.noted-card[data-note-id="${noteId}"]`);
                    if (!card) {
                        return;
                    }
                    card.classList.add('is-expanded');
                    const titleButton = card.querySelector('.noted-card__head .noted-card__title');
                    if (titleButton && titleButton.tagName === 'BUTTON') {
                        titleButton.setAttribute('aria-expanded', 'true');
                    }
                });
            })
            .catch(logError);
    }

    /**
     * Render the cards list inside `.noted-metabox-list`.
     *
     * @param {HTMLElement} list
     * @param {Array<Object>} notes
     */
    function renderList(list, notes) {
        if (!list) {
            return;
        }
        if (!notes.length) {
            const emptyMessage = escapeText(translate('No notes on this post yet.', 'noted'));
            list.innerHTML     = `<p class="noted-empty">${emptyMessage}</p>`;
            return;
        }
        list.innerHTML = notes.map(renderCard).join('');
    }

    /**
     * Build the HTML for a single note card. Matches PageNotes::renderMetaBox.
     *
     * @param {Object} note
     * @returns {string}
     */
    function renderCard(note) {
        const pinned       = !!note.pinned;
        const pinClass     = pinned ? ' is-pinned' : '';
        const pinPressed   = pinned ? 'true' : 'false';
        const pinLabel     = escapeText(pinned ? translate('Unpin', 'noted') : translate('Pin', 'noted'));
        const titleText    = escapeText(note.title || translate('(untitled)', 'noted'));
        const markdownAttr = escapeAttr(note.markdown || '');
        const titleAttr    = escapeAttr(note.title || '');
        const metaText     = escapeText(`${note.username || ''} · ${note.timestamp || ''}`);
        const description  = note.description || '';
        const editLabel    = escapeText(translate('Edit', 'noted'));
        const deleteLabel  = escapeText(translate('Delete', 'noted'));

        return `
            <article class="noted-card noted-card--collapsible${pinClass}"
                data-note-id="${note.id}"
                data-markdown="${markdownAttr}"
                data-title="${titleAttr}">
                <header class="noted-card__head">
                    <button type="button" id="noted-toggle-${note.id}"
                        class="noted-card__title" aria-expanded="false"
                        aria-controls="noted-collapsible-${note.id}">
                        ${titleText}
                    </button>
                </header>
                <div class="noted-card__collapsible" id="noted-collapsible-${note.id}"
                    role="region" aria-labelledby="noted-toggle-${note.id}">
                    <div class="noted-card__body">${description}</div>
                    <div class="noted-card__actions">
                        <button type="button" class="button-link noted-metabox-pin"
                            data-note-id="${note.id}" aria-pressed="${pinPressed}">${pinLabel}</button>
                        <button type="button" class="button-link noted-metabox-edit"
                            data-note-id="${note.id}">${editLabel}</button>
                        <button type="button" class="button-link button-link-delete noted-metabox-delete"
                            data-note-id="${note.id}">${deleteLabel}</button>
                    </div>
                    <footer class="noted-card__meta">${metaText}</footer>
                </div>
            </article>
        `;
    }

    /**
     * HTML-attribute-safe escape.
     *
     * @param {*} value
     * @returns {string}
     */
    function escapeAttr(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /**
     * HTML-text-safe escape.
     *
     * @param {*} value
     * @returns {string}
     */
    function escapeText(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /**
     * Console logger for API errors. Kept silent in production unless the
     * browser supports console.error.
     *
     * @param {Error} error
     */
    function logError(error) {
        if (window.console && console.error) {
            console.error('[Noted!]', error);
        }
    }

    /**
     * Render the “View supported Markdown” help link that appears below
     * the inline-edit textarea. Returns an empty string when the help URL
     * has not been localised.
     *
     * @returns {string}
     */
    function markdownDocHelpBelow() {
        const config = window.notedConfig || {};
        if (!config.markdown_doc_url) {
            return '';
        }
        const url       = escapeAttr(config.markdown_doc_url);
        const ariaLabel = escapeAttr(config.markdown_doc_aria_label || '');
        const tooltip   = escapeAttr(config.markdown_doc_tooltip || '');
        return `
            <p class="noted-metabox-markdown-help">
                <a href="${url}" class="noted-markdown-doc-link"
                    target="_blank" rel="noopener noreferrer"
                    aria-label="${ariaLabel}" data-tooltip="${tooltip}">
                    <span class="dashicons dashicons-info" aria-hidden="true"></span>
                </a>
            </p>
        `;
    }
})();
