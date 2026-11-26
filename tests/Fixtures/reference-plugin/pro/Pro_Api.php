<?php

namespace ReferencePlugin\Pro;

/** Pro-only callbacks of the fixture plugin. */
class Pro_Api
{
    public function export($request)
    {
        return [];
    }

    public function can_manage()
    {
        return current_user_can('manage_options');
    }

    /**
     * Export everything.
     */
    public function export_all($args, $assoc_args)
    {
    }
}
