# Smart Consumption and Material Size — Live QA Plan

Release: `38b423f263f17df8a150418c323b2421644aa961`  
Production: https://mrandmrsprintflow.com  
Scope: browser-only, non-destructive checks after seven-file deployment and live SHA-256 verification.

## Safety boundaries

- Use an authorized staff/admin session. Do not put credentials in this document.
- Start with existing read-only job/order records. Do not save an assignment, change a job status, submit a POS checkout, create/confirm/refund a payment, or edit inventory.
- Do not run the unapproved 25-material SQL or any migration.
- An assignment save/reload proof requires a separately approved test plan using an isolated synthetic job/order and a cleanup plan. Do not use a real customer record.
- Stop if a form unexpectedly submits, a job status changes, or a stock/payment action is triggered.

## 1. Staff Customizations page and asset load

1. Open `/staff/customizations.php` in the authorized browser session.
2. Confirm the page loads, existing filters and order cards render, and the browser console/network show no new 4xx/5xx for:
   - `/public/assets/js/material_consumption_estimator.js`
   - `/public/assets/js/production_material_picker.js`
   - relevant Job Order API read requests.
3. Open a pending job’s material assignment interface without submitting.
4. Record any PHP/API/console error and the job identifier privately; do not paste customer details into chat.

Pass evidence: page and both JavaScript files return successfully; assignment interface opens; no new relevant error.

## 2. Material Size selection

1. Choose an existing pending job whose service/material requirements are clear.
2. Open the material picker and inspect materials with configured size/unit information.
3. Confirm size and unit appear alongside each applicable material, and the displayed size matches the catalog data.
4. Change the size selection without saving. Confirm the selected size is reflected in the unsaved form.

Pass evidence: correct size/unit choices are visible and selection updates the draft.

## 3. Compatibility filtering

1. Keep the same job/service and inspect the offered materials before and after selecting a size/type.
2. Confirm incompatible materials are excluded or explicitly disabled with the UI’s reason; compatible choices remain available.
3. If ink requirements apply, verify materials lacking required ink compatibility are not offered as valid selections.
4. Do not save the assignment.

Pass evidence: UI only permits compatible materials; no inference from an unrelated label or material name.

## 4. Smart Consumption estimates

1. Use an existing job with known design dimensions and quantity.
2. Select a material whose configuration is complete and verified.
3. Confirm the estimate and unit update when dimensions, quantity, or material selection changes.
4. For sheet goods, inspect the displayed estimate against the shown sheet size, design dimensions, yield, rotation, and supported margin/waste settings.
5. For length-based roll stock, confirm the estimate is expressed in the configured length unit (for example, feet).
6. Do not save.

Pass evidence: estimate, unit, and input basis match the configured material and visible job dimensions. Capture the displayed inputs and result without customer-identifying details.

## 5. Manual-use confirmation fallback

1. In the unsaved assignment form, choose a material whose configuration is missing or incomplete, if one is available.
2. Confirm the UI explains that no reliable estimate is available and requires actual usage confirmation.
3. Enter a positive manual quantity and unit in the draft.
4. Verify the confirmation control and required fields behave correctly. Do not submit or persist the draft.

Pass evidence: incomplete configuration does not silently produce an automatic estimate; unconfirmed use is visibly blocked in the draft. Backend rejection remains covered by the prior local integration evidence, not by this non-mutating browser check.

## 6. Job Order assignment and reload

The true save/reload test writes production assignment metadata and is **not authorized by this plan**.

Read-only portion:
1. Open an existing job with previously persisted material assignments.
2. Confirm the displayed material, quantity, unit, estimate, manual-assignment flag, and confirmation metadata match the record.
3. Close and reopen the view; confirm the same persisted values load.

Pending proof:
- To test a new assignment save/reload, first approve a separate test plan that names an isolated synthetic job/order, verifies it cannot trigger stock deduction, defines cleanup, and authorizes those specific production writes. Never use a real customer order.

## 7. Inventory deduction safeguards

1. Open an existing pending job’s assignment view and inspect its current assignment/status without changing it.
2. Confirm opening the view or changing an unsaved draft does not trigger a material deduction request.
3. Do not mark a job in production/completed and do not create an assignment to test stock movement.
4. A live ledger/no-deduction assertion requires authorized read-only inventory/ledger evidence from the server. Browser UI alone is not proof of unchanged inventory.

Pass evidence: no mutation request is sent during read-only viewing/draft interaction. Record ledger verification as pending unless read-only server evidence is available.

## 8. Inventory, POS, and Payments read-only regression checks

- **Inventory:** open `/admin/inventory_management.php`; inspect that the page and current quantities/units render. Do not edit counts, rolls, or materials.
- **POS:** open `/staff/pos.php`; confirm catalog/search/cart UI loads. Do not add a checkout, submit payment, or complete a sale.
- **Payments:** open `/admin/payment.php` or the authorized payment verification view; inspect existing status display only. Do not create, verify, refund, or capture a payment.

Pass evidence: pages load and existing data/status views are usable; no new critical console/network errors. These checks do not prove a completed transaction flow.

## Record results

For each check, record: date/time (Asia/Manila), page, PASS/FAIL/NOT TESTED, sanitized evidence, and any relevant request/status code.

Do not call the release fully live-QA complete while the assignment save/reload test, inventory ledger proof, or other required evidence remains pending.

