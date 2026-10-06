<?php
/** @var array $context */
/** @var string $error */
/** @var string $form_id_prefix */
/** @var string $app_base */

$form_id_prefix = $form_id_prefix ?? 'pfRate';
$order_id = (int)($context['order_id'] ?? 0);
$order_code = (string)($context['order_code'] ?? '');
$service_type_label = (string)($context['service_type_label'] ?? '');
$needs_message_update = !empty($context['needs_message_update']);
$existing_rating = (int)($context['existing_rating'] ?? 0);
$existing_message = (string)($context['existing_message'] ?? '');
$error = (string)($error ?? '');
$app_base = $app_base ?? (function_exists('pf_app_base_path') ? pf_app_base_path() : '');
$action_url = $app_base . '/customer/rate_order.php?order_id=' . $order_id;
?>
<div class="pf-rate-form-root" data-order-id="<?php echo $order_id; ?>">
    <?php if ($error !== ''): ?>
        <div class="rate-error" role="alert"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="rate-info-grid">
        <div class="rate-info-item">
            <span class="rate-info-label">Order</span>
            <div class="rate-info-value"><?php echo htmlspecialchars($order_code); ?></div>
        </div>
        <div class="rate-info-item">
            <span class="rate-info-label">Service</span>
            <div class="rate-info-value"><?php echo htmlspecialchars($service_type_label); ?></div>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" class="pf-rate-order-form" action="<?php echo htmlspecialchars($action_url); ?>" data-rate-form="1">
        <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
        <input type="hidden" class="pf-rate-input" name="rating" value="<?php echo $needs_message_update ? $existing_rating : ''; ?>">
        <?php echo csrf_field(); ?>

        <div class="rate-columns">
            <div class="rate-panel">
                <label class="rate-label">Star Rating <span style="color:#ef4444">*</span></label>
                <div class="rate-stars pf-rate-stars" role="group" aria-label="Star rating">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <button type="button" class="rate-star-btn pf-rate-star <?php echo ($needs_message_update && $i <= $existing_rating) ? 'active' : ''; ?>" data-value="<?php echo $i; ?>" aria-label="<?php echo $i; ?> stars">&#9733;</button>
                    <?php endfor; ?>
                </div>

                <div style="margin-top:1.5rem;">
                    <label class="rate-label">Add Photos (Max 5)</label>
                    <div class="upload-grid pf-rate-image-grid">
                        <label class="upload-box pf-rate-add-image">
                            <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                            <span style="font-size: 10px; margin-top:4px; font-family: inherit;">Add Photo</span>
                            <input type="file" name="review_images[]" class="pf-rate-image-input" multiple accept="image/*" style="display:none">
                        </label>
                    </div>
                </div>

                <div style="margin-top:2rem;">
                    <label class="rate-label">Add Video (Max 1 MP4, 15MB)</label>
                    <div class="pf-rate-video-wrap">
                        <label class="upload-box pf-rate-add-video" style="width: 100px; height: 100px;">
                            <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                            <span style="font-size: 10px; margin-top:4px; font-family: inherit;">Add Video</span>
                            <input type="file" name="review_video" class="pf-rate-video-input" accept="video/mp4" style="display:none">
                        </label>
                        <div class="pf-rate-video-preview" style="display:none; margin-top:10px;">
                            <div style="position:relative; width:100%; max-width:240px; aspect-ratio:16/9; border-radius:12px; overflow:hidden; border:1px solid #cbd5e1; background:#f8fafc;">
                                <video class="pf-rate-video-player" controls style="width:100%; height:100%; object-fit:cover;"></video>
                                <button type="button" class="remove-btn pf-rate-video-remove" style="top:8px; right:8px; width:24px; height:24px; font-family: inherit;">&times;</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rate-panel">
                <label class="rate-label" for="<?php echo $form_id_prefix; ?>Message">Write a Review <span style="color:#ef4444">*</span></label>
                <textarea id="<?php echo $form_id_prefix; ?>Message" class="rate-textarea pf-rate-message" name="message" required maxlength="500" placeholder="Tell us about the print quality, the service, or anything you liked... (5-500 characters)"><?php echo htmlspecialchars($needs_message_update ? $existing_message : ''); ?></textarea>
                <div class="pf-rate-char-count" style="text-align: right; font-size: 11.5px; color: #64748b; margin-top: 6px; font-family: inherit; font-weight: 600;"><?php echo strlen($needs_message_update ? $existing_message : ''); ?> / 500</div>

                <div class="rate-actions" style="justify-content: flex-end; margin-top: 2rem;">
                    <button type="button" class="rate-btn-secondary pf-rate-cancel"><?php echo $needs_message_update ? 'Cancel' : 'Skip for now'; ?></button>
                    <button type="submit" class="rate-btn-primary pf-rate-submit"><?php echo $needs_message_update ? 'Update Review' : 'Submit Review'; ?></button>
                </div>
            </div>
        </div>
    </form>
</div>
