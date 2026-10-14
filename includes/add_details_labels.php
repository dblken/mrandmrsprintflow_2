<?php
/**
 * User-facing labels for the Request Add Details workflow.
 * Internal DB values, API keys, and routes keep legacy "revision" naming.
 */

if (!function_exists('printflow_add_details_status_label')) {
    /**
     * Map stored status / design_status values to customer- and staff-facing text.
     */
    function printflow_add_details_status_label(string $internalStatus): string
    {
        $key = trim($internalStatus);
        $map = [
            'For Revision' => 'Request Add Details',
            'Revision Requested' => 'Request Add Details',
            'Revision Submitted' => 'Request Add Details Submitted',
            'Customer Updating Details' => 'Customer Updating Request Add Details',
            'Resubmitted for Review' => 'Request Add Details Submitted',
            'Staff Reviewing' => 'Reviewing Request Add Details',
        ];
        return $map[$key] ?? $key;
    }
}

if (!function_exists('printflow_add_details_replace_phrases')) {
    /**
     * Replace legacy revision phrasing in a user-visible string.
     */
    function printflow_add_details_replace_phrases(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $replacements = [
            'Revision Request Unavailable' => 'Request Add Details Unavailable',
            'Revision Request Error' => 'Request Add Details Error',
            'Revise Order #' => 'Request Add Details — Order #',
            'Revise Order' => 'Request Add Details',
            'Reason for Revision' => 'Reason for Request Add Details',
            'Revision Comparison' => 'Request Add Details Comparison',
            'Revision Re-submitted' => 'Request Add Details Submitted',
            'Revision Requested' => 'Request Add Details',
            'Revision Request Unavailable' => 'Request Add Details Unavailable',
            'Revision Files' => 'Additional Design Files',
            'Submit Updated Details' => 'Submit Request Add Details',
            'Submitting Updates…' => 'Submitting Request Add Details…',
            'Submit Revision' => 'Submit Request Add Details',
            'Respond to Revision' => 'Respond to Request Add Details',
            'Revision Details' => 'Request Add Details',
            'revised design' => 'design file(s) for Request Add Details',
            'revised details' => 'Request Add Details response',
            'revision request' => 'Request Add Details',
            'revision was already submitted' => 'Request Add Details was already submitted',
            'revision request is no longer open' => 'Request Add Details is no longer open',
            'revision request exists' => 'Request Add Details exists',
            'revision status' => 'Request Add Details status',
            'resubmitted for staff review' => 'submitted for staff review (Request Add Details)',
            'open for revision' => 'open for Request Add Details',
            'needs revision' => 'needs Request Add Details',
        ];
        $out = $text;
        foreach ($replacements as $from => $to) {
            $out = str_ireplace($from, $to, $out);
        }
        return $out;
    }
}

if (!function_exists('printflow_add_details_order_page_title')) {
    function printflow_add_details_order_page_title(int $orderId): string
    {
        return 'Request Add Details — Order #' . max(0, $orderId);
    }
}
