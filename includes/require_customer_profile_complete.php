<?php
/**
 * Gate: require customers to finish their profile before ordering/checkout pages.
 * Include this on submission/checkout endpoints — not on browse-only order forms.
 */
require_once __DIR__ . '/customer_profile_completion.php';

if (printflow_customer_profile_incomplete()) {
    printflow_redirect_customer_to_complete_profile();
}
