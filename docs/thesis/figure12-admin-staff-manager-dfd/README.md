# Figure 12 — Admin, Staff, and Manager Data Flow

## Revised caption

**Figure 12. Level 1 Data Flow Diagram for Admin, Staff, and Manager Operations in PrintFlow.**

## Explanatory paragraph

Figure 12 presents the data exchanges among PrintFlow’s Admin, Staff, and Manager roles, six system processes, and five logical data stores. The Admin maintains user accounts, inventory items, and service-material configuration. Staff records point-of-sale orders and production assignments, while the Manager reviews branch-scoped orders and reports and records branch inventory transactions. Before an order is saved, the system checks selected materials against the applicable service rules and branch stock. This availability check reads stock data and does not deduct inventory. Material OUT transactions are recorded by the production workflow when material is issued. The diagram uses directional, labeled flows; repeated role and store symbols carry the same labels and refer to the same role or logical store.

## Deliverables

- Editable vector source: `figure12_admin_staff_manager.svg`
- High-resolution raster: `figure12_admin_staff_manager.png` (3200 × 2860 pixels)

Both files depict the same single Figure 12. The SVG contains editable text, shapes, and connectors. The repeated DFD symbols are layout copies, not additional roles or data stores.

## Process and entity evidence

| DFD element | Figure function | PrintFlow evidence |
|---|---|---|
| Admin | Maintains user accounts, inventory items, service fields, and material rules; uses system reporting. | `admin/user_staff_management.php:110–174`; `admin/api_update_user_status.php:39–71, 155–181`; `admin/inv_items_management.php`; `admin/reports.php:32–43` |
| Staff | Enters POS order details and records production assignments and actual material use. | `staff/pos.php`; `staff/api/pos_checkout.php:1395, 1450, 1483, 1892, 1926, 2133, 2251`; `staff/customizations.php` |
| Manager | Reviews assigned-branch orders/reports and records branch inventory transactions. | `manager/orders.php:7`; `manager/dashboard.php:18–22`; `manager/reports.php:7`; `admin/inventory_transactions_api.php:187, 272–286`; `includes/branch_context.php:239–289` |
| 1.0 Maintain User Accounts | Validates user and branch details and creates or updates accounts. | `admin/user_staff_management.php:110–174`; `admin/api_update_user_status.php:39–71, 155–181` |
| 2.0 Record Orders and Status Updates | Saves POS order/item and payment data and processes authorized order-status updates. | `staff/api/pos_checkout.php:1450, 1483, 2133, 2251`; `staff/api_update_order_status.php:13–86` |
| 3.0 Check Order and Branch Stock | Validates the selected order materials using service rules, item units, and branch stock before order persistence. | `includes/pos_service_stock_validation.php:541`; `staff/api/pos_checkout.php:1395, 1892, 1926`; `includes/InventoryManager.php:284–320` |
| 4.0 Maintain Inventory and Material Rules | Maintains the item/rule setup and accepts authorized inventory transactions. | `admin/inv_items_management.php`; `admin/inventory_transactions_api.php:187, 272–286`; `includes/InventoryManager.php:121–174, 248–278` |
| 5.0 Manage Production and Record Material Use | Persists production assignments and records material issue through the job workflow. | `staff/customizations.php`; `includes/JobOrderService.php:1362–1512, 1889, 2174, 2210, 2265` |
| 6.0 Generate Branch Reports and Summaries | Returns sales, order, production, and inventory summaries using role-appropriate branch/date scope. | `admin/reports.php:32–43`; `manager/dashboard.php:18–22, 132–147`; `manager/reports.php:7` |

## Data-store and flow evidence

| Store | Logical records shown | Schema or code evidence |
|---|---|---|
| D1 Users and Branches | `users`, `branches` | User account creation/status updates above; branch context and assignments in `includes/branch_context.php:42–57, 239–289` |
| D2 Service and Inventory Setup | `services`, `service_field_configs`, `service_material_rules`, `inv_items` | `database/service_field_config.sql`; `migrate_db.php:125–156, 184`; `database/migrate_service_material_rule_service_id_20261103.php`; Admin configuration in `admin/inv_items_management.php` |
| D3 Order and Payment Records | `orders`, `order_items`, `provider_payments`, `payment_submissions` | POS writes above; `database/paymongo_provider_payments_20260729.sql`; `database/payment_verification_ocr_20260711.sql` |
| D4 Production Jobs | `job_orders`, `job_order_materials` | `migrate_db.php:51–92`; job lifecycle in `includes/JobOrderService.php` |
| D5 Branch Inventory Records | `inventory_transactions`, `inv_rolls` | `migrate_db.php:141–173`; transaction and stock methods in `includes/InventoryManager.php:121–174, 248–320` |

| Important data flow | Supporting behavior |
|---|---|
| User and branch details → 1.0 → D1; account status/result returns to Admin. | User management form/API validates, creates, updates, and changes account state. |
| Customer/order details → 2.0 → D3; saved order/payment state returns to 2.0. | POS checkout persists `orders` and `order_items`; payment records are stored in payment-related tables. |
| Order and selected materials → 3.0; branch stock decision → 2.0. | POS checks available stock before saving the POS order. |
| D2 service rules/item units and D5 branch stock → 3.0. | Stock validation uses service-material configuration and branch inventory state. |
| Admin setup and Manager stock transaction → 4.0 → D2/D5. | Item/rule setup is distinct from branch inventory receipts, issues, and adjustments. |
| Staff assignment + saved order specifications → 5.0 → D4; material OUT → D5. | Production assignment and issue are handled through `JobOrderService`; the diagram does not imply POS save itself deducts inventory. |
| Report criteria → 6.0; D3 order/payment, D4 job, and D5 inventory summaries → report. | Admin reports can filter a branch or broader scope; Manager context is restricted to the assigned branch. |

The D-number on a repeated store shape always denotes the same logical records. Repetition keeps each process connection local and legible; it does not mean that the application creates separate copies of the data.

## Scope notes and unresolved assumptions

- The diagram is based on repository code and schema/migration definitions. No production database, Hostinger deployment, or live role session is verified by this documentation deliverable.
- The figure groups related tables into five logical DFD stores for readability. The store captions list the representative tables; the diagram does not assert that all listed tables share one physical table or that unverified foreign keys exist.
- The Customer and external payment provider are not shown because the requested Figure 12 focuses on Admin, Staff, and Manager data flow. Payment records remain represented in D3.
- Inventory availability and inventory issue are separate flows. Process 3.0 reads stock before POS order save; Process 5.0 records material OUT through production.
- Manager activity is branch-scoped. Admin reporting may use a selected branch or a broader scope, as enforced by the application context.
- The reviewer’s sketch supplies the DFD notation and broad role/process structure. This single replacement figure uses the implemented processes and named stores rather than its generic “Manage System” and “DATABASE” boxes.
