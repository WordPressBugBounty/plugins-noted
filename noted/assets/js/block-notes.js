/**
 * Noted! — Block-level notes.
 *
 * Adds `notedNote` (string) and `notedNoteUser` (string, last editor's
 * user_login) attributes to every block via the filter below. Server-
 * registered blocks need the same attrs in PHP too — see BlockNotes.php.
 * Notes are inline — they travel with the block when copy/pasted or saved.
 *
 * Surfaces:
 *   - Toolbar dropdown popover (mirrors the Link UI)
 *   - InspectorControls panel (right rail)
 *
 * View-only users see the existing note as plain text — they can read
 * but not edit. Users without edit access on a block that has no note
 * see no UI at all.
 *
 * Buildless: wp.element.createElement throughout.
 *
 * @module block-notes
 */
(function (wp, window) {
    if (!wp || !wp.hooks || !wp.element || !wp.compose || !wp.blockEditor || !wp.components) {
        return;
    }
    if (!window.notedIcons || !window.notedIcons.icon) {
        return;
    }

    const { addFilter } = wp.hooks;
    const { createElement: el, Fragment } = wp.element;
    const { createHigherOrderComponent } = wp.compose;
    const { InspectorControls, BlockControls } = wp.blockEditor;
    const { PanelBody, TextareaControl, ToolbarGroup, ToolbarButton, Dropdown } = wp.components;
    const { withNotification } = window.notedIcons;

    const translate =
        wp.i18n && typeof wp.i18n.__ === 'function'
            ? wp.i18n.__
            : function (text) {
                  return text;
              };

    /**
     * Resolve the current user's login from the editor's `core` store.
     *
     * @returns {string}
     */
    function currentUserLogin() {
        try {
            const user = wp.data && wp.data.select('core').getCurrentUser();
            return (user && user.slug) || '';
        } catch (_) {
            // The store may not be ready on first render or in unusual
            // mount orders; fall back to an empty string rather than
            // surface a TypeError to the block editor.
            return '';
        }
    }

    /**
     * Inject `notedNote` + `notedNoteUser` attributes into every block type.
     *
     * @param {Object} settings Block type settings.
     * @returns {Object}        The patched settings object.
     */
    function addNoteAttributes(settings) {
        if (!settings || typeof settings !== 'object') {
            return settings;
        }
        settings.attributes = Object.assign({}, settings.attributes || {}, {
            notedNote:     { type: 'string', default: '' },
            notedNoteUser: { type: 'string', default: '' },
        });
        return settings;
    }
    addFilter('blocks.registerBlockType', 'noted/add-note-attributes', addNoteAttributes);

    /**
     * Render the read-only display block used when the current user
     * lacks edit access on a block that already has a note.
     *
     * @param {string} note
     * @param {string} lastUser
     * @returns {React.ReactNode}
     */
    function readOnlyNote(note, lastUser) {
        return el(
            'div',
            { className: 'noted-block-note-readonly' },
            el('p', { className: 'noted-block-note-readonly__text' }, note),
            lastUser &&
                el(
                    'p',
                    { className: 'noted-block-note-readonly__meta' },
                    `${translate('Last edited by', 'noted')} ${lastUser}`,
                ),
        );
    }

    /**
     * Higher-order component that adds the toolbar popover and inspector
     * panel for the current block's note.
     *
     * @type {Function}
     */
    const withNotedControls = createHigherOrderComponent(function (BlockEdit) {
        return function (props) {
            const { attributes, setAttributes, isSelected } = props;
            const note     = (attributes && attributes.notedNote) || '';
            const lastUser = (attributes && attributes.notedNoteUser) || '';
            const hasNote  = note.trim().length > 0;
            const canEdit  = !!(
                window.notedApi &&
                window.notedApi.canEdit &&
                window.notedApi.canEdit()
            );

            // View-only users with nothing to view: stay out of the way.
            if (!canEdit && !hasNote) {
                return el(BlockEdit, props);
            }

            /**
             * Persist a new note value alongside the editing user's login.
             *
             * @param {string} value
             */
            function setNote(value) {
                setAttributes({
                    notedNote:     value,
                    notedNoteUser: currentUserLogin(),
                });
            }

            const lastEditedLine = lastUser
                ? el(
                      'p',
                      { className: 'noted-block-note-author' },
                      `${translate('Last edited by', 'noted')} ${lastUser}`,
                  )
                : null;

            const panelTitle = el(
                'span',
                { className: 'noted-panel-title' },
                translate('Block Note', 'noted'),
                hasNote &&
                    el('span', {
                        className: 'noted-dot noted-dot--inline',
                        'aria-hidden': 'true',
                    }),
            );

            const toolbarLabel = hasNote
                ? canEdit
                    ? translate('Edit block note', 'noted')
                    : translate('View block note', 'noted')
                : translate('Add block note', 'noted');

            const popoverContent = canEdit
                ? el(
                      Fragment,
                      null,
                      el(TextareaControl, {
                          label: translate('Block note', 'noted'),
                          help: translate('Only visible in the editor.', 'noted'),
                          value: note,
                          onChange: setNote,
                          rows: 5,
                          placeholder: translate('Add a note for this block…', 'noted'),
                          __nextHasNoMarginBottom: true,
                      }),
                      lastEditedLine,
                  )
                : readOnlyNote(note, lastUser);

            const toolbar =
                isSelected &&
                el(
                    BlockControls,
                    null,
                    el(
                        ToolbarGroup,
                        null,
                        el(Dropdown, {
                            contentClassName: 'noted-block-note-popover',
                            popoverProps: { placement: 'bottom-start' },
                            renderToggle: function (toggleProps) {
                                return el(ToolbarButton, {
                                    icon: withNotification(hasNote),
                                    label: toolbarLabel,
                                    onClick: toggleProps.onToggle,
                                    'aria-expanded': toggleProps.isOpen,
                                    isPressed: toggleProps.isOpen,
                                });
                            },
                            renderContent: function () {
                                return el(
                                    'div',
                                    { className: 'noted-block-note-popover__inner' },
                                    popoverContent,
                                );
                            },
                        }),
                    ),
                );

            const inspectorContent = canEdit
                ? el(
                      Fragment,
                      null,
                      el(TextareaControl, {
                          label: translate('Note', 'noted'),
                          help: translate('Only visible in the editor.', 'noted'),
                          value: note,
                          onChange: setNote,
                          rows: 5,
                          __nextHasNoMarginBottom: true,
                      }),
                      lastEditedLine,
                  )
                : readOnlyNote(note, lastUser);

            const inspector =
                isSelected &&
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        {
                            title: panelTitle,
                            initialOpen: hasNote,
                            className: 'noted-block-panel',
                        },
                        inspectorContent,
                    ),
                );

            return el(Fragment, null, el(BlockEdit, props), toolbar, inspector);
        };
    }, 'withNotedControls');
    addFilter('editor.BlockEdit', 'noted/with-block-note-controls', withNotedControls);
})(window.wp, window);
