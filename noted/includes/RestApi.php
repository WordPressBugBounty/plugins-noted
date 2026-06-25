<?php

declare(strict_types=1);

namespace Noted;

use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST endpoints for note CRUD operations.
 *
 * Routes (all under `/wp-json/noted/v1`):
 *   GET    /notes              List notes; requires ?scope=post|global (post needs post_id>0)
 *   POST   /notes              Create a note
 *   PUT    /notes/{id}         Partial update (title, description, pinned)
 *   DELETE /notes/{id}         Delete a note
 *
 * Authentication relies on the standard cookie + `X-WP-Nonce` header (the
 * same `wp_rest` nonce the block editor uses). Capability gating runs in
 * {@see RestApi::permissionsView()} and {@see RestApi::permissionsEdit()}.
 * Every mutating endpoint additionally re-validates that the target post
 * is actually a note — REST args alone are not a substitute for an
 * authoritative check at the data layer.
 */
final class RestApi
{
    public const NAMESPACE = 'noted/v1';

    public function __construct(private Settings $settings)
    {
    }

    /**
     * Register WordPress hooks.
     */
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Define every route in the namespace.
     */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/notes', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'listNotes'],
                'permission_callback' => [$this, 'permissionsView'],
                'args'                => [
                    'scope'   => [
                        'required'          => true,
                        'type'              => 'string',
                        'enum'              => ['global', 'post'],
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'post_id' => [
                        'type'              => 'integer',
                        'default'           => 0,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'createNote'],
                'permission_callback' => [$this, 'permissionsEdit'],
                'args'                => [
                    'title'            => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'description'      => [
                        'type'    => 'string',
                        'default' => '',
                    ],
                    'attached_post_id' => [
                        'type'              => 'integer',
                        'default'           => 0,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/notes/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'updateNote'],
                'permission_callback' => [$this, 'permissionsEdit'],
                'args'                => [
                    'id'          => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                    'title'       => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'description' => [
                        'type' => 'string',
                    ],
                    'pinned'      => [
                        'type' => 'boolean',
                    ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'deleteNote'],
                'permission_callback' => [$this, 'permissionsEdit'],
                'args'                => [
                    'id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Permission gate for read endpoints.
     */
    public function permissionsView(): bool
    {
        return $this->settings->userCanView();
    }

    /**
     * Permission gate for write endpoints.
     */
    public function permissionsEdit(): bool
    {
        return $this->settings->userCanEdit();
    }

    /**
     * GET /notes — return a JSON array of notes for the given scope.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function listNotes(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $scope  = (string) $request->get_param('scope');
        $postId = (int) $request->get_param('post_id');

        if ($scope === 'post' && $postId <= 0) {
            return new WP_Error(
                'noted_post_scope_requires_post_id',
                __('Fetching post-scoped notes requires a valid post ID.', 'noted'),
                ['status' => 400]
            );
        }

        $query   = new WP_Query($this->listQueryArgs($scope, $postId));
        $visible = PostType::filterRenderable($query->posts);
        $sorted  = PostType::sortPinnedFirst($visible);

        return rest_ensure_response(array_map([PostType::class, 'format'], $sorted));
    }

    /**
     * POST /notes — create a new note from a title + markdown body.
     *
     * Rejects empty payloads (both title and description blank) and rejects
     * `attached_post_id` values that point at posts the current user cannot
     * edit. The latter prevents an editor from attaching notes to content
     * they do not own.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function createNote(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $title          = (string) $request->get_param('title');
        $description    = (string) $request->get_param('description');
        $attachedPostId = (int) $request->get_param('attached_post_id');

        if ($title === '' && trim($description) === '') {
            return new WP_Error(
                'noted_empty_note',
                __('A note needs either a title or a body.', 'noted'),
                ['status' => 400]
            );
        }

        if ($attachedPostId > 0) {
            $attachmentError = $this->validateAttachedPost($attachedPostId);
            if ($attachmentError instanceof WP_Error) {
                return $attachmentError;
            }
        }

        $user   = wp_get_current_user();
        $noteId = wp_insert_post([
            'post_type'    => PostType::SLUG,
            'post_title'   => $title,
            'post_content' => Markdown::toHtml($description),
            'post_status'  => 'publish',
            'post_author'  => $user->ID,
        ], true);

        if (is_wp_error($noteId) || ! $noteId) {
            return new WP_Error(
                'noted_create_failed',
                __('Failed to save note', 'noted'),
                ['status' => 500]
            );
        }

        update_post_meta($noteId, PostType::META_TIMESTAMP, current_time('mysql'));
        update_post_meta($noteId, PostType::META_USERNAME, $user->user_login);
        update_post_meta($noteId, PostType::META_MARKDOWN, $description);
        if ($attachedPostId > 0) {
            update_post_meta($noteId, PostType::META_ATTACHED_POST_ID, $attachedPostId);
        }

        return rest_ensure_response(PostType::format(get_post($noteId)));
    }

    /**
     * PUT /notes/{id} — partial update of an existing note.
     *
     * Title, description and pinned may each be sent independently;
     * unspecified fields stay untouched so callers can toggle one
     * attribute without round-tripping the full payload.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function updateNote(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $noteId = (int) $request->get_param('id');

        $note = $this->resolveNote($noteId);
        if ($note instanceof WP_Error) {
            return $note;
        }

        $postUpdates = ['ID' => $noteId];
        if ($request->has_param('title')) {
            $postUpdates['post_title'] = (string) $request->get_param('title');
        }

        $descriptionProvided = $request->has_param('description');
        $description         = $descriptionProvided ? (string) $request->get_param('description') : '';
        if ($descriptionProvided) {
            $postUpdates['post_content'] = Markdown::toHtml($description);
        }

        if (count($postUpdates) > 1) {
            $updated = wp_update_post($postUpdates, true);
            if (is_wp_error($updated) || ! $updated) {
                return new WP_Error(
                    'noted_update_failed',
                    __('Failed to update note', 'noted'),
                    ['status' => 500]
                );
            }
        }

        if ($descriptionProvided) {
            update_post_meta($noteId, PostType::META_MARKDOWN, $description);
        }

        if ($request->has_param('pinned')) {
            $this->writePinnedState($noteId, (bool) $request->get_param('pinned'));
        }

        return rest_ensure_response(PostType::format(get_post($noteId)));
    }

    /**
     * DELETE /notes/{id}.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function deleteNote(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $noteId = (int) $request->get_param('id');

        $note = $this->resolveNote($noteId);
        if ($note instanceof WP_Error) {
            return $note;
        }

        if (! wp_delete_post($noteId, true)) {
            return new WP_Error(
                'noted_delete_failed',
                __('Failed to delete note', 'noted'),
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'deleted' => true,
            'id'      => $noteId,
        ]);
    }

    /**
     * Build the WP_Query arguments for a list request.
     *
     * Caller must supply {@see $scope} `global` or `post`; with `post`,
     * {@see $postId} must already be validated as greater than zero.
     *
     * @return array<string, mixed>
     */
    private function listQueryArgs(string $scope, int $postId): array
    {
        $base = [
            'post_type'      => PostType::SLUG,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ];

        if ($scope === 'post') {
            $base['meta_query'] = [[
                'key'   => PostType::META_ATTACHED_POST_ID,
                'value' => $postId,
                'type'  => 'NUMERIC',
            ]];

            return $base;
        }

        // $scope === 'global'
        $base['meta_query'] = [
            'relation' => 'OR',
            ['key' => PostType::META_ATTACHED_POST_ID, 'compare' => 'NOT EXISTS'],
            ['key' => PostType::META_ATTACHED_POST_ID, 'value' => 0, 'type' => 'NUMERIC'],
        ];

        return $base;
    }

    /**
     * Load a note by ID, returning a WP_Error if the post does not exist
     * or is not actually a note. Prevents callers from mutating arbitrary
     * post types through the note endpoints.
     *
     * @return WP_Post|WP_Error
     */
    private function resolveNote(int $noteId): WP_Post|WP_Error
    {
        $post = get_post($noteId);
        if (! $post instanceof WP_Post || $post->post_type !== PostType::SLUG) {
            return new WP_Error(
                'noted_note_not_found',
                __('Note not found.', 'noted'),
                ['status' => 404]
            );
        }
        return $post;
    }

    /**
     * Validate that the current user is allowed to attach a note to the
     * supplied post — the post must exist, must not itself be a note, and
     * the user must be able to edit it.
     */
    private function validateAttachedPost(int $attachedPostId): ?WP_Error
    {
        $target = get_post($attachedPostId);
        if (! $target instanceof WP_Post || $target->post_type === PostType::SLUG) {
            return new WP_Error(
                'noted_attached_post_invalid',
                __('Attached post not found.', 'noted'),
                ['status' => 400]
            );
        }

        if (! current_user_can('edit_post', $attachedPostId)) {
            return new WP_Error(
                'noted_attached_post_forbidden',
                __('You cannot attach a note to that post.', 'noted'),
                ['status' => 403]
            );
        }

        return null;
    }

    /**
     * Persist (or clear) the pinned flag for a note.
     */
    private function writePinnedState(int $noteId, bool $pinned): void
    {
        if ($pinned) {
            update_post_meta($noteId, PostType::META_PINNED, '1');
            return;
        }
        delete_post_meta($noteId, PostType::META_PINNED);
    }
}
