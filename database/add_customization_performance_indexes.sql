-- Performance Indexes for Customization Module Optimization
-- These indexes improve query performance for the Customizations page
-- without changing any existing functionality or data structure

-- Index on orders.status for frequent filtering in KPI queries
-- This speeds up COUNT queries and status-based filtering
ALTER TABLE orders ADD INDEX idx_status (status);

-- Index on orders.order_date for frequent sorting
-- This speeds up ORDER BY order_date DESC queries
ALTER TABLE orders ADD INDEX idx_order_date (order_date);

-- Index on orders.order_type for filtering customization orders
-- This speeds up EXISTS subqueries checking for custom orders
ALTER TABLE orders ADD INDEX idx_order_type (order_type);

-- Index on job_orders.order_id for frequent joins with orders table
-- This speeds up joins between job_orders and orders
ALTER TABLE job_orders ADD INDEX idx_order_id (order_id);

-- Index on job_orders.status for frequent filtering in KPI queries
-- This speeds up COUNT queries and status-based filtering
ALTER TABLE job_orders ADD INDEX idx_status (status);

-- Index on job_orders.created_at for frequent sorting
-- This speeds up ORDER BY created_at DESC queries
ALTER TABLE job_orders ADD INDEX idx_created_at (created_at);

-- Index on customizations.order_id for frequent lookups
-- This speeds up queries fetching customizations by order_id
ALTER TABLE customizations ADD INDEX idx_order_id (order_id);

-- Index on order_items.order_id for frequent lookups
-- This speeds up queries fetching order_items by order_id
-- Note: This index may already exist, ALTER TABLE will ignore if duplicate
ALTER TABLE order_items ADD INDEX idx_order_id (order_id);
