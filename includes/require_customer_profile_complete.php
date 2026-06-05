<?php
/**
 * Gate: require customers to finish their profile before ordering/checkout pages.
 * Include this on submission/checkout endpoints — not on browse-only order forms.
 */
require_once __DIR__ . '/customer_profile_completion.php';

if (get_user_type() === 'Customer') {
    $incomplete_section = printflow_first_incomplete_customer_account_section();
    if ($incomplete_section !== null) {
        printflow_redirect_customer_to_complete_profile(null, $incomplete_section);
    }
}
