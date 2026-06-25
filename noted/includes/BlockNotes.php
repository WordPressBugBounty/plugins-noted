<?php

declare(strict_types=1);

namespace Noted;

/**
 * Fix: declare block note attributes for server-registered blocks.
 *
 * Block notes are normally attached in JS (blocks.registerBlockType in
 * block-notes.js). Dynamic / server-rendered blocks also require the same
 * attributes in PHP or REST and ServerSideRender reject unknown attrs.
 */
final class BlockNotes
{
    public const ATTR_NOTE      = 'notedNote';
    public const ATTR_NOTE_USER = 'notedNoteUser';

    /**
     * Register WordPress hooks.
     */
    public function register(): void
    {
        add_filter('register_block_type_args', [$this, 'addNoteAttributes'], 10, 2);
    }

    /**
     * Add notedNote + notedNoteUser to each block type registered in PHP.
     *
     * @param array<string, mixed> $args       Block type registration arguments.
     * @param string               $block_type Block name (e.g. core/paragraph).
     * @return array<string, mixed>
     */
    public function addNoteAttributes(array $args, string $_block_type): array
    {
        if (! isset($args['attributes']) || ! is_array($args['attributes'])) {
            $args['attributes'] = [];
        }

        $args['attributes'][self::ATTR_NOTE] = [
            'type'    => 'string',
            'default' => '',
        ];
        $args['attributes'][self::ATTR_NOTE_USER] = [
            'type'    => 'string',
            'default' => '',
        ];

        return $args;
    }
}
