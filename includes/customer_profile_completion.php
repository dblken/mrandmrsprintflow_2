<?php
/**
 * Customer profile completion helpers: banner, submission guard, redirects.
 */

function printflow_profile_completion_required_message(): string
{
    return 'Please complete your profile before submitting an inquiry or placing an order.';
}

function printflow_customer_profile_incomplete(): bool
{
    return get_user_type() === 'Customer' && !is_profile_complete();
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

function printflow_redirect_customer_to_complete_profile(?string $return_to = null): void
{
    if ($return_to === null) {
        $return_to = printflow_profile_completion_return_url();
    }
    if ($return_to !== '') {
        $_SESSION['profile_return_after_complete'] = $return_to;
    }
    $_SESSION['profile_completion_flash'] = printflow_profile_completion_required_message();

    $target = rtrim(AUTH_REDIRECT_BASE, '/') . '/customer/profile.php?complete_profile=1';
    if ($return_to !== '') {
        $target .= '&return=' . rawurlencode($return_to);
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
            <strong style="display:block;margin-bottom:0.25rem;">Your profile is incomplete.</strong>
            Please complete your profile before submitting an inquiry or placing an order.
        </div>
        <a href="<?php echo htmlspecialchars($profile_url, ENT_QUOTES, 'UTF-8'); ?>" class="shopee-btn-primary" style="flex-shrink:0;white-space:nowrap;padding:0.55rem 1.25rem;text-decoration:none;">
            Complete Profile
        </a>
    </div>
    <?php
}
