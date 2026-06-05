<?php
/**
 * Customer profile completion helpers: banner, submission guard, redirects.
 */

function printflow_profile_completion_required_message(): string
{
    return 'Please complete your Customer Account before submitting an inquiry or placing an order.';
}

/**
 * Per-tab completion for the Customer Account profile page.
 *
 * @return array<string, array{complete: bool, label: string}>
 */
function printflow_customer_account_tab_status($customer = null): array
{
    if ($customer === null) {
        $customer_id = get_user_id();
        if (!$customer_id || get_user_type() !== 'Customer') {
            return [
                'section-profile' => ['complete' => true, 'label' => 'Personal Information'],
                'section-address' => ['complete' => true, 'label' => 'Address'],
                'section-account' => ['complete' => true, 'label' => 'Account Management'],
                'section-security' => ['complete' => true, 'label' => 'Security & Verification'],
            ];
        }
        $rows = db_query('SELECT * FROM customers WHERE customer_id = ? LIMIT 1', 'i', [(int)$customer_id]);
        $customer = $rows[0] ?? [];
    }

    $customer_id = (int)($customer['customer_id'] ?? get_user_id());
    $personal = customer_profile_completion_status($customer_id);

    $address_complete =
        trim((string)($customer['province'] ?? '')) !== ''
        && trim((string)($customer['city'] ?? '')) !== ''
        && trim((string)($customer['barangay'] ?? '')) !== ''
        && trim((string)($customer['street_address'] ?? '')) !== '';

    $is_google = strtolower(trim((string)($customer['auth_provider'] ?? ''))) === 'google';
    $has_password = function_exists('printflow_customer_has_usable_password_hash')
        && printflow_customer_has_usable_password_hash($customer['password_hash'] ?? null);
    $account_complete = !$is_google || $has_password;

    $id_status = trim((string)($customer['id_status'] ?? 'None'));
    $security_complete = ($id_status === 'Verified');

    return [
        'section-profile' => ['complete' => (bool)$personal['complete'], 'label' => 'Personal Information'],
        'section-address' => ['complete' => $address_complete, 'label' => 'Address'],
        'section-account' => ['complete' => $account_complete, 'label' => 'Account Management'],
        'section-security' => ['complete' => $security_complete, 'label' => 'Security & Verification'],
    ];
}

function printflow_first_incomplete_customer_account_section($customer = null): ?string
{
    foreach (printflow_customer_account_tab_status($customer) as $section => $info) {
        if (empty($info['complete'])) {
            return $section;
        }
    }
    return null;
}

function printflow_customer_account_ready_for_order($customer = null): bool
{
    return printflow_first_incomplete_customer_account_section($customer) === null;
}

function printflow_customer_profile_incomplete(): bool
{
    return get_user_type() === 'Customer' && !printflow_customer_account_ready_for_order();
}

/**
 * Actions that submit an inquiry or start placing an order (not browse-only).
 */
function printflow_is_order_submission_action(string $action): bool
{
    static $actions = [
        'inquire_now',
        'buy_now',
        'place_order',
        'submit_order',
        'order_now',
    ];
    return in_array(strtolower(trim($action)), $actions, true);
}

function printflow_profile_completion_return_url(): string
{
    $current_path = $_SERVER['REQUEST_URI'] ?? '';
    if (!is_string($current_path) || $current_path === '') {
        return '';
    }
    $path = parse_url($current_path, PHP_URL_PATH);
    if (!is_string($path) || !preg_match('#^(/[^/?#]+)?/customer/[A-Za-z0-9_\-/]+\.php$#', $path)) {
        return '';
    }
    $query = parse_url($current_path, PHP_URL_QUERY);
    return $path . ($query ? '?' . $query : '');
}

function printflow_redirect_customer_to_complete_profile(?string $return_to = null, ?string $section = null, ?string $message = null): void
{
    if ($return_to === null) {
        $return_to = printflow_profile_completion_return_url();
    }
    if ($section === null || $section === '') {
        $section = printflow_first_incomplete_customer_account_section();
    }
    if ($return_to !== '') {
        $_SESSION['profile_return_after_complete'] = $return_to;
    }
    $_SESSION['profile_completion_flash'] = $message !== null && $message !== ''
        ? $message
        : printflow_profile_completion_required_message();

    $target = rtrim(AUTH_REDIRECT_BASE, '/') . '/customer/profile.php?complete_profile=1';
    if ($return_to !== '') {
        $target .= '&return=' . rawurlencode($return_to);
    }
    if ($section !== null && $section !== '') {
        $target .= '#' . preg_replace('/[^A-Za-z0-9_\-]/', '', $section);
    }

    header('Location: ' . $target, true, 302);
    exit;
}

function printflow_block_order_submission_if_profile_incomplete(?string $return_to = null): void
{
    if (!printflow_customer_profile_incomplete()) {
        return;
    }
    printflow_redirect_customer_to_complete_profile($return_to);
}

function printflow_consume_profile_completion_flash(): string
{
    $message = trim((string)($_SESSION['profile_completion_flash'] ?? ''));
    if ($message !== '') {
        unset($_SESSION['profile_completion_flash']);
    }
    return $message;
}

function printflow_render_customer_profile_incomplete_banner(): void
{
    if (!printflow_customer_profile_incomplete()) {
        return;
    }

    $profile_url = rtrim(AUTH_REDIRECT_BASE, '/') . '/customer/profile.php';
    ?>
    <div class="pf-profile-incomplete-banner" role="status" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:0.75rem;padding:1rem 1.25rem;margin-bottom:1.5rem;">
        <div style="flex:1;min-width:220px;font-size:0.9375rem;line-height:1.5;">
            <strong style="display:block;margin-bottom:0.25rem;">Your Customer Account is incomplete.</strong>
            Please complete every section marked with ! in your profile before submitting an inquiry or placing an order.
        </div>
        <a href="<?php echo htmlspecialchars($profile_url, ENT_QUOTES, 'UTF-8'); ?>" class="shopee-btn-primary" style="flex-shrink:0;white-space:nowrap;padding:0.55rem 1.25rem;text-decoration:none;">
            Complete Profile
        </a>
    </div>
    <?php
}
