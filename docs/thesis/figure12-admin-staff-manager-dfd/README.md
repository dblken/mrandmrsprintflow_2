# Figure 12 — Admin, Staff, and Manager Data Flow

## Thesis caption

**Figure 12. Admin, Staff, and Manager Data Flow in PrintFlow.**

## Explanatory paragraph

Figure 12 presents a Level 1 data flow diagram of the PrintFlow functions used by the Admin, Staff, Customer, Manager, and the external PayMongo payment provider. The system records customer and point-of-sale orders, checks branch stock against configured service-material rules, and processes payment and order-status information through separate processes. Staff records production-material assignments after order capture; the job workflow validates those assignments and records material issues to the branch inventory ledger at its later deduction stage. Admin maintains accounts and inventory configuration, while Admin and Manager access reports subject to their respective branch scopes. Customer inquiries and order messages pass through message-handling processes and are stored as inquiry, conversation, or order-message records.

## Diagram files

- Editable vector source: `figure12_admin_staff_manager.svg`
- High-resolution raster: `figure12_admin_staff_manager.png` (3200 × 2000 pixels)

The PNG is a raster rendering of the SVG. Repeated entity or store symbols carry the same label and represent the same logical actor or store; they are repeated to keep long flows legible. The figure uses open-ended data-store symbols, numbered process boxes, and one-way arrows labeled with the data being transferred.

## Evidence mapping

| Diagram element | PrintFlow evidence |
|---|---|
| Admin / 1.0 Maintain User Accounts | `admin/user_staff_management.php` creates and manages staff accounts; `admin/api_update_user_status.php` changes account status. The page/API requires the Admin role. |
| Customer and Staff / 2.0 Capture Customer and POS Orders / D2 Orders and Items | `customer/checkout.php` creates customer orders; `staff/api/pos_checkout.php` creates POS orders and their `order_items`. The core `orders`, `order_items`, `users`, and `customers` definitions are in `printflow.sql`. |
| 4.0 Check Branch Stock / D5 Service and Material Setup / D7 Branch Inventory Ledger | `includes/pos_service_stock_validation.php::printflow_pos_validate_service_stock()` validates service materials; `staff/api/pos_checkout.php` invokes it before saving checkout groups. `includes/InventoryManager.php::getStockOnHand()` reads branch stock. This process checks availability; it does not issue stock. |
| Customer, Staff, PayMongo / 3.0 Process Payments and Order Status / D3 Payment Records and D2 | `customer/api_submit_payment.php` records payment submissions and provider results; provider payment records are defined in `database/paymongo_provider_payments_20260729.sql`, and manual submissions in `database/payment_verification_ocr_20260711.sql`. Staff payment verification is handled by `staff/api_verify_payment.php`. |
| Staff / 5.0 Assign Production Materials / D6 Production Jobs and Assignments | `includes/JobOrderService.php::validatePendingMaterialStockPublic()` validates pending assignments. The job service creates and updates `job_orders` and material assignments; its assignment and deduction functions are separate. |
| Admin, Manager, and production issue / 6.0 Maintain Inventory Setup and Record Stock Transactions / D5 and D7 | `admin/inventory_transactions_api.php` accepts Admin and Manager inventory transactions, with Manager access scoped to the assigned branch. Its OUT path calls `InventoryManager::issueStock()`. `includes/JobOrderService.php::processDeductions()` also calls `issueStock()` when the job workflow reaches its deduction stage. |
| Customer, Staff, Admin / 7.0 Handle Inquiries and Order Messages / D4 | `admin/faq_chatbot_management.php` creates inquiry, conversation, and message records; `admin/api_chatbot_conversations.php` reads conversations and records Admin replies. Order messages are inserted through `includes/order_chat_system.php` and `includes/functions.php`. |
| Admin and Manager / 8.0 Generate Reports / D2, D3, D6, D7 | `admin/reports.php` and Admin dashboard/report APIs expose operational and sales reporting to Admin and Manager. Manager report scope is branch-limited in the report and branch-context logic. |

## Corrections to the supplied diagram

- Replaces the generic `DATABASE` cylinder with D1–D7 logical record stores drawn from PrintFlow's schema and migrations.
- Removes role task lists from entity boxes. Role actions are represented as numbered processes and data flows.
- Separates order capture, branch-stock availability checks, payment/order-status processing, production-material assignment, inventory transactions, inquiry handling, and report generation.
- Shows POS availability checks reading material setup and branch stock, while later production material issues update the inventory ledger. It does not imply that adding a POS order automatically deducts production material.
- Adds Customer as a separate external entity and PayMongo only for the provider-response flow supported by the integration.
- Labels each flow with transferred data and routes entity/store interactions through a process.

## Scope notes

- Manager reports and inventory actions are branch-scoped by the application; the diagram does not imply that Manager has Admin-wide access.
- The figure groups tables into logical stores for readability. It is not an ER diagram and does not assert that every listed table participates in every flow.
- Stock issue timing follows the job workflow and may differ by job type or production transition. The diagram states only that stock checking and later inventory issue are separate stages.
- No production database or live website was accessed or changed for this documentation update.
