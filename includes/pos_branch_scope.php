<?php
/** Trusted single-branch context for operational POS cart and checkout work. */

require_once __DIR__ . '/branch_context.php';
require_once __DIR__ . '/pos_branch_scope_policy.php';

function printflow_pos_authorized_branch_id(): int
{
    $context = init_branch_context(true);
    return printflow_pos_branch_from_authorized_context($context);
}

