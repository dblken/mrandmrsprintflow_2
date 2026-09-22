        window.POS_BRANCHES = 0;

        let products = [];
        let cart = [];
        let currentTotal = 0;
        let currentMode = null; // 'products' or 'services'
        let barcodeScanBusy = false;
        let barcodeScanQueueRunning = false;
        const barcodeScanQueue = [];
        let posBarcodeDebugEnabled = false;
        let isAddingToOrder = false;
        let posEstimatedPriceController = null;
        const STAFF_BASE_PATH = 0;
        const POS_DEFAULT_CATALOG_IMG = 0;
        const POS_ADD_FX_OPTS = {
            cartTargetSelector: '[data-pos-cart-panel]',
            cardSelector: '.pos-catalog-card',
            message: 'Added to cart ✓'
        };
        let posLastServiceCardEl = null;
        const POS_CSRF_TOKEN = document.body.dataset.csrf || '';

        function posCatalogImageUrl(product) {
            if (!product) return POS_DEFAULT_CATALOG_IMG;
            if (product.image_url) return product.image_url;
            const raw = String(product.photo_path || product.product_image || '').trim();
            if (!raw) return POS_DEFAULT_CATALOG_IMG;
            if (/^https?:\/\//i.test(raw)) return raw;
            const path = raw.startsWith('/') ? raw : '/' + raw;
            return STAFF_BASE_PATH + path;
        }

        function posPlayAddAnimation(sourceEl) {
            if (!sourceEl || !window.PFAddToCartFx) return;
            window.PFAddToCartFx.run(sourceEl, POS_ADD_FX_OPTS);
        }

        function posOpenServiceFromCard(cardEl, serviceId, serviceName) {
            posLastServiceCardEl = cardEl || null;
            if (cardEl && cardEl.classList) {
                cardEl.classList.add('is-selecting');
                window.setTimeout(function () { cardEl.classList.remove('is-selecting'); }, 320);
            }
            openServiceModal(serviceId, serviceName);
        }
        try {
            posBarcodeDebugEnabled = new URLSearchParams(window.location.search).get('pos_barcode_debug') === '1'
                || window.localStorage.getItem('printflow_pos_barcode_debug') === '1';
        } catch (ignore) {}
        function posBarcodeDebug(message, details) {
            if (!posBarcodeDebugEnabled || !window.console || typeof window.console.info !== 'function') return;
            window.console.info('[POS Barcode] ' + message, details || {});
        }
        let paymongoPollTimer = null;
        let paymongoCountdownTimer = null;
        let pendingPayMongoPayment = null;
        let pendingPayMongoReceipt = null;
        let pendingPayMongoOrderId = 0;
        let posCheckoutRequestInFlight = false;
        let posCheckoutConfirmOpen = false;
        let pendingPayMongoPrintJob = null;
        let posPayMongoCheckoutPending = false;
        let posPayMongoCheckoutAttemptToken = null;
        function staffUrl(path) {
            return (STAFF_BASE_PATH || '') + '/' + String(path || '').replace(/^\/+/, '');
        }
        async function fetchWithTimeout(url, options = {}, timeoutMs = 30000) {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
            try {
                return await fetch(url, { ...options, signal: controller.signal });
            } finally {
                clearTimeout(timeoutId);
            }
        }

        function posCheckoutCustomizationPayload(customization) {
            if (!customization || typeof customization !== 'object') {
                return null;
            }
            const payload = { ...customization };
            const stripKeys = [
                'design_upload_data',
                'reference_upload_data',
                'design_data',
                'reference_data',
                'design_blob',
                'reference_blob'
            ];
            stripKeys.forEach(key => delete payload[key]);
            if (payload.design_upload_path || payload.design_file || payload.design_tmp_path) {
                delete payload.design_upload_data;
            }
            if (payload.reference_upload_path || payload.reference_file) {
                delete payload.reference_upload_data;
            }
            return Object.keys(payload).length ? payload : null;
        }

        function posCheckoutItemPayload(item) {
            return {
                id: item.product_id,
                qty: item.qty,
                price: item.price,
                name: item.name || null,
                customization: posCheckoutCustomizationPayload(item.customization),
                is_service: item.is_service || false,
                pending_order_id: item.pending_order_id || 0,
                pending_customization_id: item.pending_customization_id || 0
            };
        }

        function posVariantOptionsList(product) {
            if (!product || !product.has_variant_stock) return [];
            if (Array.isArray(product.variant_stock_options)) {
                return product.variant_stock_options;
            }
            return Object.entries(product.variant_stock_options || {}).map(([optionValue, meta]) => ({
                option_value: optionValue,
                stock_quantity: meta && meta.stock_quantity != null ? meta.stock_quantity : 0
            }));
        }

        function posVariantOptionsInStock(product) {
            return posVariantOptionsList(product).filter(option => (parseInt(option.stock_quantity, 10) || 0) > 0);
        }

        function posBuildVariantCustomization(product, optionValue) {
            const fieldKey = product.variant_stock_field_key || 'size';
            const fieldLabel = product.variant_stock_field_label || fieldKey;
            const normalized = String(optionValue || '').trim();
            const customization = {};
            customization[fieldKey] = normalized;
            customization[fieldLabel] = normalized;
            return customization;
        }

        function posCartItemVariantLabel(item) {
            const customization = item && item.customization && typeof item.customization === 'object'
                ? item.customization
                : null;
            if (!customization) return '';
            const keys = Object.keys(customization);
            for (const key of keys) {
                const normalized = String(key || '').trim().toLowerCase();
                if (['size', 'sizes', 'variant', 'variants'].includes(normalized) && customization[key]) {
                    return String(customization[key]);
                }
            }
            return '';
        }

        function posResolveVariantBeforeAdd(product) {
            if (!product || !product.has_variant_stock) {
                return { action: 'add', customization: null };
            }
            const inStock = posVariantOptionsInStock(product);
            if (inStock.length === 0) {
                return { action: 'error', message: (product.product_name || 'This product') + ' is out of stock.' };
            }
            if (inStock.length === 1) {
                const optionValue = inStock[0].option_value;
                return {
                    action: 'add',
                    customization: posBuildVariantCustomization(product, optionValue)
                };
            }
            return {
                action: 'prompt',
                options: inStock,
                fieldLabel: product.variant_stock_field_label || 'Option'
            };
        }

        let pendingVariantProduct = null;
        let pendingVariantAddOptions = null;

        function openVariantModal(product, options, fieldLabel, addOptions = {}) {
            pendingVariantProduct = product;
            pendingVariantAddOptions = addOptions;
            const overlay = document.getElementById('variant-modal-overlay');
            const title = document.getElementById('vm-title');
            const subtitle = document.getElementById('vm-subtitle');
            const optionsEl = document.getElementById('vm-options');
            const productName = product.product_name || product.name || 'Product';
            title.textContent = 'Select ' + fieldLabel;
            subtitle.textContent = productName + ' requires a ' + fieldLabel.toLowerCase() + ' before it can be added to the cart.';
            optionsEl.innerHTML = options.map((option, index) => {
                const value = String(option.option_value || '').replace(/"/g, '&quot;');
                const stock = parseInt(option.stock_quantity, 10) || 0;
                const checked = index === 0 ? 'checked' : '';
                return '<label style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 14px; border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc; cursor:pointer;">'
                    + '<span style="display:flex; align-items:center; gap:10px; font-weight:600; color:#0f172a;">'
                    + '<input type="radio" name="pos_variant_option" value="' + value + '" ' + checked + '>'
                    + '<span>' + value + '</span>'
                    + '</span>'
                    + '<span style="font-size:12px; color:#64748b;">' + stock + ' left</span>'
                    + '</label>';
            }).join('');
            overlay.style.display = 'flex';
        }

        function closeVariantModal() {
            document.getElementById('variant-modal-overlay').style.display = 'none';
            pendingVariantProduct = null;
            pendingVariantAddOptions = null;
        }

        async function confirmVariantSelection() {
            if (!pendingVariantProduct) return;
            const selected = document.querySelector('#variant-modal-overlay input[name="pos_variant_option"]:checked');
            if (!selected || !selected.value) {
                await showPOSAlert('Selection Required', 'Please choose an option before adding this product.', 'warning');
                return;
            }
            const customization = posBuildVariantCustomization(pendingVariantProduct, selected.value);
            const addOptions = pendingVariantAddOptions || {};
            const product = pendingVariantProduct;
            closeVariantModal();
            return await posAddProductToCart(product, null, null, customization, addOptions);
        }

        async function posAddProductToCart(p, overridePrice = null, overrideName = null, customization = null, options = {}) {
            const name = overrideName || p.product_name;
            const price = overridePrice !== null ? overridePrice : parseFloat(p.price);
            return await syncedCartAction('add', {
                product_id: p.product_id,
                name: name,
                price: price,
                qty: 1,
                customization: customization,
                is_service: false
            }, options);
        }
        function formatMoney(value) {
            const amount = Number.parseFloat(value);
            const safeAmount = Number.isFinite(amount) ? amount : 0;
            return '₱' + safeAmount.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, char => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[char] || char));
        }

        function formatReceiptDateTime(value) {
            if (!value) return 'Not available';
            const parsed = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(parsed.getTime())) return value;
            return parsed.toLocaleString('en-PH', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
        }

        function cleanReceiptContact(customer = {}) {
            const phone = String(customer.phone || '').trim();
            if (phone) return phone;
            const email = String(customer.email || '').trim();
            if (!email || email.toLowerCase() === 'walkin@pos.local') return '';
            return email;
        }

        function compactReceiptSpecLabel(key) {
            const map = {
                material: 'Material',
                material_type: 'Material',
                temp_plate_material: 'Material',
                dimensions: 'Size',
                size: 'Size',
                layout: 'Layout',
                finish: 'Finish',
                lamination: 'Lamination',
                laminate_option: 'Lamination',
                needed_date: 'Needed',
                quantity: 'Qty'
            };
            return map[key] || '';
        }

        function buildReceiptItemDetails(item) {
            const custom = item && item.customization && typeof item.customization === 'object' ? item.customization : {};
            const details = [];
            const pushDetail = (label, value) => {
                const text = String(value || '').trim();
                if (!text) return;
                details.push(`${label}: ${text}`);
            };

            Object.entries(custom).forEach(([key, value]) => {
                if (details.length >= 4 || value == null || value === '' || typeof value === 'object') return;
                pushDetail(String(key), value);
            });

            return details.slice(0, 4);
        }

        function buildReceiptHtml(receipt) {
            const company = receipt?.company || {};
            const customer = receipt?.customer || {};
            const payment = receipt?.payment || {};
            const items = Array.isArray(receipt?.items) ? receipt.items : [];
            const receiptContact = cleanReceiptContact(customer);
            const cashierName = 0;
            const itemRows = items.map(item => `
                <tr>
                    <td>
                        <div class="receipt-item-name">${escapeHtml(item.name || 'Item')}</div>
                        ${buildReceiptItemDetails(item).length ? `<div class="receipt-item-meta">${escapeHtml(buildReceiptItemDetails(item).join(' • '))}</div>` : ''}
                    </td>
                    <td>${escapeHtml(item.quantity || 0)}</td>
                    <td>${formatMoney(item.unit_price || 0)}</td>
                    <td style="font-weight:800;color:#0f172a;">${formatMoney(item.line_total || 0)}</td>
                </tr>
            `).join('');
            return `
                <div class="receipt-header">
                    ${company.logo_url ? `<img src="${escapeHtml(company.logo_url)}" alt="${escapeHtml(company.name || 'Company')}" class="receipt-logo">` : ''}
                    <div class="receipt-brand-name">PrintFlow</div>
                    <div class="receipt-branch">${escapeHtml(company.branch_name || 'Main Branch')}</div>
                    <div class="receipt-company-meta">
                        ${company.address ? `<div>${escapeHtml(company.address)}</div>` : ''}
                        ${company.contact ? `<div>${escapeHtml(company.contact)}</div>` : ''}
                    </div>
                    <div class="receipt-pill">Official POS Receipt</div>
                </div>

                <div class="receipt-section">
                    <div class="receipt-section-title">Receipt Info</div>
                    ${receipt.qr_payload ? `<div class="receipt-qr-wrap"><div id="pos-receipt-qr"></div><div class="receipt-qr-caption">Scan for order details</div></div>` : ''}
                    <div class="receipt-info-grid">
                        <div class="receipt-info-card">
                            <div class="receipt-label">Receipt No.</div>
                            <div class="receipt-value receipt-value--strong">${escapeHtml(receipt.receipt_number || '')}</div>
                        </div>
                        <div class="receipt-info-card">
                            <div class="receipt-label">Date & Time</div>
                            <div class="receipt-value">${escapeHtml(receipt.date_time_display || formatReceiptDateTime(receipt.date_time))}</div>
                        </div>
                        <div class="receipt-info-card">
                            <div class="receipt-label">Cashier</div>
                            <div class="receipt-value">${escapeHtml(cashierName || 'Staff')}</div>
                        </div>
                    </div>
                </div>

                <div class="receipt-section">
                    <div class="receipt-section-title">Customer</div>
                    <div class="receipt-customer">
                        <div>
                            <div class="receipt-customer-name">${escapeHtml(customer.name || 'Walk-in Guest')}</div>
                            ${receiptContact ? `<div class="receipt-value" style="margin-top:4px;">${escapeHtml(receiptContact)}</div>` : ''}
                        </div>
                        <div class="receipt-payment-chip">${escapeHtml(payment.method || 'Cash')}</div>
                    </div>
                </div>

                <div class="receipt-section">
                    <div class="receipt-section-title">Items</div>
                    <table class="receipt-items">
                        <thead>
                            <tr>
                                <th>Item / Service</th>
                                <th>Qty</th>
                                <th>Price</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>${itemRows}</tbody>
                    </table>
                </div>

                <div class="receipt-section">
                    <div class="receipt-section-title">Payment Summary</div>
                    <div class="receipt-summary">
                        <div class="receipt-total-line"><span>Subtotal</span><strong>${formatMoney(receipt.subtotal || 0)}</strong></div>
                        <div class="receipt-total-line receipt-total-line--grand"><span>Total</span><span>${formatMoney(receipt.total || 0)}</span></div>
                    </div>
                    <div class="receipt-payment-breakdown">
                        <div class="receipt-total-line"><span>Payment Method</span><strong>${escapeHtml(payment.method || 'Cash')}</strong></div>
                        <div class="receipt-total-line"><span>Amount Paid</span><strong>${formatMoney(payment.amount_paid || 0)}</strong></div>
                        <div class="receipt-total-line"><span>Change</span><strong style="color:#0f766e;">${formatMoney(payment.change || 0)}</strong></div>
                        ${(payment.balance || 0) > 0 ? `<div class="receipt-total-line"><span>Balance</span><strong>${formatMoney(payment.balance || 0)}</strong></div>` : ''}
                        ${payment.reference ? `<div class="receipt-total-line"><span>Provider Reference</span><span>${escapeHtml(payment.reference)}</span></div>` : ''}
                        ${payment.paid_at ? `<div class="receipt-total-line"><span>Paid Date</span><span>${escapeHtml(formatReceiptDateTime(payment.paid_at))}</span></div>` : ''}
                    </div>
                </div>

                <div class="receipt-footer">
                    <strong>Thank you for choosing PrintFlow!</strong>
                    <p>Please keep this receipt for your records.</p>
                </div>

                <div class="receipt-online-store">
                    <div class="receipt-online-store-title">Visit our Online Store</div>
                    <div class="receipt-qr-wrap"><div id="pos-online-store-qr"></div></div>
                    <div class="receipt-qr-caption">Scan the QR code to order online</div>
                    <div class="receipt-online-store-url">mrandmrsprintflow.com</div>
                </div>
            `;
        }

        let activePosReceipt = null;
        let activePosPrintJob = null;
        let posReceiptPrintProcessing = false;

        const POS_RECEIPT_PRINTER_SPEC = {
            speedMmPerSec: 50,
            paperWidthMm: 58,
            printWidthMm: 48,
            avgLineHeightMm: 3.75,
            calibrationBufferSec: 0.4,
        };

        /**
         * Best-effort print duration from rated thermal head speed (50 mm/s @ 58 mm paper).
         * PushPrinter / browser print APIs do not expose live paper-feed progress.
         * Frame-perfect sync would need WebUSB/WebSerial printer status polling (separate scope).
         */
        function estimatePosReceiptPrintDurationMs(receiptEl) {
            if (!receiptEl) {
                return Math.round((POS_RECEIPT_PRINTER_SPEC.calibrationBufferSec + 1.5) * 1000);
            }

            const widthPx = receiptEl.offsetWidth || receiptEl.getBoundingClientRect().width || 1;
            const heightPx = receiptEl.scrollHeight || receiptEl.offsetHeight || 0;
            const lengthMm = (heightPx / widthPx) * POS_RECEIPT_PRINTER_SPEC.paperWidthMm;
            const printTimeSec = lengthMm / POS_RECEIPT_PRINTER_SPEC.speedMmPerSec;
            const totalSec = POS_RECEIPT_PRINTER_SPEC.calibrationBufferSec + printTimeSec;
            return Math.max(900, Math.round(totalSec * 1000));
        }

        function getReceiptPrinterViewport() {
            return document.getElementById('receipt-printer-viewport');
        }

        function resetReceiptFeedAnimation(receiptEl) {
            const viewport = getReceiptPrinterViewport();
            if (viewport) {
                viewport.classList.remove('receipt-feed-reveal', 'receipt-feed-active', 'receipt-feed-empty');
                viewport.style.transition = 'none';
                viewport.style.height = '';
                viewport.style.maxHeight = '';
                viewport.style.minHeight = '';
            }
            if (receiptEl) {
                receiptEl.classList.remove('receipt-feed-active');
                receiptEl.style.transition = 'none';
                receiptEl.style.transform = '';
            }
        }

        /**
         * Paper consumed into printer: fixed slot at top; receipt block moves upward
         * and is clipped by the viewport (overflow hidden) so content disappears into
         * the slot top-first, footer last. Best-effort duration from 50 mm/s rated speed.
         */
        function runReceiptFeedAnimation(receiptEl, durationMs) {
            const viewport = getReceiptPrinterViewport();
            if (!receiptEl || !viewport) return Promise.resolve();

            const fullHeight = Math.ceil(receiptEl.scrollHeight || receiptEl.offsetHeight || 0);
            if (fullHeight <= 0) return Promise.resolve();

            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                receiptEl.style.transition = 'none';
                receiptEl.style.transform = `translateY(-${fullHeight}px)`;
                viewport.classList.add('receipt-feed-empty');
                viewport.style.height = '0px';
                return Promise.resolve();
            }

            return new Promise(resolve => {
                viewport.classList.remove('receipt-feed-empty');
                viewport.style.transition = 'none';
                viewport.style.height = `${fullHeight}px`;
                viewport.style.maxHeight = `${fullHeight}px`;
                viewport.style.overflow = 'hidden';

                receiptEl.classList.remove('receipt-feed-active');
                receiptEl.style.transition = 'none';
                receiptEl.style.transform = 'translateY(0)';
                void receiptEl.offsetHeight;

                receiptEl.classList.add('receipt-feed-active');
                requestAnimationFrame(() => {
                    receiptEl.style.transition = `transform ${durationMs}ms linear`;
                    receiptEl.style.transform = `translateY(-${fullHeight}px)`;
                });

                window.setTimeout(() => {
                    receiptEl.style.transition = 'none';
                    viewport.style.transition = 'none';
                    viewport.style.height = '0px';
                    viewport.style.maxHeight = '0px';
                    viewport.classList.add('receipt-feed-empty');
                    resolve();
                }, durationMs);
            });
        }

        function setPosReceiptPrintState(message = '', failed = false) {
            const status = document.getElementById('receipt-print-result');
            const button = document.getElementById('pos-print-receipt-btn');
            if (status) {
                status.textContent = message;
                status.style.color = failed ? '#b91c1c' : '#0f766e';
            }
            if (button) {
                button.disabled = false;
                button.textContent = failed ? 'Retry Print' : 'Print Receipt';
            }
        }

        function openReceiptModal(receipt) {
            const overlay = document.getElementById('receipt-modal-overlay');
            const printArea = document.getElementById('receipt-print-area');
            if (!overlay || !printArea) return;
            activePosReceipt = receipt || {};
            activePosPrintJob = null;
            posReceiptPrintProcessing = false;
            setPosReceiptPrintState('No physical receipt has been printed yet.');
            printArea.innerHTML = buildReceiptHtml(activePosReceipt);
            resetReceiptFeedAnimation(printArea);
            renderPosReceiptQr(receipt?.qr_payload);
            renderPosOnlineStoreQr();
            overlay.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function renderPosReceiptQr(payload) {
            const target = document.getElementById('pos-receipt-qr');
            if (!target || !payload || typeof QRCode === 'undefined') return;
            target.innerHTML = '';
            new QRCode(target, { text: String(payload), width: 116, height: 116, correctLevel: QRCode.CorrectLevel.M });
        }

        function renderPosOnlineStoreQr() {
            const target = document.getElementById('pos-online-store-qr');
            const onlineStoreUrl = 'https://mrandmrsprintflow.com/';
            if (!target || typeof QRCode === 'undefined') return;
            target.innerHTML = '';
            new QRCode(target, { text: onlineStoreUrl, width: 116, height: 116, correctLevel: QRCode.CorrectLevel.M });
        }

        function closeReceiptModal() {
            const overlay = document.getElementById('receipt-modal-overlay');
            if (!overlay) return;
            overlay.style.display = 'none';
            document.body.style.overflow = '';
        }

        async function printReceipt() {
            if (posReceiptPrintProcessing || !activePosReceipt?.order_id) return;
            const printArea = document.getElementById('receipt-print-area');
            resetReceiptFeedAnimation(printArea);
            void printArea.offsetHeight;
            const durationMs = estimatePosReceiptPrintDurationMs(printArea);

            posReceiptPrintProcessing = true;
            setPosReceiptPrintState('Printing receipt...');

            const animationPromise = runReceiptFeedAnimation(printArea, durationMs);
            const printTaskPromise = activePosPrintJob?.job_id
                ? retryReceiptPrintJob(activePosPrintJob, { silentStatus: true })
                : (async () => {
                    const response = await fetch(staffUrl('staff/api/pos_receipt_print.php'), {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            action: 'print',
                            order_id: Number(activePosReceipt.order_id),
                            csrf_token: POS_CSRF_TOKEN
                        })
                    });
                    const result = await response.json();
                    if (!response.ok || !result.success || !result.print_job?.ok) {
                        throw new Error(result.message || 'Receipt printing failed.');
                    }
                    activePosPrintJob = result.print_job;
                    await monitorReceiptPrintJob(result.print_job, { silentSuccess: true });
                })();

            try {
                await animationPromise;
                setPosReceiptPrintState('Receipt printed successfully.');
                showPOSScanNotice('Transaction completed', 'Receipt printed successfully.', 'success');
                posReceiptPrintProcessing = false;
                await printTaskPromise;
            } catch (error) {
                console.error('Receipt printing failed:', error);
                posReceiptPrintProcessing = false;
                resetReceiptFeedAnimation(printArea);
                setPosReceiptPrintState('Receipt printing failed.', true);
            }
        }

        async function retryReceiptPrintJob(printJob, options = {}) {
            const silentStatus = !!options.silentStatus;
            const jobId = Number(printJob?.job_id || 0);
            if (jobId <= 0) {
                if (!silentStatus) {
                    await showPOSAlert(
                        'Receipt printing failed',
                        'The sale is complete, but no printable receipt job is available. Please contact an administrator.',
                        'error'
                    );
                }
                throw new Error('No printable receipt job is available.');
            }
            try {
                const response = await fetch(staffUrl('staff/api/pos_receipt_print_retry.php'), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({job_id: jobId, csrf_token: POS_CSRF_TOKEN})
                });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Receipt print job could not be retried.');
                }
                activePosPrintJob = result.print_job || {ok: true, job_id: jobId};
                await monitorReceiptPrintJob(activePosPrintJob, { silentSuccess: silentStatus });
            } catch (error) {
                console.error('Receipt print retry failed:', error);
                if (!silentStatus) {
                    posReceiptPrintProcessing = false;
                    setPosReceiptPrintState('Receipt printing failed.', true);
                }
                throw error;
            }
        }

        async function showReceiptPrintFailure(printJob, detail, statusResult = null) {
            const message = String(detail || 'The printer did not confirm the receipt.');
            const job = statusResult?.job || {};
            const failure = {
                success: statusResult?.success ?? false,
                status: statusResult?.status ?? job.status ?? printJob?.status ?? 'unknown',
                message: statusResult?.message ?? message,
                error: statusResult?.error ?? job.error_message ?? null,
                job_id: Number(printJob?.job_id || 0),
                print_job_id: Number(statusResult?.print_job_id || job.print_job_id || printJob?.job_id || 0),
                printer_id: Number(statusResult?.printer_id || job.printer_id || 0),
                attempts: Number(statusResult?.attempts ?? job.attempts ?? 0),
                provider: statusResult?.provider ?? job.provider ?? 'pushy',
                delivery_status: statusResult?.delivery_status ?? job.delivery_status ?? 'unknown',
                order_number: job.order_number ?? null,
                pushy_secret_configured: job.pushy_secret_configured ?? null,
                pushy_device_registered: job.pushy_device_registered ?? null,
                printer_last_seen_at: job.printer_last_seen_at ?? null,
                events: Array.isArray(job.events) ? job.events : [],
                http_status: statusResult?._http_status ?? null
            };
            console.error('Receipt printing failed:', failure);
            if (!printJob?.job_id) {
                posReceiptPrintProcessing = false;
                setPosReceiptPrintState('Receipt printing failed.', true);
                return;
            }
            posReceiptPrintProcessing = false;
            activePosPrintJob = printJob?.job_id ? printJob : activePosPrintJob;
            setPosReceiptPrintState('Receipt printing failed.', true);
        }

        async function monitorReceiptPrintJob(printJob, options = {}) {
            const silentSuccess = !!options.silentSuccess;
            if (!printJob?.ok || !printJob?.job_id) {
                await showReceiptPrintFailure(printJob, printJob?.message || 'The receipt could not be queued for the configured printer.');
                return;
            }

            if (!silentSuccess) {
                showPOSScanNotice('Transaction completed', 'Printing receipt...', 'success');
            }
            let lastStatusResult = null;
            for (let attempt = 0; attempt < 15; attempt += 1) {
                await new Promise(resolve => window.setTimeout(resolve, 1500));
                try {
                    const response = await fetch(
                        staffUrl('staff/api/pos_receipt_print_status.php') + '?job_id=' + encodeURIComponent(printJob.job_id) + '&_=' + Date.now(),
                        {cache: 'no-store'}
                    );
                    const result = await response.json();
                    lastStatusResult = {...result, _http_status: response.status};
                    const status = result?.job?.status;
                    if (response.ok && status === 'printed') {
                        posReceiptPrintProcessing = false;
                        activePosPrintJob = null;
                        if (!silentSuccess) {
                            setPosReceiptPrintState('Receipt printed successfully.');
                            showPOSScanNotice('Transaction completed', 'Receipt printed successfully.', 'success');
                        }
                        return;
                    }
                    if (response.ok && status === 'failed') {
                        await showReceiptPrintFailure(
                            printJob,
                            result?.job?.error_message || 'PushPrinter reported that the receipt could not be printed.',
                            lastStatusResult
                        );
                        return;
                    }
                } catch (error) {
                    lastStatusResult = {
                        success: false,
                        status: 'status_request_failed',
                        message: error?.message || 'Receipt print status request failed.',
                        error: error?.message || String(error),
                        job_id: Number(printJob.job_id || 0),
                        print_job_id: Number(printJob.job_id || 0),
                        provider: 'pushy',
                        delivery_status: 'unknown'
                    };
                    console.warn('Receipt print status check failed:', error);
                }
            }
            await showReceiptPrintFailure(
                printJob,
                'PushPrinter did not confirm the receipt in time.',
                lastStatusResult
            );
        }

        async function downloadReceiptPdf() {
            const element = document.getElementById('receipt-print-area');
            if (!element) return;
            const receiptNumber = (element.textContent.match(/POS-\d+/) || ['receipt'])[0];
            await html2pdf().set({
                margin: 8,
                filename: `${receiptNumber}.pdf`,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
                jsPDF: { unit: 'mm', format: [58, 210], orientation: 'portrait' }
            }).from(element).save();
        }

        // Initialize Select2 for customer dropdown
        $(document).ready(function () {
            $('#pos-customer').select2({
                placeholder: '-- Select Customer --',
                allowClear: false,
                width: '100%',
                minimumResultsForSearch: 0 // Always show search box
            });

            // Set default to guest
            $('#pos-customer').val('guest').trigger('change');
        });

        function showPOSMode(mode) {
            currentMode = mode;
            document.getElementById('selection-view').style.display = 'none';

            if (mode === 'products') {
                document.getElementById('products-view').style.display = 'flex';
                document.getElementById('services-view').style.display = 'none';
                // Force re-render products to ensure they show with icons
                if (products.length > 0) {
                    renderProducts();
                } else {
                    fetchProducts();
                }
                setTimeout(focusBarcodeInput, 40);
            } else if (mode === 'services') {
                document.getElementById('products-view').style.display = 'none';
                document.getElementById('services-view').style.display = 'flex';
            }
        }

        function backToSelection() {
            currentMode = null;
            document.getElementById('selection-view').style.display = 'flex';
            document.getElementById('products-view').style.display = 'none';
            document.getElementById('services-view').style.display = 'none';
        }

        document.addEventListener('DOMContentLoaded', async () => {
            fetchProducts();
            refreshCart(); // Initialize cart from session
            const pendingPayMongo = sessionStorage.getItem('pos_paymongo_pending');
            if (pendingPayMongo) {
                try {
                    const savedPayment = JSON.parse(pendingPayMongo);
                    if (Number(savedPayment.order_id) > 0) {
                        resumePayMongoPosModal(Number(savedPayment.order_id));
                    }
                } catch (error) {
                    sessionStorage.removeItem('pos_paymongo_pending');
                }
            }
            const barcodeInputs = Array.from(document.querySelectorAll('.pos-barcode-entry'));
            const searchEl = document.getElementById('pos-search');
            const catEl = document.getElementById('pos-category');
            barcodeInputs.forEach(function(barcodeEl) {
                barcodeEl.addEventListener('keydown', function(e) {
                    if (!isBarcodeTerminatorKey(e.key)) return;
                    e.preventDefault();
                    handleBarcodeScan(barcodeEl.value, barcodeEl, {terminator: e.key, source: 'barcode-input'});
                });
                barcodeEl.addEventListener('paste', function() {
                    setTimeout(function() { barcodeEl.select(); }, 0);
                });
            });
            installPosBarcodeKeyboardCapture();
            setTimeout(focusBarcodeInput, 80);
            if (searchEl) searchEl.addEventListener('input', renderProducts);
            if (catEl) catEl.addEventListener('change', renderProducts);

            // Check if returning from customizations page with updated price
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('from_customizations') === '1') {
                // Restore customer selection if saved
                const savedState = sessionStorage.getItem('pos_cart_state');
                if (savedState) {
                    try {
                        const state = JSON.parse(savedState);
                        if (state.customer) {
                            $('#pos-customer').val(state.customer).trigger('change');
                        }
                        // Update cart item price if available
                        if (state.item_index !== undefined && state.updated_price !== undefined) {
                            await syncedCartAction('update_price', { 
                                index: state.item_index, 
                                price: state.updated_price 
                            });
                        }
                    } catch (e) { }
                    sessionStorage.removeItem('pos_cart_state');
                }
                // Cart price already updated in session — just refresh silently
                await refreshCart();
                // Clean URL
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        });

        async function syncedCartAction(action, payload = {}, options = {}) {
            if (posCheckoutRequestInFlight && action !== 'clear' && !options.allowDuringCheckout) {
                console.warn('Skipped cart sync during checkout:', action);
                return { success: false, skipped: true, message: 'Checkout in progress.' };
            }
            console.log('syncedCartAction:', action, payload);
            try {
                const response = await fetchWithTimeout(staffUrl('staff/api/pos_cart_handler.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action, ...payload })
                }, Number(options.timeoutMs || 15000));
                const responseText = await response.text();
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    throw new Error('Cart server returned an invalid response.');
                }
                console.log('syncedCartAction Response:', data);
                if (response.ok && data.success) {
                    cart = data.cart || [];
                    console.log('Updated local cart:', cart);
                    renderCart();
                    if (action === 'add' && options.fxSourceEl) {
                        posPlayAddAnimation(options.fxSourceEl);
                    }
                    return { success: true };
                } else {
                    console.error('syncedCartAction Error:', data.message);
                    if (!options.silentErrors) await showPOSAlert('Error', data.message || 'Action failed', 'error');
                    return { success: false, message: data.message, errors: data.errors || null };
                }
            } catch (e) {
                console.error('Cart Action Error:', e);
                if (!options.silentErrors) await showPOSAlert('Network Error', 'Network error while updating cart.', 'error');
                return { success: false };
            }
        }

        async function refreshCart() {
            await syncedCartAction('get');
        }

        async function fetchProducts() {
            const grid = document.getElementById('pos-products-grid');
            if (grid) {
                grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:32px; margin-bottom:16px;"></i><br>Loading products...</div>';
            }
            try {
                const res = await fetch(staffUrl('staff/api/get_products.php'));
                const data = await res.json();
                console.log('Products API Response:', data);
                if (data.success) {
                    products = data.products || [];
                    console.log('Total products loaded:', products.length);
                    if (products.length > 0) {
                        console.log('Sample product:', products[0]);
                    }
                    if (grid) renderProducts();
                } else {
                    if (grid) grid.innerHTML = '<p style="color:red; text-align:center; padding:20px;">Failed to load products: ' + (data.message || 'Unknown error') + '</p>';
                }
            } catch (e) {
                console.error('Fetch error:', e);
                if (grid) grid.innerHTML = '<p style="color:red; text-align:center; padding:20px;">Network error: ' + e.message + '</p>';
            }
        }

        // ── Service Modal (DB-driven fields) ────────────────────────────────────────
        function getBranchField() {
            const branches = (window.POS_BRANCHES || []).map(b => ({ value: b.id, label: b.name }));
            const hasBranches = branches && branches.length > 0;
            return { label: 'Branch *', type: 'select', name: 'branch_id', options: branches, required: hasBranches };
        }

        function destroyPosEstimatedPrice() {
            if (posEstimatedPriceController && typeof posEstimatedPriceController.destroy === 'function') {
                posEstimatedPriceController.destroy();
            }
            posEstimatedPriceController = null;
            resetPosEstimatedPriceDisplay();
        }

        function resetPosEstimatedPriceDisplay() {
            const footer = document.getElementById('pos-estimated-price-display');
            if (!footer) return;
            const totalEl = footer.querySelector('#pos-estimated-total');
            const qtyEl = footer.querySelector('#pos-qty-display');
            if (totalEl) totalEl.textContent = '₱0.00';
            if (qtyEl) qtyEl.textContent = '1';
        }

        function initPosEstimatedPrice(body, basePrice) {
            destroyPosEstimatedPrice();
            const footer = document.getElementById('pos-estimated-price-display');
            if (!footer || typeof window.printflowInitServiceEstimatedPrice !== 'function') {
                return;
            }
            posEstimatedPriceController = window.printflowInitServiceEstimatedPrice(body, {
                basePrice: basePrice,
                form: body,
                estimatedTotalEl: footer.querySelector('#pos-estimated-total'),
                qtyDisplayEl: footer.querySelector('#pos-qty-display')
            });
        }

        async function openServiceModal(serviceId, serviceName) {
            console.log('openServiceModal called:', serviceId, serviceName);
            const overlay = document.getElementById('service-modal-overlay');
            const title = document.getElementById('sm-title');
            const body = document.getElementById('sm-fields-body');
            const footerActions = document.getElementById('sm-footer-actions');

            destroyPosEstimatedPrice();
            title.textContent = serviceName + ' — Order Details';
            body.innerHTML = '<div style="text-align:center;padding:2rem;color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading fields...</div>';
            footerActions.style.display = 'none';
            overlay.style.display = 'flex';
            overlay.dataset.serviceId = serviceId;
            overlay.dataset.serviceName = serviceName;

            try {
                const res = await fetch(staffUrl('staff/api/pos_service_fields.php?service_id=') + serviceId);
                const data = await res.json();
                console.log('Service fields response:', data);
                if (!data.success) {
                    body.innerHTML = '<p style="color:#ef4444;text-align:center;padding:1rem;">' + (data.error || 'Failed to load fields.') + '</p>';
                    return;
                }
                overlay.dataset.csrfToken = data.csrf_token;
                body.innerHTML = data.fields_html;
                footerActions.style.display = 'block';
                isAddingToOrder = false;
                setServiceAddButtonBusy(false);

                // Lock branch to staff's assigned branch
                if (data.staff_branch_id) {
                    const branchSel = body.querySelector('select[name="branch_id"]');
                    if (branchSel) {
                        const branchField = branchSel.closest('.shopee-form-field');
                        const branchName = data.staff_branch_name || branchSel.options[branchSel.selectedIndex]?.text || 'Assigned Branch';
                        if (branchField) {
                            branchField.innerHTML = `
                                <div class="input-field-locked" style="width:175px; max-width:175px; display:inline-flex; align-items:center; justify-content:space-between; gap:10px; padding:.6rem .85rem; border-radius:.5rem; background:var(--staff-primary); color:#ffffff; border:1px solid var(--staff-primary); font-size:.95rem; font-weight:700; box-shadow:0 8px 18px rgba(var(--staff-accent-rgb), 0.22);">
                                    <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${branchName}</span>
                                    <i class="fas fa-building" style="font-size:.85rem; opacity:.9;"></i>
                                </div>
                            `;
                        }

                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'branch_id';
                        hidden.value = data.staff_branch_id;
                        (branchField || branchSel.parentNode).appendChild(hidden);
                    }
                }

                // Re-run the field scripts (conditional logic, qty buttons, etc.)
                if (typeof updateConditionalFields === 'function') updateConditionalFields();
                body.querySelectorAll('.shopee-opt-btn input[type="radio"]').forEach(r => {
                    r.addEventListener('change', function () {
                        if (typeof updateOptVisual === 'function') updateOptVisual(this);
                        if (typeof updateConditionalFields === 'function') updateConditionalFields();
                    });
                });
                body.querySelectorAll('select').forEach(s => {
                    if (typeof pfSyncSelectOthersWrap === 'function') pfSyncSelectOthersWrap(s);
                    s.addEventListener('change', function () {
                        if (typeof pfSyncSelectOthersWrap === 'function') pfSyncSelectOthersWrap(this);
                        if (typeof updateConditionalFields === 'function') updateConditionalFields();
                    });
                });
                bindServiceValidationClearers(body);
                if (typeof initPfDesignUploadGroups === 'function') initPfDesignUploadGroups(body);
                initPosEstimatedPrice(body, parseFloat(data.base_price) || 0);
            } catch (e) {
                body.innerHTML = '<p style="color:#ef4444;text-align:center;padding:1rem;">Network error. Please try again.</p>';
            }
        }

        function closeServiceModal() {
            isAddingToOrder = false;
            setServiceAddButtonBusy(false);
            destroyPosEstimatedPrice();
            const body = document.getElementById('sm-fields-body');
            if (body) body.innerHTML = '';
            document.getElementById('service-modal-overlay').style.display = 'none';
        }

        async function posReadFilePayload(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve({
                    name: file.name || '',
                    mime: file.type || '',
                    data: typeof reader.result === 'string' ? reader.result : ''
                });
                reader.onerror = () => reject(new Error('Failed to read file.'));
                reader.readAsDataURL(file);
            });
        }

        async function posStageMediaUpload(file, field = 'design') {
            const fd = new FormData();
            fd.append('field', field);
            fd.append(field === 'reference' ? 'reference_file' : 'design_file', file);
            const res = await fetch(staffUrl('staff/api/pos_upload_design.php'), {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            if (!data || !data.success || !data.path) {
                throw new Error((data && data.message) ? data.message : 'Failed to stage upload.');
            }
            return data;
        }

        async function posApplyStagedUpload(customization, file, field = 'design') {
            const staged = await posStageMediaUpload(file, field);
            if (field === 'reference') {
                customization.reference_upload = staged.name;
                customization.reference_upload_name = staged.name;
                customization.reference_upload_mime = staged.mime;
                customization.reference_upload_path = staged.path;
                delete customization.reference_upload_data;
            } else {
                customization.design_upload = staged.name;
                customization.design_upload_name = staged.name;
                customization.design_upload_mime = staged.mime;
                customization.design_upload_path = staged.path;
                delete customization.design_upload_data;
            }
            return staged;
        }

        async function confirmServiceModalLegacy() {
            const overlay = document.getElementById('service-modal-overlay');
            const serviceId = parseInt(overlay.dataset.serviceId);
            const serviceName = overlay.dataset.serviceName;
            const body = document.getElementById('sm-fields-body');

            // Collect all field values from the rendered form
            const customization = {};
            let valid = true;

            // Branch — prefer hidden input (set when locked for staff)
            const branchHidden = body.querySelector('input[type="hidden"][name="branch_id"]');
            const branchSel = body.querySelector('select[name="branch_id"]');
            const branchVal = (branchHidden && branchHidden.value) ? branchHidden.value : (branchSel ? branchSel.value : '');
            if (!branchVal) {
                await showPOSAlert('Branch Required', 'Please select a branch.', 'warning');
                if (branchSel) branchSel.focus();
                return;
            }
            customization['branch_id'] = branchVal;
            customization['service_id'] = serviceId;
            customization['service_type'] = serviceName;

            // All visible rows
            body.querySelectorAll('.shopee-form-row').forEach(row => {
                if (row.style.display === 'none') return; // skip hidden conditional rows
                const label = row.querySelector('.shopee-form-label');
                if (!label) return;
                const labelText = label.innerText.replace('*', '').trim();
                const isRequired = label.innerText.includes('*');

                // Radio
                const checkedRadio = row.querySelector('input[type="radio"]:checked');
                if (checkedRadio) { customization[labelText] = checkedRadio.value; return; }

                // Select (non-branch)
                const sel = row.querySelector('select:not([name="branch_id"])');
                if (sel && sel.value) { customization[labelText] = sel.value; }
                if (sel && isRequired && !sel.value) {
                    showPOSAlert('Required Field', labelText + ' is required.', 'warning');
                    valid = false;
                }

                // Date
                const dateInput = row.querySelector('input[type="date"]');
                if (dateInput && dateInput.value) { customization[labelText] = dateInput.value; }
                if (dateInput && isRequired && !dateInput.value) {
                    showPOSAlert('Required Field', labelText + ' is required.', 'warning');
                    valid = false;
                }

                // Quantity
                const qtyInput = row.querySelector('#quantity-input');
                if (qtyInput) { customization['quantity'] = qtyInput.value || 1; }

                // Textarea (notes)
                const textarea = row.querySelector('textarea');
                if (textarea && textarea.value.trim()) { customization[labelText] = textarea.value.trim(); }

                // Dimension hidden fields
                const wh = row.querySelector('#width_hidden');
                const hh = row.querySelector('#height_hidden');
                if (wh && hh) {
                    if (wh.value && hh.value) { customization[labelText] = wh.value + '×' + hh.value; }
                    else if (isRequired) {
                        showPOSAlert('Required Field', labelText + ' is required.', 'warning');
                        valid = false;
                    }
                }

                // Text / number / design link
                const textInput = row.querySelector('input[type="text"], input[type="number"]:not(#quantity-input), input[type="url"].pf-design-link-input');
                if (textInput && !textInput.id.includes('hidden') && textInput.value.trim()) {
                    if (textInput.classList.contains('pf-design-link-input')) {
                        customization[labelText + ' Link'] = textInput.value.trim();
                    } else {
                        customization[labelText] = textInput.value.trim();
                    }
                }

                const uploadGroup = row.querySelector('.pf-file-upload-group[data-pf-required="1"]');
                if (uploadGroup && isRequired) {
                    const fileInput = uploadGroup.querySelector('.pf-design-file-input');
                    const linkInput = uploadGroup.querySelector('.pf-design-link-input');
                    const hasFile = !!(fileInput && fileInput.files && fileInput.files.length > 0);
                    const hasLink = !!(linkInput && linkInput.value.trim());
                    if (!hasFile && !hasLink) {
                        showPOSAlert('Required Field', 'Please upload a design or paste a design link.', 'warning');
                        valid = false;
                    } else if (hasLink && !isValidDesignLink(linkInput.value.trim())) {
                        showPOSAlert('Invalid Link', 'Please enter a valid HTTP or HTTPS design link.', 'warning');
                        valid = false;
                    }
                }
            });

            const serviceFileInputs = Array.from(body.querySelectorAll('input[type="file"]'));
            for (const fileInput of serviceFileInputs) {
                if (!(fileInput.files && fileInput.files.length > 0)) {
                    continue;
                }
                const file = fileInput.files[0];
                const row = fileInput.closest('.shopee-form-row');
                const label = row ? row.querySelector('.shopee-form-label') : null;
                const labelText = label ? label.innerText.replace('*', '').trim() : (fileInput.name || 'File');

                const nameLc = String(fileInput.name || '').toLowerCase();
                const labelLc = String(labelText || '').toLowerCase();
                const isDesignField = nameLc === 'design_file'
                    || nameLc.includes('design')
                    || (labelLc.includes('upload') && labelLc.includes('design'));
                const isReferenceField = nameLc === 'reference_file'
                    || nameLc.includes('reference')
                    || (labelLc.includes('upload') && labelLc.includes('reference'));

                try {
                    if (isDesignField) {
                        await posApplyStagedUpload(customization, file, 'design');
                        customization[labelText] = customization.design_upload_name;
                    } else if (isReferenceField) {
                        await posApplyStagedUpload(customization, file, 'reference');
                        customization[labelText] = customization.reference_upload_name;
                    } else {
                        const payload = await posReadFilePayload(file);
                        customization[labelText] = payload.name;
                    }
                } catch (uploadErr) {
                    await showPOSAlert('Upload Failed', uploadErr.message || 'Could not save the design file.', 'error');
                    return;
                }
            }

            // Add service to cart with price = 0 (will be set in Customizations V2)
            const result = await syncedCartAction('add', {
                product_id: serviceId,
                name: serviceName,
                price: 0,
                qty: parseInt(customization['quantity'] || 1),
                customization: customization,
                is_service: true
            }, { fxSourceEl: posLastServiceCardEl });

            if (result.success) closeServiceModal();
        }

        function setServiceAddButtonBusy(isBusy) {
            const btn = document.getElementById('sm-add-to-order-btn');
            if (!btn) return;
            btn.disabled = !!isBusy;
            btn.style.opacity = isBusy ? '0.7' : '1';
            btn.style.cursor = isBusy ? 'not-allowed' : 'pointer';
            btn.textContent = isBusy ? 'Adding...' : 'Add to Order';
        }

        function isServiceFieldVisible(row) {
            if (!row || row.hidden) return false;
            const style = window.getComputedStyle(row);
            if (style.display === 'none' || style.visibility === 'hidden') return false;
            const hiddenParent = row.parentElement ? row.parentElement.closest('[style*="display:none"], [style*="display: none"]') : null;
            return !hiddenParent;
        }

        function serviceFieldLabel(row) {
            const label = row ? row.querySelector('.shopee-form-label') : null;
            return (label ? label.innerText : 'This field').replace(/\*/g, '').trim() || 'This field';
        }

        function serviceFieldKey(row, fallback = '') {
            if (!row) return fallback || 'field';
            if (row.dataset.fieldKey) return row.dataset.fieldKey;
            const named = row.querySelector('[name]');
            return named ? named.name : (fallback || serviceFieldLabel(row).toLowerCase().replace(/[^a-z0-9]+/g, '_'));
        }

        function serviceValidationMessage(row, input) {
            const label = serviceFieldLabel(row);
            const name = String((input && input.name) || serviceFieldKey(row) || label).toLowerCase();
            const type = input ? String(input.type || '').toLowerCase() : '';
            if (row && row.querySelector('.pf-file-upload-group')) {
                return 'Please upload a design or paste a design link.';
            }
            if (name.includes('design') || type === 'file') return 'Please upload a design or paste a design link.';
            if (name.includes('layout') || label.toLowerCase().includes('layout')) return 'Please select a layout.';
            if (name.includes('needed_date') || label.toLowerCase().includes('needed date')) return 'Please select a needed date.';
            if (name.includes('quantity') || label.toLowerCase().includes('quantity')) return 'Quantity must be at least 1.';
            if (type === 'radio' || row.querySelector('input[type="radio"]')) return 'Please select ' + label.toLowerCase() + '.';
            if (input && input.tagName === 'SELECT') return 'Please select ' + label.toLowerCase() + '.';
            return 'Please enter ' + label.toLowerCase() + '.';
        }

        function clearServiceFieldError(row) {
            if (!row) return;
            row.classList.remove('field-invalid');
            row.querySelectorAll('.field-invalid').forEach(el => el.classList.remove('field-invalid'));
            row.querySelectorAll('.field-error-message').forEach(el => el.remove());
        }

        function clearAllServiceValidationErrors() {
            const body = document.getElementById('sm-fields-body');
            if (!body) return;
            body.querySelectorAll('.field-invalid').forEach(el => el.classList.remove('field-invalid'));
            body.querySelectorAll('.field-error-message').forEach(el => el.remove());
        }

        function markServiceFieldInvalid(row, message) {
            if (!row) return;
            clearServiceFieldError(row);
            row.classList.add('field-invalid');
            const targetGroup = row.querySelector('.shopee-opt-group') || row.querySelector('.quantity-container');
            const targetControl = row.querySelector('input:not([type="hidden"]), select, textarea');
            if (targetGroup) targetGroup.classList.add('field-invalid');
            if (targetControl) targetControl.classList.add('field-invalid');
            const field = row.querySelector('.shopee-form-field') || row;
            const msg = document.createElement('div');
            msg.className = 'field-error-message';
            msg.textContent = message;
            field.appendChild(msg);
        }

        function showValidationErrors(errors) {
            clearAllServiceValidationErrors();
            Object.values(errors || {}).forEach(error => {
                if (error && error.row) markServiceFieldInvalid(error.row, error.message);
            });
        }

        function serviceSelectorEscape(value) {
            if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(String(value));
            return String(value).replace(/["\\]/g, '\\$&');
        }

        function showBackendValidationErrors(errors) {
            const body = document.getElementById('sm-fields-body');
            if (!body || !errors) return;
            clearAllServiceValidationErrors();
            Object.entries(errors).forEach(([key, message]) => {
                const keyLc = String(key || '').toLowerCase();
                const escapedKey = serviceSelectorEscape(key);
                let row = body.querySelector('.shopee-form-row[data-field-key="' + escapedKey + '"]');
                if (!row) {
                    const input = body.querySelector('[name="' + escapedKey + '"]');
                    row = input ? input.closest('.shopee-form-row') : null;
                }
                if (!row) {
                    row = Array.from(body.querySelectorAll('.shopee-form-row')).find(candidate => {
                        return serviceFieldLabel(candidate).toLowerCase().replace(/[^a-z0-9]+/g, '_').includes(keyLc);
                    }) || null;
                }
                if (row) markServiceFieldInvalid(row, message);
            });
        }

        function focusFirstInvalidField(errors) {
            const first = Object.values(errors || {}).find(error => error && error.row);
            if (!first) return;
            first.row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const focusable = first.row.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), .shopee-opt-btn');
            if (focusable && typeof focusable.focus === 'function') {
                setTimeout(() => focusable.focus({ preventScroll: true }), 180);
            }
        }

        function bindServiceValidationClearers(container) {
            if (!container) return;
            container.querySelectorAll('input, select, textarea, button[data-dimension-choice], button[data-dimension-others]').forEach(el => {
                ['input', 'change', 'click'].forEach(evt => {
                    el.addEventListener(evt, function () {
                        const row = this.closest('.shopee-form-row');
                        if (row) clearServiceFieldError(row);
                    });
                });
            });
        }

        function isValidDesignLink(value) {
            const text = String(value || '').trim();
            if (!text) return true;
            if (/^\s*(javascript|data|file|vbscript):/i.test(text)) return false;
            try {
                const parsed = new URL(text);
                return parsed.protocol === 'http:' || parsed.protocol === 'https:';
            } catch (err) {
                return false;
            }
        }

        function validateServiceOrderForm() {
            const body = document.getElementById('sm-fields-body');
            const errors = {};
            if (!body) return { valid: false, errors };

            const addError = (row, input, keyOverride) => {
                const key = keyOverride || serviceFieldKey(row, input ? input.name : '');
                if (!errors[key]) errors[key] = { row, field: key, message: serviceValidationMessage(row, input) };
            };

            body.querySelectorAll('.shopee-form-row').forEach(row => {
                if (!isServiceFieldVisible(row)) return;
                const requiredInputs = Array.from(row.querySelectorAll('[required]')).filter(input => {
                    if (input.disabled) return false;
                    const hiddenParent = input.closest('[style*="display:none"], [style*="display: none"]');
                    return !hiddenParent;
                });
                if (requiredInputs.length === 0) return;

                const radiosByName = new Map();
                requiredInputs.forEach(input => {
                    if (input.type === 'radio') {
                        if (!radiosByName.has(input.name)) radiosByName.set(input.name, []);
                        radiosByName.get(input.name).push(input);
                    }
                });
                radiosByName.forEach((radios, name) => {
                    const checked = radios.find(radio => radio.checked);
                    if (!checked) {
                        addError(row, radios[0], name);
                        return;
                    }
                    if (checked.value === 'Others') {
                        const otherInput = row.querySelector('input[name="' + name + '_other"]');
                        if (otherInput && !String(otherInput.value || '').trim()) {
                            addError(row, otherInput, name + '_other');
                        }
                    }
                });

                const dimensionInputs = requiredInputs.filter(input => input.type === 'hidden' && input.dataset.dimensionRole);
                if (dimensionInputs.length && dimensionInputs.some(input => !String(input.value || '').trim())) {
                    addError(row, dimensionInputs[0]);
                }

                requiredInputs.forEach(input => {
                    if (input.type === 'radio' || (input.type === 'hidden' && input.dataset.dimensionRole)) return;
                    const value = input.type === 'file'
                        ? (input.files && input.files.length > 0 ? input.files[0].name : '')
                        : String(input.value || '').trim();
                    if (input.tagName === 'SELECT') {
                        const otherValue = input.getAttribute('data-other-option') || 'Others';
                        const rowOtherInput = row.querySelector('input[name="' + input.name + '_other"]');
                        if (value === otherValue && rowOtherInput && !String(rowOtherInput.value || '').trim()) {
                            addError(row, rowOtherInput, input.name + '_other');
                        }
                    }
                    if (input.name === 'quantity' || input.classList.contains('pf-service-quantity-input')) {
                        const qty = parseInt(value, 10);
                        if (!Number.isFinite(qty) || qty < 1) addError(row, input);
                        return;
                    }
                    if (!value) addError(row, input);
                });
            });

            body.querySelectorAll('.pf-file-upload-group[data-pf-required="1"]').forEach(group => {
                const row = group.closest('.shopee-form-row');
                if (!row || !isServiceFieldVisible(row)) return;
                const fileInput = group.querySelector('.pf-design-file-input');
                const linkInput = group.querySelector('.pf-design-link-input');
                const hasFile = !!(fileInput && fileInput.files && fileInput.files.length > 0);
                const linkValue = linkInput ? String(linkInput.value || '').trim() : '';
                const hasLink = linkValue !== '';
                if (!hasFile && !hasLink) {
                    addError(row, fileInput || linkInput || group);
                    return;
                }
                if (hasLink && !isValidDesignLink(linkValue)) {
                    addError(row, linkInput || group, (linkInput && linkInput.name) || 'design_link');
                }
            });

            return { valid: Object.keys(errors).length === 0, errors };
        }

        function setCustomizationValue(customization, row, input, value) {
            const key = serviceFieldKey(row, input ? input.name : '');
            if (key) customization[key] = value;
        }

        async function confirmServiceModal() {
            if (isAddingToOrder) return;
            const overlay = document.getElementById('service-modal-overlay');
            const serviceId = parseInt(overlay.dataset.serviceId);
            const serviceName = overlay.dataset.serviceName;
            const body = document.getElementById('sm-fields-body');

            const validationResult = validateServiceOrderForm();
            if (!validationResult.valid) {
                showValidationErrors(validationResult.errors);
                focusFirstInvalidField(validationResult.errors);
                return;
            }

            isAddingToOrder = true;
            setServiceAddButtonBusy(true);

            const customization = {};
            const branchHidden = body.querySelector('input[type="hidden"][name="branch_id"]');
            const branchSel = body.querySelector('select[name="branch_id"]');
            const branchVal = (branchHidden && branchHidden.value) ? branchHidden.value : (branchSel ? branchSel.value : '');
            if (!branchVal) {
                await showPOSAlert('Branch Required', 'Please select a branch.', 'warning');
                if (branchSel) branchSel.focus();
                isAddingToOrder = false;
                setServiceAddButtonBusy(false);
                return;
            }
            customization.branch_id = branchVal;
            customization.service_id = serviceId;
            customization.service_type = serviceName;

            body.querySelectorAll('.shopee-form-row').forEach(row => {
                if (!isServiceFieldVisible(row)) return;

                const checkedRadio = row.querySelector('input[type="radio"]:checked');
                if (checkedRadio) {
                    let radioValue = checkedRadio.value;
                    if (radioValue === 'Others') {
                        const radioOther = row.querySelector('input[name="' + checkedRadio.name + '_other"]');
                        if (radioOther && radioOther.value.trim()) {
                            radioValue = radioOther.value.trim();
                            customization[serviceFieldLabel(row) + ' (Other)'] = radioValue;
                        }
                    }
                    setCustomizationValue(customization, row, checkedRadio, radioValue);
                }

                const sel = row.querySelector('select:not([name="branch_id"])');
                if (sel && sel.value) {
                    let selectValue = sel.value;
                    const otherValue = sel.getAttribute('data-other-option') || 'Others';
                    if (selectValue === otherValue) {
                        const selectOther = row.querySelector('input[name="' + sel.name + '_other"]');
                        if (selectOther && selectOther.value.trim()) {
                            selectValue = selectOther.value.trim();
                            customization[serviceFieldLabel(row) + ' (Other)'] = selectValue;
                        }
                    }
                    setCustomizationValue(customization, row, sel, selectValue);
                }

                const dateInput = row.querySelector('input[type="date"]');
                if (dateInput && dateInput.value) setCustomizationValue(customization, row, dateInput, dateInput.value);

                const qtyInput = row.querySelector('#quantity-input, .pf-service-quantity-input, input[name="quantity"]');
                if (qtyInput) customization.quantity = qtyInput.value || 1;

                const textarea = row.querySelector('textarea');
                if (textarea && textarea.value.trim()) setCustomizationValue(customization, row, textarea, textarea.value.trim());

                const wh = row.querySelector('[data-dimension-role="width"], #width_hidden');
                const hh = row.querySelector('[data-dimension-role="height"], #height_hidden');
                if (wh && hh && wh.value && hh.value) {
                    const fieldKey = serviceFieldKey(row, wh.name || hh.name || '');
                    const widthVal = String(wh.value).trim();
                    const heightVal = String(hh.value).trim();
                    if (fieldKey) {
                        customization[fieldKey] = widthVal + 'x' + heightVal;
                        customization[fieldKey + '_width'] = widthVal;
                        customization[fieldKey + '_height'] = heightVal;
                    }
                    if (fieldKey === 'dimensions') {
                        customization.width = widthVal;
                        customization.height = heightVal;
                    }
                }

                const textInput = row.querySelector('input[type="text"]:not(.pf-service-quantity-input):not(.select-others-input):not(.radio-others-input), input[type="number"]:not(#quantity-input):not(.pf-service-quantity-input), input[type="url"].pf-design-link-input');
                if (textInput && !textInput.id.includes('hidden') && textInput.value.trim()) {
                    if (textInput.classList.contains('pf-design-link-input')) {
                        const labelText = serviceFieldLabel(row);
                        customization[labelText + ' Link'] = textInput.value.trim();
                    }
                    setCustomizationValue(customization, row, textInput, textInput.value.trim());
                }
            });

            try {
                const serviceFileInputs = Array.from(body.querySelectorAll('input[type="file"]'));
                for (const fileInput of serviceFileInputs) {
                    if (!(fileInput.files && fileInput.files.length > 0)) continue;
                    const file = fileInput.files[0];
                    const row = fileInput.closest('.shopee-form-row');
                    const labelText = row ? serviceFieldLabel(row) : (fileInput.name || 'File');
                    const nameLc = String(fileInput.name || '').toLowerCase();
                    const labelLc = String(labelText || '').toLowerCase();
                    const isDesignField = nameLc === 'design_file' || nameLc.includes('design') || (labelLc.includes('upload') && labelLc.includes('design'));
                    const isReferenceField = nameLc === 'reference_file' || nameLc.includes('reference') || (labelLc.includes('upload') && labelLc.includes('reference'));

                    if (isDesignField) {
                        await posApplyStagedUpload(customization, file, 'design');
                        setCustomizationValue(customization, row, fileInput, customization.design_upload_name);
                    } else if (isReferenceField) {
                        await posApplyStagedUpload(customization, file, 'reference');
                        setCustomizationValue(customization, row, fileInput, customization.reference_upload_name);
                    } else {
                        const payload = await posReadFilePayload(file);
                        setCustomizationValue(customization, row, fileInput, payload.name);
                    }
                }
            } catch (uploadErr) {
                await showPOSAlert('Upload Failed', uploadErr.message || 'Could not save the design file.', 'error');
                isAddingToOrder = false;
                setServiceAddButtonBusy(false);
                return;
            }

            const result = await syncedCartAction('add', {
                product_id: serviceId,
                name: serviceName,
                price: 0,
                qty: parseInt(customization.quantity || 1, 10),
                customization: customization,
                is_service: true
            }, { silentErrors: true, fxSourceEl: posLastServiceCardEl });

            if (result.success) {
                closeServiceModal();
            } else {
                if (result.errors) {
                    showBackendValidationErrors(result.errors);
                    focusFirstInvalidField(Object.fromEntries(Object.entries(result.errors).map(([key, message]) => {
                        const body = document.getElementById('sm-fields-body');
                        const escapedKey = serviceSelectorEscape(key);
                        let row = null;
                        if (body) {
                            row = body.querySelector('.shopee-form-row[data-field-key="' + escapedKey + '"]');
                            if (!row) {
                                const named = body.querySelector('[name="' + escapedKey + '"]');
                                row = named && named.closest ? named.closest('.shopee-form-row') : null;
                            }
                        }
                        return [key, { row, message }];
                    })));
                }
                await showPOSAlert('Incomplete Fields', result.message || 'Some required order details are missing.', 'warning');
                isAddingToOrder = false;
                setServiceAddButtonBusy(false);
            }
        }

        // ── Legacy service requirements (kept for product-based services) ─────────────
        const serviceRequirements = {
            'Tarpaulin': [
                getBranchField,
                { label: 'Dimensions (ft)', type: 'dimensions_ft', name: 'dimensions', subNames: ['width', 'height'], placeholders: ['Width', 'Height'], required: true },
                { label: 'Finish Type', type: 'select', name: 'finish', options: ['Matte', 'Glossy'] },
                { label: 'Lamination', type: 'select', name: 'lamination', options: ['With Laminate', 'Without Laminate'] },
                { label: 'Eyelets', type: 'select', name: 'with_eyelets', options: ['Yes', 'No'] },
                { label: 'Layout', type: 'select', name: 'layout', options: ['With Layout', 'Without Layout'] },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Needed Date', type: 'date', name: 'needed_date', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ],
            'T-Shirt': [
                getBranchField,
                { label: 'Shirt Source', type: 'select', name: 'shirt_source', options: ['Shop will provide the shirt', 'Customer will provide the shirt'], required: true },
                { label: 'Shirt Type', type: 'select', name: 'shirt_type', options: ['Crew Neck', 'V-Neck', 'Polo', 'Raglan', 'Long Sleeve', 'Others'] },
                { label: 'Shirt Type (if Others)', type: 'text', name: 'shirt_type_other', placeholder: 'Enter custom shirt type', conditionalOn: { field: 'shirt_type', value: 'Others' } },
                { label: 'Shirt Color', type: 'select', name: 'shirt_color', options: ['Black', 'White', 'Red', 'Blue', 'Navy', 'Grey', 'Other'] },
                { label: 'Color (if Other)', type: 'text', name: 'color_other', placeholder: 'Enter custom color', conditionalOn: { field: 'shirt_color', value: 'Other' } },
                { label: 'Sizes', type: 'select_other', name: 'sizes', options: ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', 'Others'], otherOption: 'Others', otherName: 'sizes_other', otherPlaceholder: 'Enter custom size', disabledWhen: { field: 'shirt_source', value: 'Customer will provide the shirt' } },
                { label: 'Print Placement', type: 'select', name: 'print_placement', options: ['Front Center Print', 'Back Upper Print', 'Left/Right Chest Print', 'Bottom Hem Print', 'Sleeve Print', 'Long Sleeve Arm Print'] },
                { label: 'Lamination', type: 'select', name: 'lamination', options: ['With Laminate', 'Without Laminate'] },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ],
            'Stickers': [
                getBranchField,
                { label: 'Dimensions (W × H, inches)', type: 'text', name: 'size', placeholder: 'e.g. 2x2', required: true },
                { label: 'Finish', type: 'select', name: 'finish', options: ['Glossy', 'Matte'] },
                { label: 'Laminate', type: 'select', name: 'laminate_option', options: ['With Laminate', 'Without Laminate'] },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Needed Date', type: 'date', name: 'needed_date', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ],
            'Decals / Stickers': [
                getBranchField,
                { label: 'Dimensions (W × H, inches)', type: 'text', name: 'size', placeholder: 'e.g. 2x2', required: true },
                { label: 'Finish', type: 'select', name: 'finish', options: ['Glossy', 'Matte'] },
                { label: 'Laminate', type: 'select', name: 'laminate_option', options: ['With Laminate', 'Without Laminate'] },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Needed Date', type: 'date', name: 'needed_date', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ],
            'Glass/Wall': [
                getBranchField,
                { label: 'Dimensions (ft)', type: 'dimensions_ft', name: 'dimensions', subNames: ['width', 'height'], placeholders: ['Width', 'Height'], required: true },
                { label: 'Surface Type', type: 'select', name: 'surface_type', options: ['Glass (Window/Door/Storefront)', 'Wall (Painted/Concrete)', 'Frosted Glass', 'Mirror', 'Acrylic/Panel', 'Others'] },
                { label: 'Surface Type (if Others)', type: 'text', name: 'surface_type_other', placeholder: 'Specify surface type', conditionalOn: { field: 'surface_type', value: 'Others' } },
                { label: 'Lamination', type: 'select', name: 'lamination', options: ['With Laminate', 'Without Laminate'] },
                { label: 'Installation', type: 'select', name: 'installation', options: ['Without Installation', 'With Installation'] },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Needed Date', type: 'date', name: 'needed_date', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ],
            'Transparent Stickers': [
                getBranchField,
                { label: 'Surface Application', type: 'select', name: 'surface_application', options: ['Glass (Window/Door/Storefront)', 'Plastic / Acrylic', 'Metal', 'Smooth Painted Wall', 'Mirror', 'Others'] },
                { label: 'Surface (if Others)', type: 'text', name: 'surface_other', placeholder: 'Specify surface', conditionalOn: { field: 'surface_application', value: 'Others' } },
                { label: 'Dimensions (e.g. 2x2, 3x4 ft)', type: 'text', name: 'dimensions', placeholder: 'e.g. 2x2', required: true },
                { label: 'Layout', type: 'select', name: 'layout', options: ['With Layout', 'Without Layout'] },
                { label: 'Lamination', type: 'select', name: 'lamination', options: ['With Laminate', 'Without Laminate'] },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Needed Date', type: 'date', name: 'needed_date', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ],
            'Reflectorized': {
                isDynamic: true,
                base: [
                    getBranchField,
                    { label: 'Product Type *', type: 'select', name: 'product_type', options: ['Subdivision / Gate Pass (Vehicle Sticker)', 'Plate Number / Temporary Plate', 'Custom Reflectorized Sign'], required: true, dynamicTrigger: true }
                ],
                'Subdivision / Gate Pass (Vehicle Sticker)': [
                    { label: 'Subdivision / Company Name *', type: 'text', name: 'gate_pass_subdivision', placeholder: 'GREEN VALLEY SUBDIVISION', required: true },
                    { label: 'Gate Pass Number *', type: 'text', name: 'gate_pass_number', placeholder: 'GP-0215', required: true },
                    { label: 'Plate Number *', type: 'text', name: 'gate_pass_plate', placeholder: 'ABC 1234', required: true },
                    { label: 'Year / Validity *', type: 'text', name: 'gate_pass_year', placeholder: 'VALID UNTIL: 2026', required: true },
                    { label: 'Vehicle Type', type: 'select', name: 'gate_pass_vehicle_type', options: [{ value: '', label: 'Select' }, { value: 'Car', label: 'Car' }, { value: 'Motorcycle', label: 'Motorcycle' }] },
                    { label: 'Exact Size (Width × Height)', type: 'text', name: 'dimensions', placeholder: 'e.g. 12 x 18' },
                    { label: 'Unit', type: 'select', name: 'unit', options: ['in', 'ft'] },
                    { label: 'Needed Date * (dd/mm/yyyy)', type: 'date', name: 'needed_date', required: true },
                    { label: 'Upload Design * (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' },
                    { label: 'Quantity Required *', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true }
                ],
                'Plate Number / Temporary Plate': [
                    { label: 'Material Selection *', type: 'select', name: 'temp_plate_material', options: ['Acrylic', 'Aluminum Sheet', 'Aluminum Coated (Steel)'], required: true },
                    { label: 'Plate Number * (must match OR/CR)', type: 'text', name: 'temp_plate_number', placeholder: 'Must match OR/CR', required: true },
                    { label: 'TEMPORARY PLATE text', type: 'text', name: 'temp_plate_text', placeholder: 'Auto-displayed on design', defaultValue: 'TEMPORARY PLATE' },
                    { label: 'MV File Number', type: 'text', name: 'mv_file_number', placeholder: 'Optional' },
                    { label: 'Dealer Name', type: 'text', name: 'dealer_name', placeholder: 'Optional' },
                    { label: 'Needed Date * (dd/mm/yyyy)', type: 'date', name: 'needed_date', required: true },
                    { label: 'Quantity Required *', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true }
                ],
                'Custom Reflectorized Sign': [
                    { label: 'Needed Date * (dd/mm/yyyy)', type: 'date', name: 'needed_date', required: true },
                    { label: 'Dimensions *', type: 'select_other', name: 'dimensions', options: ['6 x 12 in', '9 x 12 in', '12 x 18 in', '18 x 24 in', '24 x 36 in'], otherOption: 'Others', otherName: 'dimensions_other', otherPlaceholder: 'e.g. 10 x 14 in', required: true },
                    { label: 'Lamination', type: 'select', name: 'laminate_option', options: ['With Lamination', 'Without Lamination'] },
                    { label: 'Layout', type: 'select', name: 'layout', options: ['With Layout', 'Without Layout'] },
                    { label: 'Material Brand', type: 'select', name: 'material_type', options: ['Kiwalite (Japan Brand)', '3M Brand'] },
                    { label: 'Upload Design * (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' },
                    { label: 'Quantity Required *', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true }
                ]
            },
            'Sintraboard': [
                getBranchField,
                { label: 'Sintraboard Type', type: 'select', name: 'sintra_type', options: ['Flat Type', '2D Type (with Frame)', 'Standee (Back Stand Support)'], required: true },
                { label: 'Dimensions (e.g. 12 x 18)', type: 'text', name: 'dimensions', placeholder: 'e.g. 12 x 18', required: true },
                { label: 'Unit', type: 'select', name: 'unit', options: ['in', 'ft'] },
                { label: 'Thickness', type: 'select', name: 'thickness', options: ['3mm', '5mm'], required: true },
                { label: 'Lamination', type: 'select', name: 'lamination', options: ['With Lamination', 'Without Lamination'] },
                { label: 'Layout', type: 'select', name: 'layout', options: ['With Layout', 'Without Layout'] },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ],
            'Standees': [
                getBranchField,
                { label: 'Size', type: 'text', name: 'size', placeholder: 'e.g. 22x28 inches', required: true },
                { label: 'With Stand?', type: 'select', name: 'with_stand', options: ['No', 'Yes'] },
                { label: 'Needed Date', type: 'date', name: 'needed_date', required: true },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ],
            'Souvenirs': [
                getBranchField,
                { label: 'Type', type: 'select', name: 'souvenir_type', options: ['Mug', 'Keychain', 'Tote Bag', 'Pen', 'Tumbler', 'T-Shirt', 'Others'] },
                { label: 'Custom Print?', type: 'select', name: 'custom_print', options: ['No', 'Yes – I have a design'] },
                { label: 'Lamination', type: 'select', name: 'lamination', options: ['With Lamination', 'Without Lamination'] },
                { label: 'Needed Date', type: 'date', name: 'needed_date', required: true },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ]
        };

        function getRequirementsForProduct(productName, category) {
            const term = (productName + ' ' + (category || '')).toLowerCase();
            const svc = productName || category || '';
            if (term.includes('tarpaulin') || term.includes('tarp')) return expandRequirements('Tarpaulin');
            if (term.includes('t-shirt') || term.includes('tshirt')) return expandRequirements('T-Shirt');
            if (term.includes('sticker') || term.includes('decal') || svc === 'Decals / Stickers') return expandRequirements(svc === 'Decals / Stickers' ? 'Decals / Stickers' : 'Stickers');
            if (term.includes('glass') || term.includes('wall')) return expandRequirements('Glass/Wall');
            if (term.includes('transparent')) return expandRequirements('Transparent Stickers');
            if (term.includes('reflectorized') || term.includes('signage')) return expandRequirements('Reflectorized');
            if (term.includes('sintraboard') && !term.includes('standee')) return expandRequirements('Sintraboard');
            if (term.includes('standee')) return expandRequirements('Standees');
            if (term.includes('souvenir')) return expandRequirements('Souvenirs');
            return null;
        }
        function expandRequirements(key, productType) {
            const raw = serviceRequirements[key];
            if (!raw) return [];
            if (raw.isDynamic) {
                const base = (raw.base || []).map(r => typeof r === 'function' ? r() : r).filter(Boolean);
                if (productType && raw[productType]) {
                    return base.concat(raw[productType]);
                }
                return base;
            }
            const arr = Array.isArray(raw) ? raw : [];
            return arr.map(r => typeof r === 'function' ? r() : r).filter(Boolean);
        }

        function renderProducts() {
            const grid = document.getElementById('pos-products-grid');
            if (!grid) {
                console.error('Grid element not found!');
                return;
            }
            const searchEl = document.getElementById('pos-search');
            const catEl = document.getElementById('pos-category');
            const search = (searchEl ? searchEl.value : '').toLowerCase();
            const cat = catEl ? catEl.value : '';

            console.log('Rendering products. Total products:', products.length);

            grid.innerHTML = '';

            const filtered = products.filter(p => {
                const mSearch = p.product_name.toLowerCase().includes(search) || (p.sku && p.sku.toLowerCase().includes(search));
                const mCat = cat === '' || p.category === cat;
                return mSearch && mCat;
            });

            console.log('Filtered products:', filtered.length);
            if (filtered.length > 0) {
                console.log('Sample product:', filtered[0]);
            }

            if (filtered.length === 0) {
                grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:#94a3b8;">No products found.</div>';
                return;
            }

            filtered.forEach((p) => {
                const outOfStock = p.stock_quantity <= 0;
                const imgUrl = escapeHtml(posCatalogImageUrl(p));
                const defaultImg = escapeHtml(POS_DEFAULT_CATALOG_IMG);

                const card = document.createElement('button');
                card.type = 'button';
                card.className = `pos-catalog-card pos-card ${outOfStock ? 'no-stock' : ''}`;
                if (!outOfStock) {
                    card.onclick = async () => {
                        card.classList.add('is-selecting');
                        try {
                            await addToCart(p, null, null, { fxSourceEl: card });
                        } finally {
                            card.classList.remove('is-selecting');
                        }
                    };
                }

                const priceFormatted = formatMoney(p.price || 0);
                const productName = escapeHtml(p.product_name || 'Unnamed Product');
                const category = escapeHtml(p.category || 'Product');
                const stockQty = parseInt(p.stock_quantity) || 0;
                const stockBadge = outOfStock
                    ? '<span class="pos-catalog-card__badge is-danger">Out of Stock</span>'
                    : (stockQty <= (parseInt(p.low_stock_level, 10) || 10)
                        ? `<span class="pos-catalog-card__badge">${stockQty} left</span>`
                        : '');

                card.innerHTML = `
            <div class="pos-catalog-card__media">
                <img src="${imgUrl}" alt="" loading="lazy" decoding="async"
                    onerror="this.onerror=null;this.src='${defaultImg}';">
                ${stockBadge}
            </div>
            <div class="pos-catalog-card__body">
                <span class="pos-catalog-card__meta">${category}</span>
                <p class="pos-catalog-card__name">${productName}</p>
                <div class="pos-catalog-card__price">${priceFormatted}</div>
            </div>
        `;
                grid.appendChild(card);
            });
        }

        function focusBarcodeInput(preferredInput = null) {
            const inputs = Array.from(document.querySelectorAll('.pos-barcode-entry'));
            const preferredIsVisible = preferredInput
                && !!(preferredInput.offsetWidth || preferredInput.offsetHeight || preferredInput.getClientRects().length);
            const input = (preferredIsVisible ? preferredInput : null) || inputs.find(function(el) {
                return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
            }) || inputs[0];
            if (!input) return;
            input.focus();
            input.select();
        }

        function clearBarcodeInputs() {
            document.querySelectorAll('.pos-barcode-entry').forEach(function(input) { input.value = ''; });
        }

        function showPOSScanNotice(title, message, type = 'warning') {
            const container = document.getElementById('pos-scan-toast-container');
            if (!container) return;
            const toast = document.createElement('div');
            const icon = type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-exclamation-circle' : 'fa-exclamation-triangle');
            toast.className = 'pos-scan-toast ' + type;
            toast.innerHTML = '<div class="pos-scan-toast-icon"><i class="fas ' + icon + '"></i></div>'
                + '<div><div class="pos-scan-toast-title"></div><div class="pos-scan-toast-message"></div></div>';
            toast.querySelector('.pos-scan-toast-title').textContent = title;
            toast.querySelector('.pos-scan-toast-message').innerHTML = String(message || '').replace(/\n/g, '<br>');
            container.appendChild(toast);
            requestAnimationFrame(function() { toast.classList.add('show'); });
            setTimeout(function() {
                toast.classList.remove('show');
                setTimeout(function() { toast.remove(); }, 220);
            }, type === 'success' ? 1800 : 3200);
        }

        function scannedCartQuantity(product) {
            const productId = String(product && product.product_id != null ? product.product_id : '');
            if (!productId) return 0;
            return cart.reduce(function(total, item) {
                if (String(item.product_id) !== productId || item.is_service) return total;
                return total + (parseInt(item.qty, 10) || 0);
            }, 0);
        }

        function finishBarcodeScan(input) {
            clearBarcodeInputs();
            focusBarcodeInput(input);
        }

        function isBarcodeTerminatorKey(key) {
            return key === 'Enter' || key === 'Tab' || key === '\r' || key === '\n';
        }

        function normalizeProductBarcode(code) {
            return String(code || '').replace(/[\u0000-\u001F\u007F\u200B-\u200D\u2060\uFEFF]+/g, '').trim();
        }

        function isProtectedPosBarcodeTarget(target) {
            if (!target || target === document.body) return false;
            if (target.closest && target.closest('.pos-barcode-entry')) return true;
            if (target.isContentEditable) return true;
            const tag = String(target.tagName || '').toLowerCase();
            return tag === 'input' || tag === 'textarea' || tag === 'select';
        }

        function installPosBarcodeKeyboardCapture() {
            if (window.__printflowPosProductScannerCaptureInstalled) return;
            window.__printflowPosProductScannerCaptureInstalled = true;
            let buffer = '';
            let startedAt = 0;
            let lastKeyAt = 0;
            const maxGapMs = 120;
            const maxScanMs = 2500;

            function resetBuffer() {
                buffer = '';
                startedAt = 0;
                lastKeyAt = 0;
            }

            document.addEventListener('keydown', function(event) {
                if (event.defaultPrevented || event.ctrlKey || event.altKey || event.metaKey) {
                    resetBuffer();
                    return;
                }
                if (event.target && event.target.closest && event.target.closest('.pos-barcode-entry')) {
                    resetBuffer();
                    return;
                }
                if (isProtectedPosBarcodeTarget(event.target)) {
                    resetBuffer();
                    return;
                }

                const now = Date.now();
                if (isBarcodeTerminatorKey(event.key)) {
                    const raw = buffer;
                    const duration = startedAt ? now - startedAt : Number.MAX_SAFE_INTEGER;
                    resetBuffer();
                    const normalized = normalizeProductBarcode(raw);
                    posBarcodeDebug('key terminator', {terminator: event.key, characters: raw.length, duration_ms: duration});
                    if (!normalized || normalized.length < 3 || duration > maxScanMs) return;
                    // The shared receipt scanner owns canonical receipt payloads
                    // outside the dedicated product input.
                    if (/^PF1:ORDER:[1-9][0-9]{0,9}$/i.test(normalized)) return;
                    event.preventDefault();
                    if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
                    handleBarcodeScan(raw, null, {terminator: event.key, source: 'pos-keyboard-buffer'});
                    return;
                }
                if (event.key === 'Shift' || event.key === 'CapsLock' || event.key === 'NumLock' || event.key === 'Process') return;
                if (event.key.length !== 1 || !/[\x20-\x7E]/.test(event.key)) {
                    resetBuffer();
                    return;
                }
                if (!startedAt || now - lastKeyAt > maxGapMs) {
                    resetBuffer();
                    startedAt = now;
                    posBarcodeDebug('scan started', {source: 'pos-keyboard-buffer'});
                }
                buffer += event.key;
                lastKeyAt = now;
                if (buffer.length > 96) resetBuffer();
            }, true);
        }

        function handleBarcodeScan(code, sourceInput = null, scanMeta = {}) {
            const barcodeEl = sourceInput || Array.from(document.querySelectorAll('.pos-barcode-entry')).find(function(input) {
                return !!(input.offsetWidth || input.offsetHeight || input.getClientRects().length);
            }) || document.getElementById('pos-barcode-input-home');
            const raw = String(code || '');
            const sku = normalizeProductBarcode(raw);
            posBarcodeDebug('raw value', {value: raw, source: scanMeta.source || 'manual'});
            posBarcodeDebug('normalized value', {value: sku, terminator: scanMeta.terminator || ''});
            if (!sku) {
                finishBarcodeScan(barcodeEl);
                return Promise.resolve(false);
            }
            clearBarcodeInputs();
            focusBarcodeInput(barcodeEl);
            return new Promise(function(resolve) {
                barcodeScanQueue.push({sku, barcodeEl, scanMeta: {...scanMeta, queuedAt: Date.now()}, resolve});
                posBarcodeDebug('scan queued', {value: sku, queue_length: barcodeScanQueue.length});
                drainBarcodeScanQueue();
            });
        }

        async function drainBarcodeScanQueue() {
            if (barcodeScanQueueRunning) return;
            barcodeScanQueueRunning = true;
            barcodeScanBusy = true;
            try {
                while (barcodeScanQueue.length > 0) {
                    const scan = barcodeScanQueue.shift();
                    let result = false;
                    try {
                        result = await processBarcodeScan(scan.sku, scan.barcodeEl, scan.scanMeta);
                    } catch (error) {
                        console.error('[POS Barcode] scan processing failed', {
                            value: scan.sku,
                            name: error instanceof Error ? error.name : 'UnknownError',
                            message: error instanceof Error ? error.message : 'Unknown scan error'
                        });
                        showPOSScanNotice('Scan Error', 'Unable to scan product. Please try again.', 'error');
                    } finally {
                        scan.resolve(result);
                        focusBarcodeInput(scan.barcodeEl);
                    }
                }
            } finally {
                barcodeScanBusy = false;
                barcodeScanQueueRunning = false;
                if (barcodeScanQueue.length > 0) drainBarcodeScanQueue();
            }
        }

        async function processBarcodeScan(sku, barcodeEl, scanMeta = {}) {
            posBarcodeDebug('lookup request started', {value: sku, source: scanMeta.source || 'manual'});
            // Printed PrintFlow receipts use this canonical payload. Reuse the
                // existing focused scanner input without adding a competing global
                // keyboard listener or treating the receipt as a product SKU.
                if (/^PF1:ORDER:[1-9][0-9]{0,9}$/i.test(sku)) {
                    try {
                        const lookupResponse = await fetch(
                            staffUrl('staff/api/order_receipt_lookup.php?identifier=') + encodeURIComponent(sku) + '&_=' + Date.now(),
                            { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } }
                        );
                        const lookupData = await lookupResponse.json().catch(function() { return {}; });
                        if (!lookupResponse.ok || !lookupData.success || !lookupData.route) {
                            showPOSScanNotice('Receipt Lookup', lookupData.message || 'Order lookup failed. Please scan the receipt again.', 'error');
                            return;
                        }
                        showPOSScanNotice('Order Found', lookupData.warning || ('Opening ' + lookupData.identifier + '…'), 'success');
                        window.setTimeout(function() { window.location.assign(lookupData.route); }, lookupData.warning ? 900 : 150);
                    } catch (e) {
                        showPOSScanNotice('Network Error', 'Network error while looking up the receipt.', 'error');
                    }
                    return;
                }

                let product = null;
                let availability = null;
                try {
                    const res = await fetch(
                        staffUrl('staff/api/get_product_by_sku.php?sku=') + encodeURIComponent(sku),
                        {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}}
                    );
                    const contentType = res.headers && typeof res.headers.get === 'function'
                        ? String(res.headers.get('content-type') || '')
                        : '';
                    const data = await res.json().catch(function() { return null; });
                    posBarcodeDebug('lookup response', {
                        value: sku,
                        http_status: Number(res.status || 0),
                        content_type: contentType,
                        success: !!(data && data.success),
                        availability: data && data.availability ? data.availability : '',
                        product_id: data && data.product && data.product.product_id ? data.product.product_id : null,
                        response_sku: data && data.product && data.product.sku ? data.product.sku : ''
                    });
                    if (!res.ok || !data || (contentType && !contentType.toLowerCase().includes('application/json'))) {
                        showPOSScanNotice('Scan Error', 'Unable to scan product. Please try again.', 'error');
                        return;
                    }
                    if (!data.success) {
                        showPOSScanNotice('Scan Error', data.message || 'Could not scan barcode.', 'error');
                        return;
                    }
                    product = data.product || null;
                    availability = data.availability || (product ? 'available' : null);
                    if (product && availability === 'available') {
                        const existingIndex = products.findIndex(p => String(p.product_id) === String(product.product_id));
                        if (existingIndex >= 0) products[existingIndex] = product;
                        else products.push(product);
                    }
                } catch (e) {
                    showPOSScanNotice('Network Error', 'Network error while scanning barcode.', 'error');
                    return;
                }
                if (!product) {
                    showPOSScanNotice('Product Not Found', 'No product matches the scanned barcode or SKU.', 'warning');
                    return;
                }
                if (availability === 'archived' || String(product.status || '').toLowerCase() === 'archived') {
                    showPOSScanNotice('Product Unavailable', 'This product has been archived and cannot be sold.', 'warning');
                    return;
                }
                if (availability === 'inactive' || String(product.status || '').toLowerCase() === 'deactivated') {
                    showPOSScanNotice('Product Inactive', 'This product is currently inactive and cannot be sold.', 'warning');
                    return;
                }
                if (availability === 'pos_unavailable') {
                    showPOSScanNotice('Product Unavailable', 'This product is not available for POS sale.', 'warning');
                    return;
                }

                const stock = parseInt(product.stock_quantity, 10) || 0;
                if (stock <= 0) {
                    showPOSScanNotice('Out of Stock', 'Product: ' + (product.product_name || 'Product') + '\nSKU: ' + (product.sku || sku) + '\nThis product is currently out of stock and cannot be added to the cart.', 'warning');
                    return;
                }
                if (scannedCartQuantity(product) >= stock) {
                    showPOSScanNotice('Insufficient Stock', 'Only ' + stock + ' item(s) are currently available.', 'warning');
                    return;
                }

                const result = await addToCart(product, null, null, { silentErrors: true });
                posBarcodeDebug('add-to-cart result', {
                    value: sku,
                    product_id: product.product_id,
                    success: !!(result && result.success),
                    message: result && result.message ? result.message : '',
                    elapsed_ms: scanMeta.queuedAt ? Date.now() - scanMeta.queuedAt : null
                });
                if (result && result.success) {
                    showPOSScanNotice('Added to Cart', (product.product_name || 'Product') + ' was added to the cart.', 'success');
                    renderProducts();
                } else if (result && result.message && result.message.toLowerCase().includes('out of stock')) {
                    showPOSScanNotice('Out of Stock', 'Product: ' + (product.product_name || 'Product') + '\nSKU: ' + (product.sku || sku) + '\nThis product is currently out of stock and cannot be added to the cart.', 'warning');
                } else if (result && result.message && result.message.toLowerCase().includes('stock')) {
                    showPOSScanNotice('Insufficient Stock', 'Only ' + stock + ' item(s) are currently available.', 'warning');
                } else if (result && result.message) {
                    showPOSScanNotice('Scan Error', result.message, 'error');
                } else if (result && result.success === false) {
                    showPOSScanNotice('Scan Error', 'Could not add this product to the cart.', 'error');
                }
            return true;
        }
        async function addToCart(p, overridePrice = null, overrideName = null, options = {}) {
            if (p.price == 0 && overridePrice === null) {
                openPriceModal(p);
                return;
            }

            const variantPlan = posResolveVariantBeforeAdd(p);
            if (variantPlan.action === 'error') {
                if (!options.silentErrors) {
                    await showPOSAlert('Cannot Add Product', variantPlan.message, 'warning');
                }
                return { success: false, message: variantPlan.message };
            }
            if (variantPlan.action === 'prompt') {
                openVariantModal(p, variantPlan.options, variantPlan.fieldLabel, options);
                return { success: true, pending_variant: true };
            }

            return await posAddProductToCart(p, overridePrice, overrideName, variantPlan.customization, options);
        }

        let pendingCustomProduct = null;
        let currentCustomRequirements = null;
        let posDynamicRequirements = null;
        let posDynamicFieldStartIndex = 500;

        function renderPosField(container, req, idx, baseStyle) {
            const div = document.createElement('div');
            div.style.display = 'flex';
            div.style.flexDirection = 'column';
            div.style.gap = '4px';
            const reqLabel = req.label || '';
            const reqName = req.name || ('field_' + idx);
            const isOpt = reqLabel.includes('(if ');
            let label = `<label style="font-size:12px; font-weight:600; color:#475569; text-transform:uppercase; letter-spacing:0.05em;">${reqLabel}</label>`;
            let inputHtml = '';
            if (req.type === 'dimensions_ft') {
                const subNames = req.subNames || ['width', 'height'];
                const placeholders = req.placeholders || ['Width', 'Height'];
                div.innerHTML = `<label style="font-size:12px; font-weight:600; color:#475569; text-transform:uppercase; letter-spacing:0.05em;">${reqLabel}</label>
            <div style="display:flex; align-items:center; gap:8px;">
                <input type="number" id="custom_field_${idx}_0" name="${subNames[0]}" placeholder="${placeholders[0]}" step="0.1" style="${baseStyle}; flex:1;" data-field-name="${subNames[0]}">
                <span style="flex-shrink:0; font-weight:700; color:#94a3b8;">×</span>
                <input type="number" id="custom_field_${idx}_1" name="${subNames[1]}" placeholder="${placeholders[1]}" step="0.1" style="${baseStyle}; flex:1;" data-field-name="${subNames[1]}">
            </div>`;
                div.dataset.fieldType = 'dimensions_ft';
                div.dataset.fieldIndex = String(idx);
            } else if (req.type === 'select_other') {
                const opts = req.options || [];
                const otherOpt = req.otherOption || 'Others';
                const otherName = req.otherName || (reqName + '_other');
                const otherPh = (req.otherPlaceholder || 'Enter custom').replace(/"/g, '&quot;');
                inputHtml = `<select id="custom_field_${idx}" name="${reqName}" style="${baseStyle}" data-field-name="${reqName}" data-other-option="${otherOpt}" data-other-name="${otherName}" onchange="togglePosOtherInput(${idx}, '${otherOpt}', '${otherName}')">`;
                inputHtml += `<option value="">Select...</option>`;
                opts.forEach(opt => {
                    const val = (typeof opt === 'object' && opt !== null && 'value' in opt) ? opt.value : opt;
                    const lab = (typeof opt === 'object' && opt !== null && 'label' in opt) ? opt.label : opt;
                    inputHtml += `<option value="${String(val).replace(/"/g, '&quot;')}">${String(lab).replace(/</g, '&lt;')}</option>`;
                });
                inputHtml += `</select>`;
                inputHtml += `<div id="custom_other_${idx}" style="display:none; margin-top:6px;"><input type="text" id="custom_field_${idx}_other" name="${otherName}" placeholder="${otherPh}" style="${baseStyle}" data-field-name="${otherName}"></div>`;
                div.innerHTML = label + inputHtml;
                div.dataset.fieldType = 'select_other';
                if (req.disabledWhen && req.disabledWhen.field && req.disabledWhen.value) {
                    const wrap = document.createElement('div');
                    wrap.dataset.disabledWhenField = req.disabledWhen.field;
                    wrap.dataset.disabledWhenValue = req.disabledWhen.value;
                    wrap.dataset.disabledWhenDisplay = req.disabledWhen.display || 'Provided by Customer';
                    const readonlyDiv = document.createElement('div');
                    readonlyDiv.className = 'pos-disabled-when-display';
                    readonlyDiv.style.cssText = 'display:none; padding:10px 12px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px; font-size:14px; color:#64748b;';
                    readonlyDiv.innerHTML = reqLabel + ': <strong style="color:#475569;">' + (req.disabledWhen.display || 'Provided by Customer') + '</strong>';
                    wrap.appendChild(div);
                    wrap.appendChild(readonlyDiv);
                    container.appendChild(wrap);
                    return wrap;
                }
            } else if (req.type === 'select') {
                inputHtml = `<select id="custom_field_${idx}" name="${reqName}" style="${baseStyle}" data-field-name="${reqName}">`;
                inputHtml += `<option value="">Select...</option>`;
                const opts = req.options || [];
                opts.forEach(opt => {
                    const val = (typeof opt === 'object' && opt !== null && 'value' in opt) ? opt.value : opt;
                    const lab = (typeof opt === 'object' && opt !== null && 'label' in opt) ? opt.label : opt;
                    inputHtml += `<option value="${String(val).replace(/"/g, '&quot;')}">${String(lab).replace(/</g, '&lt;')}</option>`;
                });
                inputHtml += `</select>`;
                div.innerHTML = label + inputHtml;
            } else if (req.type === 'file') {
                inputHtml = `<input type="file" id="custom_field_${idx}" name="${reqName}" accept="${(req.accept || '').replace(/"/g, '&quot;')}" style="${baseStyle}" data-field-name="${reqName}">`;
                div.innerHTML = label + inputHtml;
            } else if (req.type === 'date') {
                const minDate = new Date().toISOString().split('T')[0];
                inputHtml = `<input type="date" id="custom_field_${idx}" name="${reqName}" min="${minDate}" style="${baseStyle}" data-field-name="${reqName}">`;
                div.innerHTML = label + inputHtml;
            } else {
                const ph = (req.placeholder || '').replace(/"/g, '&quot;');
                const st = req.step ? ` step="${req.step}"` : '';
                const dv = req.defaultValue ? ` value="${String(req.defaultValue).replace(/"/g, '&quot;')}"` : '';
                inputHtml = `<input type="${req.type || 'text'}" id="custom_field_${idx}" name="${reqName}" placeholder="${ph}"${st}${dv} style="${baseStyle}" data-field-name="${reqName}">`;
                div.innerHTML = label + inputHtml;
            }
            div.dataset.fieldName = reqName;
            if (req.conditionalOn && req.conditionalOn.field && req.conditionalOn.value) {
                const wrap = document.createElement('div');
                wrap.style.display = 'none';
                wrap.dataset.conditionalField = req.conditionalOn.field;
                wrap.dataset.conditionalValue = req.conditionalOn.value;
                wrap.appendChild(div);
                container.appendChild(wrap);
                return wrap;
            }
            container.appendChild(div);
            return div;
        }

        function renderReflectorizedDynamicFields(productType) {
            const dynContainer = document.getElementById('cm-dynamic-product-fields');
            if (!dynContainer) return;
            dynContainer.innerHTML = '';
            posDynamicRequirements = null;
            if (!productType) return;
            const refl = serviceRequirements['Reflectorized'];
            if (!refl || !refl[productType]) return;
            posDynamicRequirements = refl[productType];
            const baseStyle = 'width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; outline:none;';
            posDynamicRequirements.forEach((req, i) => {
                const idx = posDynamicFieldStartIndex + i;
                renderPosField(dynContainer, req, idx, baseStyle);
            });
        }

        function openCustomModal(product, requirements) {
            pendingCustomProduct = product;
            currentCustomRequirements = requirements;
            posDynamicRequirements = null;

            const isReflectorized = (product.category === 'Reflectorized' || product.product_name === 'Reflectorized');

            document.getElementById('cm-title').textContent = (product.product_name || product.name) + ' Details';
            const container = document.getElementById('cm-dynamic-fields');
            container.innerHTML = '';

            const baseStyle = 'width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; outline:none;';

            requirements.forEach((req, idx) => {
                const r = typeof req === 'function' ? req() : req;
                if (r) renderPosField(container, r, idx, baseStyle);
            });
            wireUpConditionalFields(container);
            wireUpDisabledWhenFields(container);
            if (isReflectorized) {
                const dynWrap = document.createElement('div');
                dynWrap.id = 'cm-dynamic-product-fields';
                dynWrap.style.marginTop = '12px';
                container.appendChild(dynWrap);
                const ptSelect = container.querySelector('select[name="product_type"]');
                if (ptSelect) {
                    ptSelect.addEventListener('change', function () {
                        const val = this.value;
                        renderReflectorizedDynamicFields(val);
                    });
                    if (ptSelect.value) renderReflectorizedDynamicFields(ptSelect.value);
                }
            }

            // Inject Price Input directly into the form if product price is 0
            const initialPrice = parseFloat(product.price) || 0;
            if (initialPrice === 0) {
                const priceHtml = `
            <div id="cm-price-section" style="margin-top:16px; padding-top:16px; border-top:1px dashed #cbd5e1;">
                <label style="display:block; font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:8px; letter-spacing:0.05em;">Negotiated Price *</label>
                <div style="position: relative;">
                    <span style="position: absolute; left: 16px; top: 14px; font-weight: 700; color: #94a3b8;">₱</span>
                    <input type="text" id="cm-price-input" 
                           oninput="let v = this.value.replace(/[^0-9.]/g, ''); let p = v.split('.'); p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ','); this.value = p.join('.');"
                           onblur="if(this.value){ let n = parseFloat(this.value.replace(/,/g, '')) || 0; this.value = n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }"
                           style="width:100%; padding:14px 14px 14px 32px; border:1px solid #e2e8f0; border-radius:12px; font-weight:800; font-size:24px; background:#f8fafc; color:#1e293b; outline:none;" placeholder="0.00">
                </div>
            </div>`;
                container.insertAdjacentHTML('beforeend', priceHtml);

                // Ensure scrolling works properly
                container.style.maxHeight = '55vh';
            }

            document.getElementById('custom-modal-overlay').style.display = 'flex';
        }

        function closeCustomModal() {
            document.getElementById('custom-modal-overlay').style.display = 'none';
            pendingCustomProduct = null;
            currentCustomRequirements = null;
            posDynamicRequirements = null;
        }
        function togglePosOtherInput(idx, otherOption, otherName) {
            const sel = document.getElementById('custom_field_' + idx);
            const wrap = document.getElementById('custom_other_' + idx);
            if (sel && wrap) {
                wrap.style.display = (sel.value === otherOption) ? 'block' : 'none';
                if (sel.value !== otherOption) {
                    const inp = wrap.querySelector('input');
                    if (inp) inp.value = '';
                }
            }
        }

        function wireUpConditionalFields(container) {
            if (!container) return;
            container.querySelectorAll('[data-conditional-field]').forEach(wrap => {
                const fieldName = wrap.dataset.conditionalField;
                const showValue = wrap.dataset.conditionalValue;
                const parentSelect = container.querySelector('select[name="' + fieldName + '"]');
                const input = wrap.querySelector('input');
                if (!parentSelect) return;
                function sync() {
                    const show = parentSelect.value === showValue;
                    wrap.style.display = show ? 'block' : 'none';
                    if (!show && input) input.value = '';
                }
                parentSelect.addEventListener('change', sync);
                sync();
            });
        }

        function wireUpDisabledWhenFields(container) {
            if (!container) return;
            container.querySelectorAll('[data-disabled-when-field]').forEach(wrap => {
                const fieldName = wrap.dataset.disabledWhenField;
                const triggerValue = wrap.dataset.disabledWhenValue;
                const triggerSelect = container.querySelector('select[name="' + fieldName + '"]');
                const editableChild = wrap.firstElementChild;
                const readonlyChild = wrap.querySelector('.pos-disabled-when-display');
                if (!triggerSelect || !editableChild || !readonlyChild) return;
                function sync() {
                    const disabled = triggerSelect.value === triggerValue;
                    editableChild.style.display = disabled ? 'none' : 'flex';
                    readonlyChild.style.display = disabled ? 'block' : 'none';
                    const sel = editableChild.querySelector('select');
                    const otherWrap = editableChild.querySelector('[id^="custom_other_"]');
                    const otherInp = otherWrap ? otherWrap.querySelector('input') : null;
                    if (sel) sel.disabled = disabled;
                    if (disabled) {
                        if (sel) sel.value = '';
                        if (otherInp) otherInp.value = '';
                        if (otherWrap) otherWrap.style.display = 'none';
                    }
                }
                triggerSelect.addEventListener('change', sync);
                sync();
            });
        }

        async function collectRequirementsToCustomization(requirements, startIdx, customization, validation) {
            if (!requirements) return;
            requirements.forEach((req, i) => {
                const resolvedReq = typeof req === 'function' ? req() : req;
                if (!resolvedReq) return;
                req = resolvedReq;
                const idx = startIdx + i;
                const name = req.name || ('field_' + idx);
                let val = null;

                if (req.type === 'dimensions_ft') {
                    const w = document.getElementById(`custom_field_${idx}_0`);
                    const h = document.getElementById(`custom_field_${idx}_1`);
                    if (w && h) {
                        const wv = w.value, hv = h.value;
                        if (req.required && (!wv || !hv)) validation.valid = false;
                        if (wv) customization['width'] = wv;
                        if (hv) customization['height'] = hv;
                    }
                    return;
                }
                if (req.type === 'select_other') {
                    const sel = document.getElementById(`custom_field_${idx}`);
                    const other = document.getElementById(`custom_field_${idx}_other`);
                    const otherOpt = req.otherOption || 'Others';
                    if (req.disabledWhen && req.disabledWhen.field) {
                        const overlay = document.getElementById('custom-modal-overlay');
                        const trigger = overlay ? overlay.querySelector('select[name="' + req.disabledWhen.field + '"]') : null;
                        if (trigger && trigger.value === req.disabledWhen.value) {
                            customization[name] = 'Provided by Customer';
                            return;
                        }
                    }
                    if (sel) {
                        val = sel.value;
                        if (val === otherOpt && other && other.value) {
                            val = other.value;
                        } else if (val === otherOpt && req.required) {
                            validation.valid = false;
                            return;
                        }
                        if (req.required && !val) validation.valid = false;
                        if (val) customization[name] = val;
                    }
                    return;
                }

                const el = document.getElementById(`custom_field_${idx}`);
                if (!el) return;
                val = el.value;
                if (req.required && !val) validation.valid = false;
                if (val) customization[name] = val;
            });

            for (let i = 0; i < requirements.length; i++) {
                const resolvedReq = typeof requirements[i] === 'function' ? requirements[i]() : requirements[i];
                if (!resolvedReq || resolvedReq.type !== 'file') {
                    continue;
                }
                const req = resolvedReq;
                const idx = startIdx + i;
                const name = req.name || ('field_' + idx);
                const el = document.getElementById(`custom_field_${idx}`);
                if (!(el && el.files && el.files.length > 0)) {
                    continue;
                }
                try {
                    if (name === 'design_file') {
                        await posApplyStagedUpload(customization, el.files[0], 'design');
                        customization[name] = customization.design_upload_name;
                    } else if (name === 'reference_file') {
                        await posApplyStagedUpload(customization, el.files[0], 'reference');
                        customization[name] = customization.reference_upload_name;
                    } else {
                        const payload = await posReadFilePayload(el.files[0]);
                        customization[name] = payload.name;
                    }
                } catch (uploadErr) {
                    await showPOSAlert('Upload Failed', uploadErr.message || 'Could not save the design file.', 'error');
                    return;
                }
            }
        }

        async function confirmCustomization() {
            if (!pendingCustomProduct || !currentCustomRequirements) return;

            const customization = {};
            const validation = { valid: true };

            await collectRequirementsToCustomization(currentCustomRequirements, 0, customization, validation);
            if (posDynamicRequirements) {
                await collectRequirementsToCustomization(posDynamicRequirements, posDynamicFieldStartIndex, customization, validation);
            }

            if (pendingCustomProduct.category === 'Reflectorized' || pendingCustomProduct.product_name === 'Reflectorized') {
                customization.service_type = 'Reflectorized Signage';
                if (!customization.product_type) {
                    validation.valid = false;
                }
            }

            if (!validation.valid) {
                await showPOSAlert('Incomplete Fields', 'Please complete all required fields (marked *) before proceeding.', 'warning');
                return;
            }

            let price = parseFloat(pendingCustomProduct.price) || 0;
            if (price === 0) {
                const pInput = document.getElementById('cm-price-input');
                if (pInput) {
                    price = parseFloat(pInput.value.replace(/,/g, ''));
                    if (isNaN(price) || price <= 0) {
                        await showPOSAlert('Invalid Price', 'Please enter a valid negotiated price.', 'warning');
                        pInput.focus();
                        return;
                    }
                } else {
                    // Fallback if input somehow didn't render
                    const p = pendingCustomProduct;
                    closeCustomModal();
                    openPriceModal(p, false, customization);
                    return;
                }
            }

            const result = await syncedCartAction('add', {
                product_id: pendingCustomProduct.product_id,
                name: pendingCustomProduct.product_name || pendingCustomProduct.name,
                price: price,
                qty: 1,
                customization: customization,
                is_service: true
            });

            if (result.success) {
                closeCustomModal();
            }
        }

        let pendingProduct = null;
        let isOtherService = false;
        let pendingCustomization = null;

        function openPriceModal(p, isOther = false, customization = null) {
            pendingProduct = p;
            isOtherService = isOther;
            pendingCustomization = customization || null;

            document.getElementById('pm-title').textContent = isOther ? 'Custom Service' : 'Set Service Price';
            document.getElementById('pm-name-group').style.display = isOther ? 'block' : 'none';
            document.getElementById('pm-name-input').value = isOther ? '' : (p.product_name || p.name || '');
            document.getElementById('pm-price-input').value = p.price > 0 ? p.price : '';
            document.getElementById('price-modal-overlay').style.display = 'flex';

            const focusEl = isOther ? 'pm-name-input' : 'pm-price-input';
            setTimeout(() => document.getElementById(focusEl).focus(), 100);
        }

        function closePriceModal() {
            document.getElementById('price-modal-overlay').style.display = 'none';
            pendingProduct = null;
            isOtherService = false;
            pendingCustomization = null;
        }

        async function confirmPrice() {
            const name = document.getElementById('pm-name-input').value.trim();
            const price = parseFloat(document.getElementById('pm-price-input').value);

            if (isOtherService && !name) {
                await showPOSAlert('Missing Information', 'Please enter a service name.', 'warning');
                return;
            }
            if (isNaN(price) || price <= 0) {
                await showPOSAlert('Invalid Price', 'Please enter a valid price.', 'warning');
                return;
            }

            addToCartWithCustomization(pendingProduct, price, name, pendingCustomization);
            closePriceModal();
        }

        async function addToCartWithCustomization(p, price, name, customization) {
            const itemName = name || p.product_name || p.name;
            const srcPage = String(customization?.source_page || '').trim().toLowerCase();
            const serviceLike = !!(
                customization
                && (
                    customization.service_id
                    || customization.service_type
                    || srcPage === 'services'
                    || srcPage === 'service'
                )
            );
            await syncedCartAction('add', {
                product_id: p.product_id,
                name: itemName,
                price: price,
                qty: 1,
                customization: customization,
                is_service: serviceLike
            });
        }

        function setActiveService(btn) {
            document.querySelectorAll('.pos-services-grid .service-btn').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');
            setTimeout(() => btn && btn.classList.remove('active'), 400);
        }

        // addQuickService kept as no-op for legacy compatibility
        function addQuickService(serviceName) { }

        async function updateQtyByCartIndex(index, delta) {
            const item = cart[index];
            if (!item) return;
            let newQty = parseInt(item.qty) + delta;
            if (newQty < 1) newQty = 1;
            if (newQty > 100) {
                await showPOSAlert('Quantity Limit', "Maximum quantity per item is 100.", 'warning');
                newQty = 100;
            }
            await syncedCartAction('update', { index, qty: newQty });
        }

        async function removeByCartIndex(index) {
            await syncedCartAction('remove', { index });
        }

        async function clearCart() {
            if (cart.length > 0 && (await showPOSConfirm('Clear Order', 'Are you sure you want to clear the current order?', 'Clear', 'danger'))) {
                await syncedCartAction('clear');
                document.getElementById('pos-tendered').value = '';
            }
        }

        function renderCart() {
            const cont = document.getElementById('pos-cart-items');
            currentTotal = 0;

            if (cart.length === 0) {
                cont.innerHTML = `<div class="pos-empty-state"><i class="fas fa-shopping-basket"></i><p>Cart is empty</p></div>`;
            } else {
                cont.innerHTML = '';
                cart.forEach((item, index) => {
                    const rowTotal = item.price * item.qty;
                    currentTotal += rowTotal;
                    const div = document.createElement('div');
                    div.className = 'pos-cart-item';

                    // Check if item is a service (price = 0 or is_service flag)
                    const isService = item.is_service || item.price === 0;
                    const priceWasSet = item.price_set === true;

                    // Check if material has been set in customization
                    const hasMaterialSet = item.customization && (
                        item.customization['Material Selection'] || 
                        item.customization['Material Brand'] || 
                        item.customization['Material'] ||
                        item.customization['temp_plate_material'] ||
                        item.customization['material_type']
                    );

                    if (item.customization && typeof item.customization === 'object') {
                        try {
                            div.dataset.customization = JSON.stringify(item.customization);
                        } catch (_) {}
                    }

                    const variantLabel = posCartItemVariantLabel(item);
                    const priceHtml = (isService && !priceWasSet && !hasMaterialSet)
                        ? `<button type="button" class="pos-btn-set-price" onclick="redirectToSetPrice(${index})" title="Click to set price in Customizations">
                    <i class="fas fa-tag"></i> Set Price
                  </button>`
                        : `<div class="pos-item-price">${formatMoney(item.price)}</div>`;

                    div.innerHTML = `
                <div class="pos-cart-item-top">
                    <div class="pos-item-details">
                        <div class="pos-item-name">${escapeHtml(item.name)}${variantLabel ? `<div style="font-size:11px; color:#64748b; margin-top:2px;">${escapeHtml(variantLabel)}</div>` : ''}</div>
                    </div>
                    <button type="button" class="pos-item-remove" onclick="removeByCartIndex(${index})" title="Remove item" aria-label="Remove item">
                        <i class="fas fa-trash-alt"></i> Remove
                    </button>
                </div>
                <div class="pos-cart-item-bottom">
                    <div class="pos-item-action">${priceHtml}</div>
                    <div class="pos-item-controls">
                        <button type="button" class="pos-qty-btn" onclick="updateQtyByCartIndex(${index}, -1)" aria-label="Decrease quantity">&minus;</button>
                        <input class="pos-qty-val" value="${item.qty}" readonly aria-label="Quantity">
                        <button type="button" class="pos-qty-btn" onclick="updateQtyByCartIndex(${index}, 1)" aria-label="Increase quantity">&plus;</button>
                    </div>
                    <div class="pos-item-total">${formatMoney(rowTotal)}</div>
                </div>
            `;
                    cont.appendChild(div);
                });
            }

            const fTotal = formatMoney(currentTotal);
            document.getElementById('pos-subtotal').textContent = fTotal;
            document.getElementById('pos-total').textContent = fTotal;

            calculateChange();
            updateCheckoutState();
        }

        // Handlers are used above in renderCart via 'index' directly


        // Handlers are used above in renderCart via 'index' directly


        function toggleReferenceField() {
            const pm = document.getElementById('pos-payment-method').value;
            const isPayMongo = isPayMongoPaymentMethod(pm);
            document.getElementById('tender-group').style.display = isPayMongo ? 'none' : '';
            document.getElementById('change-group').style.display = isPayMongo ? 'none' : '';
            updateCheckoutState();
        }

        function isPayMongoPaymentMethod(method) {
            return String(method || '').trim() === 'PayMongo QRPh';
        }

        function calculateChange() {
            if (currentTotal === 0) {
                document.getElementById('pos-change').textContent = formatMoney(0);
                return;
            }
            const tenderedInput = document.getElementById('pos-tendered');
            let tendered = parseFloat(tenderedInput.value) || 0;

            if (tendered > 1000000) {
                tendered = 1000000;
                tenderedInput.value = tendered;
            }

            let change = tendered - currentTotal;
            if (change < 0) change = 0; // Must never be negative on display

            const changeEl = document.getElementById('pos-change');
            changeEl.textContent = formatMoney(change);
            changeEl.style.color = (tendered < currentTotal && tendered > 0) ? '#ef4444' : 'var(--staff-primary)';

            updateCheckoutState();
        }

        function updateCheckoutState() {
            const btn = document.getElementById('pos-checkout-btn');
            const icon = document.getElementById('checkout-icon');
            const text = document.getElementById('checkout-text');

            if (posCheckoutRequestInFlight) {
                btn.disabled = true;
                icon.className = 'fas fa-spinner fa-spin';
                text.textContent = 'Processing...';
                return;
            }

            if (cart.length === 0) {
                btn.disabled = true;
                icon.className = 'fas fa-lock';
                text.textContent = 'Select Items';
                return;
            }

            let canCheckout = true;
            let message = 'Complete Sale';

            // Check if customer is selected
            const customer = $('#pos-customer').val();
            if (!customer) {
                canCheckout = false;
                message = 'Select Customer';
            }

            // Check if cart has any services with price = 0
            const hasUnpricedService = cart.some(i => (i.is_service || i.price === 0) && i.price === 0);

            if (hasUnpricedService) {
                canCheckout = false;
                message = 'Set Price First';
                icon.className = 'fas fa-lock';
                text.textContent = message;
                btn.disabled = true;
                return;
            }

            const pm = document.getElementById('pos-payment-method').value;

            // Regular products require payment
            const tendered = parseFloat(document.getElementById('pos-tendered').value) || 0;
            if (!isPayMongoPaymentMethod(pm) && (tendered < currentTotal || tendered > 1000000)) {
                canCheckout = false;
                if (message === 'Complete Sale') message = 'Enter Valid Amount';
            }

            btn.disabled = !canCheckout;
            icon.className = canCheckout ? 'fas fa-check-circle' : 'fas fa-lock';
            text.textContent = message;
        }

        async function processCheckout() {
            if (cart.length === 0 || posCheckoutRequestInFlight || posCheckoutConfirmOpen) return;

            console.log('[POS CHECKOUT] started');
            console.log('[POS CHECKOUT] validating cart', { items: cart.length, total: currentTotal });

            for (const item of cart) {
                if (item.is_service) continue;
                const catalogProduct = products.find(p => String(p.product_id) === String(item.product_id));
                if (!catalogProduct || !catalogProduct.has_variant_stock) continue;
                if (!posCartItemVariantLabel(item)) {
                    const fieldLabel = catalogProduct.variant_stock_field_label || 'stock option';
                    await showPOSAlert(
                        'Selection Required',
                        item.name + ': please select a ' + fieldLabel + ' before checkout. Remove this item and add it again.',
                        'warning'
                    );
                    return;
                }
            }

            // Validate customer selection
            const customer = $('#pos-customer').val();
            if (!customer) {
                await showPOSAlert('Select Customer', 'Please select a customer before checkout.', 'warning');
                return;
            }

            // Block checkout if any item has price = 0
            const hasUnpricedService = cart.some(i => (i.is_service || i.price === 0) && i.price === 0);
            if (hasUnpricedService) {
                await showPOSAlert('Price Required', 'Please set the price for all items before completing the sale.\n\nClick the yellow "Set Price" button on items to set their price in Customizations.', 'warning');
                return;
            }

            const pm = document.getElementById('pos-payment-method').value;
            const tendered = parseFloat(document.getElementById('pos-tendered').value) || 0;

            if (!isPayMongoPaymentMethod(pm) && (tendered < currentTotal || tendered > 1000000)) {
                await showPOSAlert('Invalid Amount', "Amount paid must be at least " + formatMoney(currentTotal) + " and not exceed ₱1,000,000.", 'warning');
                return;
            }

            const changeAmount = isPayMongoPaymentMethod(pm) ? 0 : tendered - currentTotal;
            const confirmMsg = isPayMongoPaymentMethod(pm)
                ? `Create a Dynamic QR Ph payment for ${formatMoney(currentTotal)}? The sale remains unpaid until PayMongo confirms it.`
                : `Confirm sale of ${formatMoney(currentTotal)} using ${pm}?\nChange due: ${formatMoney(changeAmount)}`;

            posCheckoutConfirmOpen = true;
            const confirmed = await showPOSConfirm('Confirm Transaction', confirmMsg);
            posCheckoutConfirmOpen = false;
            if (!confirmed) return;

            posCheckoutRequestInFlight = true;
            updateCheckoutState();

            resetPayMongoPosCheckoutState(false);
            const checkoutToken = getPosPayMongoCheckoutToken();
            posPayMongoCheckoutPending = true;
            const payload = {
                action: 'walkin_checkout',
                customer_id: $('#pos-customer').val(),
                payment_method: pm,
                reference_number: '',
                amount_tendered: tendered,
                csrf_token: POS_CSRF_TOKEN,
                checkout_token: checkoutToken,
                items: cart.map(posCheckoutItemPayload)
            };

            let checkoutData = null;
            let checkoutErrorMessage = '';
            const checkoutUrl = staffUrl('staff/api/pos_checkout.php');
            try {
                console.log('[POS CHECKOUT] request sent', checkoutUrl, { items: payload.items.length });
                const startedAt = performance.now();
                const res = await fetchWithTimeout(checkoutUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                }, 45000);
                console.log('[POS CHECKOUT] response received', {
                    status: res.status,
                    ms: Math.round(performance.now() - startedAt)
                });
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseErr) {
                    console.error('[POS CHECKOUT] ERROR invalid JSON response', text.slice(0, 500));
                    checkoutErrorMessage = 'Server returned an invalid response. Check the browser console for details.';
                    return;
                }
                if (res.ok && data.success) {
                    console.log('[POS CHECKOUT] checkout completed', { orderId: data.order_id });
                    checkoutData = data;
                } else {
                    console.error('[POS CHECKOUT] ERROR', {
                        status: res.status,
                        stage: data.stage || '',
                        message: data.message || ''
                    });
                    checkoutErrorMessage = data.message
                        || ('Checkout failed with HTTP ' + res.status + '.');
                }
            } catch (e) {
                console.error('[POS CHECKOUT] ERROR', e);
                checkoutErrorMessage = e.name === 'AbortError'
                    ? 'Checkout took too long to respond. Please refresh the POS and check Store Orders before trying again.'
                    : ('Network error: ' + e.message);
            } finally {
                posCheckoutConfirmOpen = false;
                posCheckoutRequestInFlight = false;
                updateCheckoutState();
                console.log('[POS CHECKOUT] UI unlocked');
            }

            if (checkoutErrorMessage) {
                await showPOSAlert('Checkout Failed', checkoutErrorMessage, 'error');
                return;
            }
            if (!checkoutData) return;

            posPayMongoCheckoutPending = false;
            resetPayMongoPosCheckoutState();

            if (checkoutData.payment_pending && checkoutData.payment) {
                openPayMongoPosModal(checkoutData.order_id, checkoutData.payment);
                const clearResult = await syncedCartAction('clear', {}, {silentErrors: true, timeoutMs: 10000});
                if (!clearResult.success) {
                    cart = [];
                    renderCart();
                    updateCheckoutState();
                }
                return;
            }

            document.getElementById('pos-payment-method').value = 'Cash';
            document.getElementById('pos-tendered').value = '';
            toggleReferenceField();
            calculateChange();

            if (checkoutData.receipt && checkoutData.order_id) {
                try {
                    openReceiptModal(checkoutData.receipt);
                } catch (receiptError) {
                    console.error('[POS CHECKOUT] receipt modal failed:', receiptError);
                    await showPOSAlert(
                        'Sale Completed',
                        'Order #' + checkoutData.order_id + ' was saved, but the receipt preview could not be opened.',
                        'warning'
                    );
                }
            } else {
                await showPOSAlert(
                    'Sale Completed',
                    (checkoutData.message || 'Sale completed successfully.')
                        + (checkoutData.order_id ? ' Order #' + checkoutData.order_id + '.' : ''),
                    checkoutData.warning ? 'warning' : 'success'
                );
            }

            const clearResult = await syncedCartAction('clear', {}, {silentErrors: true, timeoutMs: 10000});
            if (!clearResult.success) {
                cart = [];
                renderCart();
            }
            updateCheckoutState();
        }

        function getPosPayMongoCheckoutToken(forceNew = false) {
            if (forceNew) {
                const bytes = new Uint8Array(24);
                crypto.getRandomValues(bytes);
                posPayMongoCheckoutAttemptToken = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
                sessionStorage.setItem('pos_paymongo_checkout_token', posPayMongoCheckoutAttemptToken);
                return posPayMongoCheckoutAttemptToken;
            }
            if (posPayMongoCheckoutAttemptToken) {
                return posPayMongoCheckoutAttemptToken;
            }
            const stored = sessionStorage.getItem('pos_paymongo_checkout_token');
            if (stored) {
                posPayMongoCheckoutAttemptToken = stored;
                return stored;
            }
            const bytes = new Uint8Array(24);
            crypto.getRandomValues(bytes);
            posPayMongoCheckoutAttemptToken = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
            sessionStorage.setItem('pos_paymongo_checkout_token', posPayMongoCheckoutAttemptToken);
            return posPayMongoCheckoutAttemptToken;
        }

        function resetPayMongoPosCheckoutState(clearCheckoutToken = true) {
            if (paymongoPollTimer) window.clearInterval(paymongoPollTimer);
            if (paymongoCountdownTimer) window.clearInterval(paymongoCountdownTimer);
            paymongoPollTimer = null;
            paymongoCountdownTimer = null;
            pendingPayMongoPayment = null;
            pendingPayMongoReceipt = null;
            pendingPayMongoPrintJob = null;
            pendingPayMongoOrderId = 0;
            posPayMongoCheckoutPending = false;
            sessionStorage.removeItem('pos_paymongo_pending');
            if (clearCheckoutToken) {
                posPayMongoCheckoutAttemptToken = null;
                sessionStorage.removeItem('pos_paymongo_checkout_token');
            }
        }

        function closePayMongoPosModal() {
            document.getElementById('paymongo-pos-modal').style.display = 'none';
            document.getElementById('paymongo-pos-reference').textContent = '';
            resetPayMongoPosCheckoutState();
        }

        function renderPayMongoPosPayment(payment) {
            pendingPayMongoPayment = payment || null;
            const isQr = payment?.payment_flow === 'payment_intent' && payment?.payment_method === 'qrph';
            const qrImage = document.getElementById('paymongo-pos-qr');
            qrImage.style.display = isQr && payment?.qr_image_url ? 'block' : 'none';
            if (isQr && payment?.qr_image_url) qrImage.src = payment.qr_image_url;
            document.getElementById('paymongo-pos-title').textContent = 'Dynamic QR Ph';
            document.getElementById('paymongo-pos-order').textContent =
                `Order #${pendingPayMongoOrderId} - ${formatMoney(Number(payment?.amount || 0) / 100)}`;
            const referenceLabel = document.getElementById('paymongo-pos-reference');
            const reference = payment?.reference_number || payment?.payment_reference || '';
            referenceLabel.textContent = reference ? 'Reference Number: ' + reference : '';
            const retryButton = document.getElementById('paymongo-pos-retry');
            const status = String(payment?.status || '').toLowerCase();
            retryButton.style.display = isQr && ['failed', 'expired', 'cancelled'].includes(status) ? 'block' : 'none';
            const statusLabel = document.getElementById('paymongo-pos-status');
            if (status === 'failed') statusLabel.textContent = 'Payment was not completed. Generate a new QR to try again.';
            else if (status === 'expired') statusLabel.textContent = 'QR code expired. Generate a new QR to continue.';
            else if (status === 'paid') statusLabel.textContent = 'Payment confirmed. Complete the transaction to continue.';
            else statusLabel.textContent = 'Waiting for payment confirmation.';

            if (paymongoCountdownTimer) window.clearInterval(paymongoCountdownTimer);
            const countdown = document.getElementById('paymongo-pos-countdown');
            countdown.textContent = '';
            if (isQr && payment?.qr_expires_at_epoch && status === 'awaiting_payment') {
                const renderCountdown = () => {
                    const remaining = Math.max(0, Number(payment.qr_expires_at_epoch) - Math.floor(Date.now() / 1000));
                    const minutes = String(Math.floor(remaining / 60)).padStart(2, '0');
                    const seconds = String(remaining % 60).padStart(2, '0');
                    countdown.textContent = remaining > 0 ? `QR expires in ${minutes}:${seconds}` : 'QR code expired';
                    if (remaining <= 0 && paymongoCountdownTimer) {
                        window.clearInterval(paymongoCountdownTimer);
                        paymongoCountdownTimer = null;
                    }
                };
                renderCountdown();
                paymongoCountdownTimer = window.setInterval(renderCountdown, 1000);
            }
        }

        async function resumePayMongoPosModal(orderId) {
            try {
                const url = staffUrl('staff/api/paymongo_payment.php')
                    + '?subject_type=order&subject_id=' + encodeURIComponent(orderId)
                    + '&channel=pos&_=' + Date.now();
                const response = await fetch(url, {cache: 'no-store'});
                const data = await response.json();
                if (response.ok && data.success && data.payment) openPayMongoPosModal(orderId, data.payment);
                else sessionStorage.removeItem('pos_paymongo_pending');
            } catch (error) {
                // Keep the saved order reference so a page refresh can retry safely.
            }
        }

        async function retryPayMongoPosQr() {
            if (pendingPayMongoOrderId <= 0) return;
            const retryButton = document.getElementById('paymongo-pos-retry');
            retryButton.disabled = true;
            document.getElementById('paymongo-pos-status').textContent = 'Generating a new QR Ph code...';
            try {
                const response = await fetch(staffUrl('staff/api/paymongo_payment.php'), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action: 'create_qrph',
                        subject_type: 'order',
                        subject_id: pendingPayMongoOrderId,
                        channel: 'pos',
                        csrf_token: POS_CSRF_TOKEN
                    })
                });
                const data = await response.json();
                if (!response.ok || !data.success || !data.payment) throw new Error(data.message || 'A new QR could not be generated.');
                openPayMongoPosModal(pendingPayMongoOrderId, data.payment);
            } catch (error) {
                document.getElementById('paymongo-pos-status').textContent = error.message || 'A new QR could not be generated.';
            } finally {
                retryButton.disabled = false;
            }
        }

        function openPayMongoPosModal(orderId, payment) {
            const modal = document.getElementById('paymongo-pos-modal');
            const completeButton = document.getElementById('paymongo-pos-complete');
            completeButton.disabled = true;
            completeButton.style.background = '#94a3b8';
            completeButton.style.cursor = 'not-allowed';
            pendingPayMongoReceipt = null;
            pendingPayMongoPrintJob = null;
            pendingPayMongoOrderId = Number(orderId);
            sessionStorage.setItem('pos_paymongo_pending', JSON.stringify({order_id: pendingPayMongoOrderId}));
            renderPayMongoPosPayment(payment);
            modal.style.display = 'flex';
            if (paymongoPollTimer) window.clearInterval(paymongoPollTimer);
            const finishPaidPosTransaction = async (data) => {
                if (paymongoPollTimer) window.clearInterval(paymongoPollTimer);
                paymongoPollTimer = null;
                if (data?.receipt_available && data?.receipt) {
                    pendingPayMongoReceipt = data.receipt;
                    pendingPayMongoPrintJob = data.print_job || null;
                    closePayMongoPosModal();
                    openReceiptModal(data.receipt);
                    pendingPayMongoOrderId = 0;
                    return true;
                }
                if (data?.can_complete) {
                    document.getElementById('paymongo-pos-status').textContent = 'Payment confirmed. Complete the transaction to issue the receipt.';
                    completeButton.disabled = false;
                    completeButton.style.background = '#059669';
                    completeButton.style.cursor = 'pointer';
                }
                return false;
            };
            const poll = async () => {
                try {
                    const url = staffUrl('staff/api/paymongo_payment.php')
                        + '?subject_type=order&subject_id=' + encodeURIComponent(orderId)
                        + '&channel=pos&_=' + Date.now();
                    const response = await fetch(url, {cache: 'no-store'});
                    const data = await response.json();
                    if (data.success && data.payment) {
                        renderPayMongoPosPayment(data.payment);
                        if (['failed', 'expired', 'cancelled'].includes(String(data.payment.status || '').toLowerCase())) {
                            if (paymongoPollTimer) window.clearInterval(paymongoPollTimer);
                            paymongoPollTimer = null;
                            return;
                        }
                    }
                    if (data.success && data.payment && data.payment.status === 'paid') {
                        const completed = await finishPaidPosTransaction(data);
                        if (completed) {
                            return;
                        }
                    }
                } catch (error) {
                    // Keep polling; transient network failures do not change payment state.
                }
            };
            poll();
            paymongoPollTimer = window.setInterval(poll, 4000);
        }

        async function completePayMongoPosTransaction() {
            if (pendingPayMongoReceipt) {
                sessionStorage.removeItem('pos_paymongo_pending');
                sessionStorage.removeItem('pos_paymongo_checkout_token');
                closePayMongoPosModal();
                openReceiptModal(pendingPayMongoReceipt);
                pendingPayMongoOrderId = 0;
                return;
            }
            if (pendingPayMongoOrderId <= 0) return;
            const completeButton = document.getElementById('paymongo-pos-complete');
            completeButton.disabled = true;
            document.getElementById('paymongo-pos-status').textContent = 'Completing transaction…';
            try {
                const response = await fetch(staffUrl('staff/api/paymongo_payment.php'), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action: 'complete_pos',
                        subject_type: 'order',
                        subject_id: pendingPayMongoOrderId,
                        channel: 'pos',
                        csrf_token: POS_CSRF_TOKEN
                    })
                });
                const data = await response.json();
                if (!response.ok || !data.success || !data.receipt) {
                    throw new Error(data.message || 'The transaction could not be completed.');
                }
                if (paymongoPollTimer) window.clearInterval(paymongoPollTimer);
                paymongoPollTimer = null;
                pendingPayMongoReceipt = data.receipt;
                pendingPayMongoPrintJob = data.print_job || null;
                sessionStorage.removeItem('pos_paymongo_pending');
                sessionStorage.removeItem('pos_paymongo_checkout_token');
                closePayMongoPosModal();
                openReceiptModal(data.receipt);
                pendingPayMongoOrderId = 0;
            } catch (error) {
                completeButton.disabled = false;
                document.getElementById('paymongo-pos-status').textContent = error.message;
            }
        }

        function openNewCustomerModal() {
            document.getElementById('customer-modal').style.display = 'flex';
        }
        function closeCustomerModal() {
            document.getElementById('customer-modal').style.display = 'none';
        }
        async function saveCustomer() {
            const first = document.getElementById('nc-first').value.trim();
            const last = document.getElementById('nc-last').value.trim();
            const email = document.getElementById('nc-email').value.trim();
            const phone = document.getElementById('nc-phone').value.trim();

            // Validation
            if (!first) {
                await showPOSAlert('Missing Info', 'First name is required.', 'warning');
                document.getElementById('nc-first').focus();
                return;
            }
            if (!last) {
                await showPOSAlert('Missing Info', 'Last name is required.', 'warning');
                document.getElementById('nc-last').focus();
                return;
            }
            if (!email) {
                await showPOSAlert('Missing Info', 'Email address is required.', 'warning');
                document.getElementById('nc-email').focus();
                return;
            }

            // Email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                await showPOSAlert('Invalid Email', 'Please enter a valid email address.', 'warning');
                document.getElementById('nc-email').focus();
                return;
            }

            const btn = document.getElementById('nc-save-btn');
            btn.textContent = 'Creating customer...';
            btn.disabled = true;

            try {
                const res = await fetch(staffUrl('staff/api/pos_add_customer.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        first_name: first,
                        last_name: last,
                        email: email,
                        contact_number: phone
                    })
                });
                const data = await res.json();
                if (data.success) {
                    const sel = $('#pos-customer');
                    const displayText = `${first} ${last} - ${email}`;
                    const opt = $('<option></option>').attr('value', data.customer_id).text(displayText);
                    sel.append(opt);
                    sel.val(data.customer_id).trigger('change');
                    closeCustomerModal();

                    // Clear form
                    document.getElementById('nc-first').value = '';
                    document.getElementById('nc-last').value = '';
                    document.getElementById('nc-email').value = '';
                    document.getElementById('nc-phone').value = '';

                    // Show success message
                    await showPOSAlert('Customer Created', `Customer created successfully!\n\nA password setup email has been sent to ${email}.\nThe customer can use this email to create their account password.`, 'success');
                } else {
                    await showPOSAlert('Error', 'Failed: ' + (data.message || 'Unknown error'), 'error');
                }
            } catch (e) {
                console.error('Error:', e);
                await showPOSAlert('Network Error', 'Network error. Please try again.', 'error');
            } finally {
                btn.textContent = 'Create Customer & Send Email';
                btn.disabled = false;
            }
        }

        // Expose key handlers globally for reliability (Turbo/SPA compatibility)
        window.confirmCustomization = confirmCustomization;
        window.closeCustomModal = closeCustomModal;
        window.confirmPrice = confirmPrice;
        window.closePriceModal = closePriceModal;
        window.processCheckout = processCheckout;
        window.addQuickService = addQuickService;
        window.addToCart = addToCart;
        window.closeVariantModal = closeVariantModal;
        window.confirmVariantSelection = confirmVariantSelection;
        window.togglePosOtherInput = togglePosOtherInput;
        window.updateQtyByCartIndex = updateQtyByCartIndex;
        window.removeByCartIndex = removeByCartIndex;
        window.clearCart = clearCart;

        async function redirectToSetPrice(index) {
            const item = cart[index];
            if (!item) return;

            // Validate customer is selected
            const customer = $('#pos-customer').val();
            if (!customer) {
                await showPOSAlert('Customer Required', 'Please select a customer first.', 'warning');
                return;
            }

            // Store cart state in session storage
            sessionStorage.setItem('pos_cart_state', JSON.stringify({
                cart: cart,
                customer: customer,
                item_index: index
            }));

            // Create a temporary customization entry
            const payload = {
                action: 'create_pending_customization',
                customer_id: customer,
                csrf_token: POS_CSRF_TOKEN,
                item: {
                    id: item.product_id,
                    name: item.name,
                    qty: item.qty,
                    customization: item.customization || null,
                    is_service: item.is_service || false
                }
            };

            try {
                const res = await fetch(staffUrl('staff/api/pos_checkout.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success && data.order_id) {
                    await syncedCartAction('update_service_link', {
                        index,
                        pending_order_id: parseInt(data.order_id, 10) || 0,
                        customization_id: parseInt(data.customization_id, 10) || 0
                    }, { silentErrors: true });

                    const hadDesignUpload = !!(
                        item.customization?.design_upload_data
                        || item.customization?.design_upload_path
                        || item.customization?.design_upload
                    );
                    if (hadDesignUpload && !data.design_saved) {
                        await showPOSAlert(
                            'Upload Warning',
                            'Your design file may not have saved correctly. If the preview is wrong in Customizations, re-add the item with the image and try Set Price again.',
                            'warning'
                        );
                    }
                    // Deep-link into the pricing/material flow using a POS-specific context.
                    const redirectUrl = new URL(0, window.location.origin);
                    redirectUrl.searchParams.set('mode', 'pos_pricing');
                    redirectUrl.searchParams.set('status', 'APPROVED');
                    redirectUrl.searchParams.set('source_order_id', data.order_id);
                    redirectUrl.searchParams.set('return_to_pos', '1');
                    window.location.href = redirectUrl.toString();
                } else {
                    await showPOSAlert('Error', 'Failed to create customization: ' + (data.message || 'Unknown error'), 'error');
                }
            } catch (e) {
                console.error('Error:', e);
                await showPOSAlert('Network Error', 'Network error. Please try again.', 'error');
            }
        }


        let posAlertResolve = null;

        function applyPOSModalIcon(type = 'info') {
            const iconCont = document.getElementById('pos-alert-icon-container');
            const icon = document.getElementById('pos-alert-icon');
            if (!iconCont || !icon) return;

            const presets = {
                error: { bg: '#fee2e2', color: '#ef4444', icon: 'fa-circle-exclamation' },
                warning: { bg: '#fef3c7', color: '#d97706', icon: 'fa-triangle-exclamation' },
                success: { bg: '#dcfce7', color: '#10b981', icon: 'fa-circle-check' },
                info: { bg: '#e0f2fe', color: '#0ea5e9', icon: 'fa-circle-info' },
                confirm: { bg: '#edf4fc', color: '#2f6fae', icon: 'fa-circle-check' },
                danger: { bg: '#fee2e2', color: '#ef4444', icon: 'fa-trash-can' },
            };
            const preset = presets[type] || presets.info;
            iconCont.style.background = preset.bg;
            icon.style.color = preset.color;
            icon.className = 'fas ' + preset.icon;
        }

        async function showPOSAlert(title, message, type = 'info') {
            return new Promise(resolve => {
                const overlay = document.getElementById('pos-alert-overlay');
                const box = document.getElementById('pos-alert-box');
                const titleEl = document.getElementById('pos-alert-title');
                const msgEl = document.getElementById('pos-alert-message');
                const cancelBtn = document.getElementById('pos-alert-cancel');
                const confirmBtn = document.getElementById('pos-alert-confirm');

                titleEl.textContent = title;
                msgEl.innerHTML = (message || "").replace(/\n/g, '<br>');
                cancelBtn.style.display = 'none';
                cancelBtn.disabled = false;
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'OK';
                confirmBtn.style.background = 'var(--staff-pos-button-bg)';
                applyPOSModalIcon(type);

                overlay.style.display = 'flex';
                setTimeout(() => {
                    overlay.style.opacity = '1';
                    box.style.transform = 'translateY(0)';
                }, 10);

                confirmBtn.onclick = () => {
                    closePOSAlert();
                    resolve(true);
                };
            });
        }

        async function showPOSConfirm(title, message, confirmLabel = 'Confirm', variant = 'confirm') {
            return new Promise(resolve => {
                const overlay = document.getElementById('pos-alert-overlay');
                const box = document.getElementById('pos-alert-box');
                const titleEl = document.getElementById('pos-alert-title');
                const msgEl = document.getElementById('pos-alert-message');
                const cancelBtn = document.getElementById('pos-alert-cancel');
                const confirmBtn = document.getElementById('pos-alert-confirm');

                titleEl.textContent = title;
                msgEl.innerHTML = (message || "").replace(/\n/g, '<br>');
                cancelBtn.style.display = 'block';
                cancelBtn.disabled = false;
                confirmBtn.disabled = false;
                confirmBtn.textContent = confirmLabel;
                confirmBtn.style.background = variant === 'danger'
                    ? '#ef4444'
                    : 'var(--staff-pos-button-bg)';
                applyPOSModalIcon(variant === 'danger' ? 'danger' : 'confirm');

                overlay.style.display = 'flex';
                setTimeout(() => {
                    overlay.style.opacity = '1';
                    box.style.transform = 'translateY(0)';
                }, 10);

                cancelBtn.onclick = () => {
                    cancelBtn.disabled = true;
                    confirmBtn.disabled = true;
                    closePOSAlert();
                    resolve(false);
                };
                confirmBtn.onclick = () => {
                    confirmBtn.disabled = true;
                    cancelBtn.disabled = true;
                    closePOSAlert();
                    resolve(true);
                };
            });
        }

        function closePOSAlert() {
            const overlay = document.getElementById('pos-alert-overlay');
            const box = document.getElementById('pos-alert-box');
            overlay.style.opacity = '0';
            box.style.transform = 'translateY(20px)';
            setTimeout(() => {
                overlay.style.display = 'none';
            }, 200);
        }

        window.redirectToSetPrice = redirectToSetPrice;
        window.posOpenServiceFromCard = posOpenServiceFromCard;
        window.openServiceModal = openServiceModal;
        window.confirmServiceModal = confirmServiceModal;