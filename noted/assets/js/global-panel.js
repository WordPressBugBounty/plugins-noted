/**
 * Noted! — Floating admin-bar panel.
 *
 * jQuery-driven UI rendered on non-block-editor screens. Communicates with
 * the server via window.notedApi (REST under the hood) and stays in sync
 * with the classic meta box and block-editor sidebar via the shared
 * notedApi pub/sub channel.
 *
 * @module global-panel
 */
jQuery(document).ready(function ($) {
    const translate =
        window.wp && window.wp.i18n && typeof window.wp.i18n.__ === 'function'
            ? window.wp.i18n.__
            : function (text) {
                  return text;
              };

    /** @type {'global'|'post'} Active scope tab. */
    let currentScope = 'global';

    /**
     * Selector matching every interactive element inside the panel that
     * should be reachable via Tab key while the panel is open.
     */
    const FOCUSABLE_SELECTOR =
        'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]),' +
        ' select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    /** Element to restore focus to after the panel closes. */
    let previouslyFocused = null;

    /**
     * Read the current post ID from the panel's data attribute.
     *
     * @returns {number} Post ID, or 0 when none.
     */
    function currentPostId() {
        const attribute = $('#noted-panel').data('current-post');
        const parsed    = parseInt(attribute, 10);
        return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
    }

    /**
     * Open the panel: capture current focus, slide in, focus the title
     * input, and install the keyboard trap.
     */
    function openPanel() {
        const panel = document.getElementById('noted-panel');
        if (!panel) {
            return;
        }
        previouslyFocused = document.activeElement;
        panel.classList.add('open');
        loadNotes();

        // Defer to the next animation frame so the slide animation does
        // not fight a scroll-into-view triggered by .focus().
        requestAnimationFrame(function () {
            const titleInput = panel.querySelector('#noted-title');
            if (titleInput) {
                titleInput.focus();
            }
        });

        document.addEventListener('keydown', onPanelKeyDown);
    }

    /**
     * Close the panel and restore focus to the element that opened it.
     */
    function closePanel() {
        const panel = document.getElementById('noted-panel');
        if (!panel) {
            return;
        }
        panel.classList.remove('open');
        document.removeEventListener('keydown', onPanelKeyDown);
        if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
            previouslyFocused.focus();
        }
        previouslyFocused = null;
    }

    /**
     * Keyboard handler installed while the panel is open. Escape closes
     * the panel; Tab is constrained inside the panel.
     *
     * @param {KeyboardEvent} event
     */
    function onPanelKeyDown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            closePanel();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }

        const panel = document.getElementById('noted-panel');
        if (!panel) {
            return;
        }
        const focusables = Array.prototype.filter.call(
            panel.querySelectorAll(FOCUSABLE_SELECTOR),
            function (element) {
                return element.offsetParent !== null;
            },
        );
        if (focusables.length === 0) {
            return;
        }

        const firstFocusable = focusables[0];
        const lastFocusable  = focusables[focusables.length - 1];

        if (event.shiftKey && document.activeElement === firstFocusable) {
            event.preventDefault();
            lastFocusable.focus();
            return;
        }
        if (!event.shiftKey && document.activeElement === lastFocusable) {
            event.preventDefault();
            firstFocusable.focus();
        }
    }

    $(document).on('click', '.noted-icon', function (event) {
        event.preventDefault();
        const isOpen = $('#noted-panel').hasClass('open');
        if (isOpen) {
            closePanel();
        } else {
            openPanel();
        }
    });

    $(document).on('click', '#noted-close', closePanel);

    $(document).on('click', '.noted-tab', function () {
        const tab = $(this).data('tab');
        if (tab === currentScope) {
            return;
        }
        currentScope = tab;
        $('.noted-tab').removeClass('is-active');
        $(this).addClass('is-active');
        loadNotes();
    });

    /**
     * Send the add-note form to the server.
     */
    $('#noted-add').on('click', function () {
        const title       = $('#noted-title').val();
        const description = $('#noted-description').val();
        const attached    = currentScope === 'post' ? currentPostId() : 0;

        window.notedApi
            .addNote(title, description, attached)
            .then(function () {
                $('#noted-title').val('');
                $('#noted-description').val('');
                loadNotes();
            })
            .catch(logError);
    });

    /**
     * Fetch notes for the current scope and (re)render the list,
     * preserving which cards were expanded before the re-render.
     */
    function loadNotes() {
        const expandedIds = new Set();
        $('#noted-list .noted-card.is-expanded').each(function () {
            const id = parseInt($(this).attr('data-note-id'), 10);
            if (Number.isFinite(id) && id > 0) {
                expandedIds.add(id);
            }
        });

        window.notedApi
            .fetchNotes(currentScope, currentPostId())
            .then(function (notes) {
                const list = $('#noted-list').empty();
                if (!notes || notes.length === 0) {
                    const emptyMessage =
                        currentScope === 'post'
                            ? translate('No notes on this post yet.', 'noted')
                            : translate('No notes yet.', 'noted');
                    list.append($('<p class="noted-empty"></p>').text(emptyMessage));
                    return;
                }
                notes.forEach(function (note) {
                    const card = renderNote(note);
                    if (expandedIds.has(note.id)) {
                        card.addClass('is-expanded');
                    }
                    list.append(card);
                });
                attachNoteEvents();
            })
            .catch(logError);
    }

    /**
     * Build the DOM for a single note card. Uses .text() / .attr() to
     * set user data so the markup is XSS-safe even when the server stops
     * pre-escaping (which it now does, so the same payload can serve
     * both the React and jQuery consumers).
     *
     * @param {Object} note
     * @returns {jQuery}
     */
    function renderNote(note) {
        const collapsibleId = `noted-collapsible-${note.id}`;
        const toggleId      = `noted-toggle-${note.id}`;
        const card = $(`
            <article class="noted-card noted-card--collapsible">
                <header class="noted-card__head">
                    <button type="button" class="noted-card__title" aria-expanded="false"></button>
                </header>
                <div class="noted-card__collapsible" role="region">
                    <div class="noted-card__body"></div>
                    <div class="noted-card__actions">
                        <button type="button" class="button-link noted-card__pin"></button>
                        <button type="button" class="button-link noted-card__edit"></button>
                        <button type="button" class="button-link button-link-delete noted-card__delete"></button>
                    </div>
                    <footer class="noted-card__meta"></footer>
                </div>
            </article>
        `);

        card.attr('data-note-id', note.id);
        if (note.pinned) {
            card.addClass('is-pinned');
        }

        card.find('.noted-card__title')
            .attr('id', toggleId)
            .attr('aria-controls', collapsibleId)
            .text(note.title || translate('(untitled)', 'noted'));

        card.find('.noted-card__collapsible')
            .attr('id', collapsibleId)
            .attr('aria-labelledby', toggleId);

        card.find('.noted-card__pin')
            .attr('data-note-id', note.id)
            .attr('aria-pressed', note.pinned ? 'true' : 'false')
            .text(note.pinned ? translate('Unpin', 'noted') : translate('Pin', 'noted'));

        card.find('.noted-card__edit')
            .attr('data-note-id', note.id)
            .attr('data-markdown', note.markdown || '')
            .text(translate('Edit', 'noted'));

        card.find('.noted-card__delete')
            .attr('data-note-id', note.id)
            .text(translate('Delete', 'noted'));

        // Description is already wp_kses_post'd on the server and is the
        // only field we deliberately render as HTML.
        card.find('.noted-card__body').html(note.description);
        card.find('.noted-card__meta')
            .text(`${note.username || ''} · ${note.timestamp || ''}`);

        return card;
    }

    /**
     * Wire up per-note expand / edit / delete / pin handlers after a
     * render. Uses delegated `.off().on()` so repeated calls are safe.
     */
    function attachNoteEvents() {
        $('.noted-card--collapsible .noted-card__head')
            .off('click')
            .on('click', function (event) {
                if ($(event.target).closest('.noted-card__actions').length) {
                    return;
                }
                const card = $(this).closest('.noted-card');
                if (card.hasClass('is-editing')) {
                    return;
                }
                const expanded = !card.hasClass('is-expanded');
                card.toggleClass('is-expanded', expanded);
                const titleButton = card.find('.noted-card__head > .noted-card__title');
                if (titleButton.is('button')) {
                    titleButton.attr('aria-expanded', expanded ? 'true' : 'false');
                }
            });

        $('.noted-card__edit')
            .off('click')
            .on('click', function (event) {
                event.preventDefault();
                startEditing($(this).data('note-id'));
            });

        $('.noted-card__delete')
            .off('click')
            .on('click', function (event) {
                event.preventDefault();
                const noteId = $(this).data('note-id');
                if (!confirm(translate('Are you sure you want to delete this note?', 'noted'))) {
                    return;
                }
                window.notedApi.deleteNote(noteId).then(loadNotes).catch(logError);
            });

        $('.noted-card__pin')
            .off('click')
            .on('click', function (event) {
                event.preventDefault();
                const pinButton = $(this);
                const noteId    = pinButton.data('note-id');
                const wasPinned = pinButton.attr('aria-pressed') === 'true';
                window.notedApi.pinNote(noteId, !wasPinned).then(loadNotes).catch(logError);
            });
    }

    /**
     * Switch a note card into inline edit mode.
     *
     * @param {number} noteId
     */
    function startEditing(noteId) {
        const card     = $(`.noted-card[data-note-id='${noteId}']`).addClass('is-editing is-expanded');
        const editBtn  = card.find('.noted-card__edit');
        const markdown = editBtn.attr('data-markdown') || '';
        const originalTitle = card.find('.noted-card__title').text().trim();

        // Swap the title button for a span — putting an input inside a
        // <button> is invalid HTML, and edit mode disables the accordion
        // toggle anyway.
        const titleInput = $('<input type="text" class="widefat edit-note-title">').val(originalTitle);
        card.find('.noted-card__title')
            .replaceWith($('<span class="noted-card__title"></span>').append(titleInput));

        const newBody = $(`
            <div class="noted-card__body">
                <textarea class="widefat edit-note-description" rows="4"></textarea>
                <div class="edit-actions">
                    <button type="button" class="button button-small button-primary save-note"></button>
                    <button type="button" class="button button-small cancel-note"></button>
                </div>
            </div>
        `);
        newBody.find('.save-note').text(translate('Save', 'noted'));
        newBody.find('.cancel-note').text(translate('Cancel', 'noted'));
        newBody.find('.edit-note-description').val(markdown);
        card.find('.noted-card__body').replaceWith(newBody);

        card.find('.save-note').on('click', function () {
            window.notedApi
                .editNote(
                    noteId,
                    card.find('.edit-note-title').val(),
                    card.find('.edit-note-description').val(),
                )
                .then(loadNotes)
                .catch(logError);
        });
        card.find('.cancel-note').on('click', loadNotes);
    }

    /**
     * Console logger for API errors.
     *
     * @param {Error} error
     */
    function logError(error) {
        if (window.console && console.error) {
            console.error('[Noted!]', error);
        }
    }

    // Hide the add form for view-only users. We only act when notedConfig
    // explicitly says `can_edit: false` — a missing config indicates a
    // script-loading problem, not a permission downgrade, so we keep the
    // UI editable in that case.
    if (window.notedConfig && window.notedConfig.can_edit === false) {
        $('#noted-form').hide();
        $('#noted-panel').addClass('is-view-only');
    }

    loadNotes();

    if (window.notedApi && typeof window.notedApi.subscribe === 'function') {
        window.notedApi.subscribe(loadNotes);
    }
});

/**
 * Detect whether the WP admin bar is visible so the panel can compensate
 * for it with `margin-top`. Runs once on initial load.
 */
document.addEventListener('DOMContentLoaded', function () {
    const adminBar = document.getElementById('wpadminbar');
    if (!adminBar) {
        return;
    }
    if (window.getComputedStyle(adminBar).display === 'none') {
        return;
    }
    document.body.classList.add('has-visible-admin-bar');
});
