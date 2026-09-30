<?php
/**
 * Customer: New Customization (Refined Version)
 * Modern, single-page ordering interface with real-time validation.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ServiceAvailabilityChecker.php';

require_role(['Customer', 'Staff', 'Admin', 'Manager']);
$page_title = 'New Customization - PrintFlow';
$availableServices = ServiceAvailabilityChecker::getAvailableServices();
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php'; 
?>

<div class="py-8" x-data="orderSystem()" x-init="initCustomer()">
    <div class="container mx-auto px-4" style="max-width:1200px;">
        
        <div class="flex flex-col lg:flex-row gap-8">
            
            <!-- Left Column: Form Sections -->
            <div class="flex-1 space-y-8">
                <header class="mb-4">
                    <h1 class="ct-page-title mb-1">Place Print Order</h1>
                    <p class="text-gray-500 text-sm">Configure your job specifications and upload your design.</p>
                </header>

                <!-- 1. Customer Context (Hidden if customer, visible if staff) -->
                <?php if ($_SESSION['user_type'] !== 'Customer'): ?>
                <div class="ct-card border-l-4 border-indigo-500">
                    <h2 class="text-sm font-black text-gray-400 uppercase tracking-widest mb-4">Customer Context</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-[11px] font-bold text-gray-500 uppercase">Search Existing Customer</label>
                            <input type="text" placeholder="Phone or Name..." class="input-field w-full" x-model="customerSearch" @input.debounce="searchCustomer">
                        </div>
                        <template x-if="customerProfile">
                            <div class="flex items-center gap-3 p-3 bg-indigo-50 rounded-xl border border-indigo-100">
                                <div class="w-10 h-10 flex items-center justify-center bg-indigo-600 text-white rounded-full font-bold" x-text="customerProfile.first_name[0]"></div>
                                <div>
                                    <div class="text-sm font-bold" x-text="customerProfile.first_name + ' ' + customerProfile.last_name"></div>
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <span class="text-[10px] px-1.5 py-0.5 rounded font-black text-white" :class="customerProfile.customer_type === 'NEW' ? 'bg-emerald-500' : 'bg-indigo-500'" x-text="customerProfile.customer_type"></span>
                                        <span class="text-[10px] text-gray-500 font-bold" x-text="customerProfile.transaction_count + ' transaction(s)'"></span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
                <?php else: ?>
                    <!-- Customer Badge for Logged In Customer -->
                    <div class="flex items-center gap-4 mb-2">
                         <template x-if="customerProfile">
                            <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-white border rounded-full shadow-sm">
                                <span class="text-[10px] font-black uppercase tracking-wider text-gray-400">Account Type:</span>
                                <span class="text-[10px] px-2 py-0.5 rounded-full font-black text-white" :class="customerProfile.customer_type === 'NEW' ? 'bg-emerald-500' : 'bg-indigo-500'" x-text="customerProfile.customer_type"></span>
                            </div>
                         </template>
                    </div>
                <?php endif; ?>

                <!-- Branch Selection (Visible only for Customers) -->
                <?php if ($_SESSION['user_type'] === 'Customer'): ?>
                <div class="ct-card border-l-4 border-amber-500">
                    <h2 class="text-sm font-black text-gray-400 uppercase tracking-widest mb-4">Select Branch</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <?php 
                        $branches = db_query("SELECT id, branch_name, address FROM branches WHERE status = 'Active'");
                        foreach($branches as $b): ?>
                            <label class="relative flex flex-col p-4 border-2 rounded-2xl cursor-pointer transition-all hover:bg-amber-50"
                                   :class="form.branch_id == <?php echo $b['id']; ?> ? 'border-amber-500 bg-amber-50' : 'border-gray-100'">
                                <input type="radio" name="branch" class="hidden" @change="form.branch_id = <?php echo $b['id']; ?>" :checked="form.branch_id == <?php echo $b['id']; ?>">
                                <span class="text-sm font-bold text-gray-800"><?php echo htmlspecialchars($b['branch_name']); ?></span>
                                <span class="text-[10px] text-gray-500 mt-1"><?php echo htmlspecialchars($b['address']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 2. Service Selection -->
                <div class="ct-card">
                    <h2 class="text-sm font-black text-gray-400 uppercase tracking-widest mb-6">1. Choose Service</h2>
                    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3">
                        <template x-for="svc in availableServices" :key="svc">
                            <div @click="selectService(svc)" 
                                 :class="form.service_type === svc ? 'border-indigo-600 bg-indigo-50 shadow-md transform -translate-y-1' : 'border-gray-100 bg-gray-50/50 hover:border-indigo-200'"
                                 class="p-4 border-2 rounded-2xl cursor-pointer transition-all text-center flex flex-col items-center justify-center gap-2">
                                <div class="text-lg" x-text="getServiceIcon(svc)"></div>
                                <div class="text-[11px] font-black leading-tight uppercase" x-text="svc"></div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- 3. Customization Specifications -->
                <div class="ct-card" x-show="form.service_type">
                    <h2 class="text-sm font-black text-gray-400 uppercase tracking-widest mb-6" x-text="'2. Configure ' + form.service_type"></h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div class="space-y-6">
                            <div>
                                <label class="block text-xs font-bold text-gray-400 uppercase mb-2">Internal Title (Optional)</label>
                                <input type="text" x-model="form.job_title" placeholder="Project Name, Client Ref..." class="input-field w-full">
                            </div>

                            <!-- Dynamic Field Group: Roll Based -->
                            <template x-if="isRollBased">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-gray-400 uppercase mb-2">Width (ft)</label>
                                        <input type="number" step="0.1" x-model.number="form.width" @input="calculateTotals" class="input-field w-full">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-400 uppercase mb-2">Height (ft)</label>
                                        <input type="number" step="0.1" x-model.number="form.height" @input="calculateTotals" class="input-field w-full">
                                    </div>
                                </div>
                            </template>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-gray-400 uppercase mb-2">Quantity</label>
                                    <input type="number" min="1" x-model.number="form.quantity" @input="calculateTotals" class="input-field w-full">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-400 uppercase mb-2">Preferred Due Date</label>
                                    <input type="date" x-model="form.due_date" class="input-field w-full" :min="today">
                                </div>
                            </div>
                        </div>

                        <div class="space-y-6">
                             <div>
                                <label class="block text-xs font-bold text-gray-400 uppercase mb-2">Priority</label>
                                <select x-model="form.priority" class="input-field w-full">
                                    <option value="NORMAL">Normal Processing</option>
                                    <option value="HIGH">RUSH (Priority Queue)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-400 uppercase mb-2">Special Instructions / Notes</label>
                                <textarea x-model="form.notes" rows="4" placeholder="Any specific finishing, color matching, or cutting instructions..." class="input-field w-full"></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 4. Artwork Upload -->
                <div class="ct-card" x-show="form.service_type">
                    <h2 class="text-sm font-black text-gray-400 uppercase tracking-widest mb-6">3. Artwork Files</h2>
                    
                    <div @dragover.prevent="dragOver = true" 
                         @dragleave.prevent="dragOver = false"
                         @drop.prevent="handleDrop($event)"
                         :class="dragOver ? 'border-indigo-600 bg-indigo-50' : 'border-gray-200 bg-gray-50/30'"
                         class="border-2 border-dashed rounded-3xl p-12 text-center transition-all relative">
                        
                        <input type="file" multiple id="fileUpload" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" @change="handleFileSelect($event)">
                        
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-16 h-16 flex items-center justify-center bg-white rounded-2xl shadow-sm text-indigo-600 text-2xl">
                                <svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-800">Click or Drag Artwork Here</h3>
                                <p class="text-gray-400 text-xs mt-1">Accepts high-res PDF, JPEG, PNG, or TIFF files.</p>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6" x-show="artworks.length > 0">
                        <template x-for="(file, index) in artworks" :key="index">
                            <div class="group relative aspect-square rounded-2xl overflow-hidden border bg-gray-50">
                                <template x-if="file.preview">
                                    <img :src="file.preview" class="w-full h-full object-cover">
                                </template>
                                <template x-if="!file.preview">
                                    <div class="w-full h-full flex items-center justify-center text-gray-400 text-2xl font-bold uppercase" x-text="file.ext"></div>
                                </template>

                                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                    <button @click="removeFile(index)" class="w-10 h-10 bg-red-500 text-white rounded-full flex items-center justify-center shadow-lg hover:scale-110 transition">
                                        &times;
                                    </button>
                                </div>
                                <div class="absolute bottom-0 inset-x-0 p-2 bg-white/90 backdrop-blur-sm border-t">
                                    <div class="text-[10px] font-bold truncate text-gray-800" x-text="file.name"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Right Column: Summary Panel -->
            <div class="lg:w-96">
                <div class="sticky top-24 space-y-6">
                    <div class="ct-card border-2 border-indigo-600 shadow-xl shadow-indigo-100">
                        <h2 class="text-sm font-black text-indigo-600 uppercase tracking-widest mb-6">Order Summary</h2>
                        
                        <div class="space-y-4 mb-8">
                            <div class="flex justify-between items-start">
                                <span class="text-xs text-gray-400 font-bold uppercase">Service</span>
                                <span class="text-xs font-black text-right" x-text="form.service_type || '---'"></span>
                            </div>
                            <template x-if="isRollBased && form.width > 0">
                                <div class="flex justify-between">
                                    <span class="text-xs text-gray-400 font-bold uppercase">Specifications</span>
                                    <span class="text-xs font-black text-right" x-text="`${form.width} x ${form.height} ft`"></span>
                                </div>
                            </template>
                            <div class="flex justify-between">
                                <span class="text-xs text-gray-400 font-bold uppercase">Quantity</span>
                                <span class="text-xs font-black text-right" x-text="form.quantity + ' pcs'"></span>
                            </div>
                            <template x-if="isRollBased">
                                <div class="flex justify-between">
                                    <span class="text-xs text-gray-400 font-bold uppercase">Total Area</span>
                                    <span class="text-xs font-black text-right" x-text="totalSqft.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' sqft'"></span>
                                </div>
                            </template>
                            <div class="flex justify-between">
                                <span class="text-xs text-gray-400 font-bold uppercase">Files</span>
                                <span class="text-xs font-black text-right" x-text="artworks.length + ' uploaded'"></span>
                            </div>
                        </div>

                        <div class="pt-6 border-t border-gray-100 space-y-3">
                            <div class="p-4 bg-amber-50 border border-amber-200 rounded-2xl">
                                <div class="flex items-start gap-3">
                                    <div class="text-amber-500 mt-0.5">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    </div>
                                    <div>
                                        <h3 class="text-sm font-bold text-amber-800 mb-1">Price will be confirmed by the shop</h3>
                                        <p class="text-xs text-amber-700 leading-relaxed">
                                            The final price and payment options will be available once the staff reviews and approves your order specifications. You will be notified when it is ready.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button @click="submitOrder" 
                                :disabled="!isFormValid || submitting"
                                class="w-full mt-8 py-5 bg-indigo-600 text-white font-black rounded-2xl hover:bg-indigo-700 transition shadow-lg shadow-indigo-200 disabled:opacity-30 disabled:grayscale disabled:pointer-events-none">
                            <span x-show="!submitting">Submit Customization</span>
                            <span x-show="submitting">Processing...</span>
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    function orderSystem() {
        return {
            customerProfile: null,
            customerSearch: '',
            availableServices: <?php echo json_encode($availableServices ?? []); ?>,
            dragOver: false,
            artworks: [],
            submitting: false,
            today: new Date().toISOString().split('T')[0],

            form: {
                customer_id: null,
                branch_id: <?php echo ($_SESSION['user_type'] !== 'Customer') ? (int)($_SESSION['branch_id'] ?? 1) : 'null'; ?>,
                service_type: '',
                job_title: '',
                width: 0,
                height: 0,
                quantity: 1,
                due_date: '',
                priority: 'NORMAL',
                notes: ''
            },

            // Basic Price Table (Simulation for frontend estimation)
            pricing: {
                'Tarpaulin Printing': 15.00, // per sqft
                'T-shirt Printing': 250.00, // per pc base
                'Decals/Stickers (Print/Cut)': 40.00, // per sqft
                'Transparent Stickers': 50.00 // per sqft
            },

            async initCustomer() {
                // If standard customer, load profile automatically
                try {
                    const res = await (await fetch('customer_order_api.php?action=get_customer_info')).json();
                    if(res.success) {
                        this.customerProfile = res.data;
                        this.form.customer_id = res.data.customer_id;
                    }
                } catch(e) {}
            },

            getServiceIcon(svc) {
                const icons = {
                    'Tarpaulin Printing': '???',
                    'T-shirt Printing': '??',
                    'Decals/Stickers (Print/Cut)': '???',
                    'Transparent Stickers': '?',
                    'Layouts': '??',
                    'Souvenirs': '??'
                };
                return icons[svc] || '??';
            },

            get isRollBased() {
                const rollServices = [
                    'Tarpaulin Printing', 'Decals/Stickers (Print/Cut)', 
                    'Glass Stickers / Wall / Frosted Stickers', 'Transparent Stickers',
                    'Reflectorized (Subdivision Stickers/Signages)', 'Stickers on Sintraboard',
                    'Sintraboard Standees'
                ];
                return rollServices.includes(this.form.service_type);
            },

            get totalSqft() {
                if(!this.isRollBased) return 0;
                return (this.form.width * this.form.height) * this.form.quantity;
            },

            get isFormValid() {
                if(!this.form.branch_id) return false;
                if(!this.form.service_type) return false;
                if(this.isRollBased && (this.form.width <= 0 || this.form.height <= 0)) return false;
                if(this.form.quantity <= 0) return false;
                if(this.artworks.length === 0 && this.form.service_type !== 'Layouts') return false;
                return true;
            },

            selectService(svc) {
                this.form.service_type = svc;
                if(this.isRollBased && this.form.width === 0) {
                    this.form.width = 2;
                    this.form.height = 3;
                }
            },

            handleFileSelect(e) {
                const files = Array.from(e.target.files);
                this.processFiles(files);
            },

            handleDrop(e) {
                this.dragOver = false;
                const files = Array.from(e.dataTransfer.files);
                this.processFiles(files);
            },

            processFiles(files) {
                files.forEach(file => {
                    const reader = new FileReader();
                    const ext = file.name.split('.').pop().toLowerCase();
                    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);

                    reader.onload = (e) => {
                        this.artworks.push({
                            file: file,
                            name: file.name,
                            ext: ext,
                            preview: isImage ? e.target.result : null
                        });
                    };
                    if(isImage) reader.readAsDataURL(file);
                    else reader.readAsText(file); // Dummy read for non-images
                });
            },

            removeFile(index) {
                this.artworks.splice(index, 1);
            },

            formatCurrency(val) {
                return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(val);
            },

            async submitOrder() {
                this.submitting = true;
                const fd = new FormData();
                fd.append('action', 'create_order');
                fd.append('csrf_token', <?php echo json_encode(generate_csrf_token()); ?>);
                fd.append('service_type', this.form.service_type);
                fd.append('branch_id', this.form.branch_id);
                fd.append('job_title', this.form.job_title);
                fd.append('width_ft', this.form.width);
                fd.append('height_ft', this.form.height);
                fd.append('quantity', this.form.quantity);
                fd.append('notes', this.form.notes);
                fd.append('due_date', this.form.due_date);
                fd.append('priority', this.form.priority);
                fd.append('estimated_total', 0);
                
                this.artworks.forEach(art => {
                    fd.append('artworks[]', art.file);
                });

                try {
                    const res = await (await fetch('customer_order_api.php', { method: 'POST', body: fd })).json();
                    if(res.success) {
                        window.location.href = `order_confirmation.php?id=${res.id}`;
                    } else {
                        alert(res.error);
                    }
                } catch(e) { alert('Connection failed.'); }
                finally { this.submitting = false; }
            }
        }
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
