<?php

namespace ReferencePlugin;

/** Free REST callbacks, CLI command and ability of the fixture plugin. */
class Free_Api
{
    public function items($request)
    {
        return [];
    }

    public function can_read()
    {
        return current_user_can('edit_posts');
    }

    /**
     * Sync items.
     *
     * ## OPTIONS
     *
     * <id>
     * : Item id.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * [--dry-run]
     * : Do not write.
     */
    public function sync($args, $assoc_args)
    {
    }

    public function ability($input)
    {
        return [];
    }
}
