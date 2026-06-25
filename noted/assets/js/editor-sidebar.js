/**
 * Noted! — Block-editor PluginSidebar.
 *
 * Registers a top-right icon (PluginSidebarMoreMenuItem) and a slide-out
 * panel (PluginSidebar). Inside: tabbed UI for Global vs. This Post notes.
 * Errors and confirmations surface via the editor's snackbar notices
 * (core/notices store), not inline UI.
 *
 * Buildless: wp.element.createElement throughout.
 *
 * @module editor-sidebar
 */
(function (wp, window) {
  if (!wp || !wp.plugins || !wp.element || !wp.data || !wp.components) {
    return;
  }
  if (!window.notedIcons || !window.notedIcons.icon) {
    return;
  }

  // PluginSidebar moved to @wordpress/editor in WP 6.6; fall back to the
  // edit-post namespace for older installs so the sidebar still mounts.
  const editorPackage = wp.editor || wp.editPost || {};
  const PluginSidebar = editorPackage.PluginSidebar;
  const PluginSidebarMoreMenuItem = editorPackage.PluginSidebarMoreMenuItem;
  if (!PluginSidebar || !PluginSidebarMoreMenuItem) {
    return;
  }

  const { registerPlugin } = wp.plugins;
  const { createElement: el, useState, useEffect, Fragment } = wp.element;
  const { TextControl, TextareaControl, Button, Spinner } = wp.components;
  const { select, dispatch } = wp.data;
  const { icon } = window.notedIcons;

  const translate =
    wp.i18n && typeof wp.i18n.__ === 'function'
      ? wp.i18n.__
      : function (text) {
          return text;
        };

  /**
   * Build the icon link to the Markdown reference tab on the Noted!
   * settings screen, or null when no URL was localised.
   *
   * @returns {React.ReactNode|null}
   */
  function markdownDocLinkElement() {
    const config = window.notedConfig || {};
    if (!config.markdown_doc_url) {
      return null;
    }
    return el(
      'a',
      {
        href: config.markdown_doc_url,
        className: 'noted-markdown-doc-link',
        target: '_blank',
        rel: 'noopener noreferrer',
        'aria-label': config.markdown_doc_aria_label || '',
        'data-tooltip': config.markdown_doc_tooltip || '',
      },
      el('span', {
        className: 'dashicons dashicons-info',
        'aria-hidden': 'true',
      }),
    );
  }

  /**
   * Build the description-field label as a fragment combining the
   * translated label with the Markdown reference icon link.
   *
   * @returns {React.ReactNode}
   */
  function descriptionFieldLabel() {
    return el(
      Fragment,
      null,
      translate('Description', 'noted'),
      ' ',
      markdownDocLinkElement(),
    );
  }

  /**
   * Push a notice into the editor's snackbar area.
   *
   * @param {('success'|'error'|'info'|'warning')} status
   * @param {string} message
   */
  function snackbar(status, message) {
    const noticesDispatch = dispatch('core/notices');
    if (!noticesDispatch) {
      return;
    }
    noticesDispatch.createNotice(status, message, {
      type: 'snackbar',
      isDismissible: true,
    });
  }

  /**
   * The notes UI rendered inside the PluginSidebar.
   *
   * @returns {React.ReactNode}
   */
  function NotesPanel() {
    const postId = select('core/editor')
      ? select('core/editor').getCurrentPostId()
      : 0;
    const canEdit = !!(
      window.notedApi &&
      window.notedApi.canEdit &&
      window.notedApi.canEdit()
    );

    const [scope, setScope] = useState('global');
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [notes, setNotes] = useState([]);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [editingId, setEditingId] = useState(0);
    const [editTitle, setEditTitle] = useState('');
    const [editBody, setEditBody] = useState('');
    const [expandedIds, setExpandedIds] = useState({});

    /**
     * Toggle the expansion state for a given note.
     *
     * @param {number} id
     */
    function toggleExpanded(id) {
      setExpandedIds(function (previous) {
        const next = Object.assign({}, previous);
        if (next[id]) {
          delete next[id];
        } else {
          next[id] = true;
        }
        return next;
      });
    }

    /**
     * Fetch the current scope's notes from the server.
     */
    function refresh() {
      setLoading(true);
      window.notedApi
        .fetchNotes(scope, postId)
        .then(function (data) {
          setNotes(Array.isArray(data) ? data : []);
        })
        .catch(function (error) {
          snackbar(
            'error',
            (error && error.message) || translate('Failed to load notes', 'noted'),
          );
        })
        .finally(function () {
          setLoading(false);
        });
    }

    useEffect(refresh, [scope, postId]);

    // Stay in sync with the floating panel / metabox / other tabs.
    useEffect(
      function () {
        if (!window.notedApi || typeof window.notedApi.subscribe !== 'function') {
          return undefined;
        }
        return window.notedApi.subscribe(refresh);
      },
      [scope, postId],
    );

    /**
     * Submit the add-note form.
     */
    function handleAdd() {
      if (!title && !description) {
        return;
      }
      setSaving(true);
      const attached = scope === 'post' ? postId : 0;
      window.notedApi
        .addNote(title, description, attached)
        .then(function () {
          setTitle('');
          setDescription('');
          snackbar('success', translate('Note added', 'noted'));
          refresh();
        })
        .catch(function (error) {
          snackbar(
            'error',
            (error && error.message) || translate('Failed to save note', 'noted'),
          );
        })
        .finally(function () {
          setSaving(false);
        });
    }

    /**
     * Enter inline edit mode for an existing note.
     *
     * @param {Object} note
     */
    function startEditing(note) {
      setEditingId(note.id);
      setEditTitle(note.title || '');
      setEditBody(note.markdown || '');
    }

    /**
     * Cancel inline editing without saving.
     */
    function cancelEditing() {
      setEditingId(0);
      setEditTitle('');
      setEditBody('');
    }

    /**
     * Persist the inline edit and refresh.
     */
    function saveEditing() {
      const noteId = editingId;
      window.notedApi
        .editNote(noteId, editTitle, editBody)
        .then(function () {
          snackbar('success', translate('Note updated', 'noted'));
          cancelEditing();
          refresh();
        })
        .catch(function (error) {
          snackbar(
            'error',
            (error && error.message) || translate('Failed to update note', 'noted'),
          );
        });
    }

    /**
     * Delete a note (with confirmation).
     *
     * @param {number} noteId
     */
    function handleDelete(noteId) {
      if (!confirm(translate('Delete this note?', 'noted'))) {
        return;
      }
      window.notedApi
        .deleteNote(noteId)
        .then(function () {
          snackbar('success', translate('Note deleted', 'noted'));
          refresh();
        })
        .catch(function (error) {
          snackbar(
            'error',
            (error && error.message) || translate('Failed to delete note', 'noted'),
          );
        });
    }

    /**
     * Toggle the pinned state of a note.
     *
     * @param {Object} note
     */
    function togglePin(note) {
      const next = !note.pinned;
      window.notedApi
        .pinNote(note.id, next)
        .then(function () {
          snackbar(
            'success',
            next ? translate('Note pinned', 'noted') : translate('Note unpinned', 'noted'),
          );
          refresh();
        })
        .catch(function (error) {
          snackbar(
            'error',
            (error && error.message) || translate('Failed to update note', 'noted'),
          );
        });
    }

    const tabs = el(
      'div',
      { className: 'noted-editor-tabs' },
      ['global', 'post'].map(function (scopeKey) {
        return el(
          'button',
          {
            key: scopeKey,
            type: 'button',
            className: `noted-editor-tab${scope === scopeKey ? ' is-active' : ''}`,
            onClick: function () {
              setScope(scopeKey);
            },
          },
          scopeKey === 'global'
            ? translate('Global', 'noted')
            : translate('This Post', 'noted'),
        );
      }),
    );

    const form =
      canEdit &&
      el(
        'div',
        { className: 'noted-editor-form' },
        el(TextControl, {
          label: translate('Title', 'noted'),
          value: title,
          onChange: setTitle,
          __next40pxDefaultSize: true,
          __nextHasNoMarginBottom: true,
        }),
        el(TextareaControl, {
          label: descriptionFieldLabel(),
          value: description,
          onChange: setDescription,
          rows: 8,
          __nextHasNoMarginBottom: true,
        }),
        el(
          Button,
          {
            variant: 'primary',
            onClick: handleAdd,
            isBusy: saving,
            disabled: saving || (!title && !description),
          },
          translate('Add Note', 'noted'),
        ),
      );

    const emptyMessage =
      scope === 'post'
        ? translate('No notes on this post yet.', 'noted')
        : translate('No global notes yet.', 'noted');

    const list = loading
      ? el(Spinner, null)
      : notes.length === 0
        ? el('p', { className: 'noted-empty' }, emptyMessage)
        : el('div', { className: 'noted-editor-list' }, notes.map(renderNote));

    /**
     * Render a single note card. Switches into an inline form when
     * `editingId` matches the note's ID.
     *
     * @param {Object} note Note as returned from the API.
     * @returns {React.ReactNode}
     */
    function renderNote(note) {
      const isEditing  = editingId === note.id;
      const isExpanded = isEditing || !!expandedIds[note.id];

      if (isEditing) {
        return renderEditingCard(note);
      }

      return renderReadOnlyCard(note, isExpanded);
    }

    /**
     * Render the inline-edit form for a note.
     *
     * @param {Object} note
     * @returns {React.ReactNode}
     */
    function renderEditingCard(note) {
      return el(
        'article',
        {
          'key': note.id,
          'className': 'noted-card noted-card--collapsible is-editing is-expanded',
          'data-note-id': note.id,
        },
        el(
          'header',
          { className: 'noted-card__head' },
          el(
            'span',
            { className: 'noted-card__title' },
            el('input', {
              type: 'text',
              className: 'widefat',
              value: editTitle,
              onChange: function (event) {
                setEditTitle(event.target.value);
              },
            }),
          ),
        ),
        el(
          'div',
          { className: 'noted-card__collapsible' },
          el(
            'div',
            { className: 'noted-card__edit-form' },
            el(TextareaControl, {
              label: descriptionFieldLabel(),
              value: editBody,
              onChange: setEditBody,
              rows: 8,
              __nextHasNoMarginBottom: true,
            }),
            el(
              'div',
              { className: 'noted-card__edit-actions' },
              el(Button, { variant: 'primary', onClick: saveEditing }, translate('Save', 'noted')),
              el(Button, { variant: 'tertiary', onClick: cancelEditing }, translate('Cancel', 'noted')),
            ),
          ),
          el(
            'footer',
            { className: 'noted-card__meta' },
            `${note.username} · ${note.timestamp}`,
          ),
        ),
      );
    }

    /**
     * Render the read-only card for a note.
     *
     * @param {Object} note
     * @param {boolean} isExpanded
     * @returns {React.ReactNode}
     */
    function renderReadOnlyCard(note, isExpanded) {
      const collapsibleId = `noted-collapsible-${note.id}`;
      const toggleId      = `noted-toggle-${note.id}`;

      const className =
        'noted-card noted-card--collapsible' +
        (isExpanded ? ' is-expanded' : '') +
        (note.pinned ? ' is-pinned' : '');

      return el(
        'article',
        {
          'key': note.id,
          'className': className,
          'data-note-id': note.id,
        },
        el(
          'header',
          { className: 'noted-card__head' },
          el(
            'button',
            {
              'type': 'button',
              'id': toggleId,
              'className': 'noted-card__title',
              'aria-expanded': isExpanded ? 'true' : 'false',
              'aria-controls': collapsibleId,
              'onClick': function () {
                toggleExpanded(note.id);
              },
            },
            note.title || translate('(untitled)', 'noted'),
          ),
        ),
        el(
          'div',
          {
            'className': 'noted-card__collapsible',
            'id': collapsibleId,
            'role': 'region',
            'aria-labelledby': toggleId,
          },
          el('div', {
            className: 'noted-card__body',
            dangerouslySetInnerHTML: { __html: note.description },
          }),
          canEdit && renderActions(note),
          el(
            'footer',
            { className: 'noted-card__meta' },
            `${note.username} · ${note.timestamp}`,
          ),
        ),
      );
    }

    /**
     * Render the pin / edit / delete action bar for a note.
     *
     * @param {Object} note
     * @returns {React.ReactNode}
     */
    function renderActions(note) {
      return el(
        'div',
        { className: 'noted-card__actions' },
        el(
          Button,
          {
            variant: 'link',
            onClick: function () {
              togglePin(note);
            },
          },
          note.pinned ? translate('Unpin', 'noted') : translate('Pin', 'noted'),
        ),
        el(
          Button,
          {
            variant: 'link',
            onClick: function () {
              startEditing(note);
            },
          },
          translate('Edit', 'noted'),
        ),
        el(
          Button,
          {
            variant: 'link',
            isDestructive: true,
            onClick: function () {
              handleDelete(note.id);
            },
          },
          translate('Delete', 'noted'),
        ),
      );
    }

    return el(
      'div',
      { className: 'noted-editor-panel' },
      tabs,
      form,
      el('hr', { className: 'noted-editor-sep' }),
      list,
    );
  }

  registerPlugin('noted-sidebar', {
    icon: icon,
    render: function () {
      return el(
        Fragment,
        null,
        el(
          PluginSidebarMoreMenuItem,
          {
            target: 'noted-sidebar',
            icon: icon,
          },
          'Noted!',
        ),
        el(
          PluginSidebar,
          {
            name: 'noted-sidebar',
            title: 'Noted!',
            icon: icon,
          },
          el(NotesPanel),
        ),
      );
    },
  });
})(window.wp, window);
