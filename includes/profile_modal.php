<?php
/**
 * Customer Profile Modal
 * PrintFlow - Printing Shop PWA
 */
?>
<style>
/* Modal Base Styles */
.prof-modal-backdrop {
    position: fixed; inset: 0; z-index: 9998;
    background: rgba(15, 23, 42, 0.6);
    opacity: 0; visibility: hidden;
    transition: opacity 0.2s ease, visibility 0.2s ease;
}
.prof-modal-backdrop.is-open { opacity: 1; visibility: visible; }

.prof-modal {
    position: fixed; left: 50%; top: 50%; transform: translate(-50%, -50%) scale(0.95);
    z-index: 9999;
    width: 100%; max-width: 42rem; max-height: 90vh;
    background: #fff;
    border-radius: 1rem;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    opacity: 0; visibility: hidden;
    transition: all 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
    display: flex; flex-direction: column;
}
.prof-modal.is-open { opacity: 1; visibility: visible; transform: translate(-50%, -50%) scale(1); }

.prof-modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 1.5rem; border-bottom: 1px solid #f1f5f9;
}
.prof-modal-body {
    padding: 1.5rem; overflow-y: auto; flex: 1;
}

/* Tabs */
.prof-tabs {
    display: flex; gap: 1rem; border-bottom: 1px solid #e2e8f0; margin-bottom: 1.5rem;
}
.prof-tab-btn {
    padding: 0.75rem 0; font-weight: 600; font-size: 0.95rem;
    color: #64748b; background: none; border: none; border-bottom: 2px solid transparent;
    cursor: pointer; transition: all 0.2s;
}
.prof-tab-btn:hover { color: #1e293b; }
.prof-tab-btn.active { color: #4f46e5; border-bottom-color: #4f46e5; }
.prof-tab-content { display: none; }
.prof-tab-content.active { display: block; animation: fadeIn 0.3s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

.prof-alert {
    padding: 0.75rem 1rem; border-radius: 0.5rem; margin-bottom: 1.25rem; font-size: 0.875rem; font-weight: 500; display: none;
}
.prof-alert.success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; display: block; }
.prof-alert.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; display: block; }
</style>

<div class="prof-modal-backdrop" id="prof-modal-backdrop" aria-hidden="true"></div>

<div class="prof-modal" id="prof-modal" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="prof-modal-header">
        <div>
            <h2 class="text-xl font-bold text-gray-900" style="margin:0;">My Profile</h2>
            <p class="text-xs text-gray-500" style="margin-top:2px;">Manage your account details and password</p>
        </div>
        <button type="button" class="auth-modal-close" data-prof-close aria-label="Close">&times;</button>
    </div>
    
    <div class="prof-modal-body">
        <div id="prof-master-alert" class="prof-alert"></div>
        
        <div class="prof-tabs">
            <button class="prof-tab-btn active" data-tab="info">General Info</button>
            <button class="prof-tab-btn" data-tab="security">Security</button>
            <button class="prof-tab-btn" data-tab="account">Account</button>
        </div>

        <!-- Info Tab -->
        <div id="tab-info" class="prof-tab-content active">
            <form id="prof-info-form">
                <input type="hidden" name="action" value="update_profile">
                <?php echo csrf_field(); ?>
                <!-- Data populated via JS -->
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">First Name *</label>
                        <input type="text" id="prof_first_name" name="first_name" class="input-field" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Last Name *</label>
                        <input type="text" id="prof_last_name" name="last_name" class="input-field" required>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" id="prof_email" class="input-field bg-gray-50" disabled>
                    <p class="text-xs text-gray-400 mt-1">Email cannot be changed.</p>
                </div>
                
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Contact Number</label>
                        <input type="tel" id="prof_contact" name="contact_number" class="input-field">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Gender</label>
                        <select id="prof_gender" name="gender" class="input-field">
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end mt-6">
                    <button type="button" class="text-gray-500 hover:bg-gray-100 px-4 py-2 rounded-lg font-medium transition-colors mr-2" data-prof-close>Cancel</button>
                    <button type="submit" class="btn-primary" style="padding:0.5rem 1.5rem;">Save Changes</button>
                </div>
            </form>
        </div>

        <!-- Security Tab -->
        <div id="tab-security" class="prof-tab-content">
            <form id="prof-pass-form">
                <input type="hidden" name="action" value="change_password">
                <?php echo csrf_field(); ?>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Current Password *</label>
                    <input type="password" name="current_password" class="input-field" required>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">New Password *</label>
                    <input type="password" name="new_password" class="input-field" required minlength="8">
                    <p class="text-xs text-gray-500 mt-1">Must be at least 8 characters long.</p>
                </div>
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password *</label>
                    <input type="password" name="confirm_password" class="input-field" required minlength="8">
                </div>
                <div class="flex justify-end mt-4">
                    <button type="submit" class="btn-primary" style="padding:0.5rem 1.5rem;">Update Password</button>
                </div>
            </form>
        </div>

        <!-- Account Tab -->
        <div id="tab-account" class="prof-tab-content">
            <div class="bg-indigo-50 rounded-xl p-5 border border-indigo-100">
                <h3 class="font-bold text-indigo-900 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Account Information
                </h3>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between border-b border-indigo-100 pb-2">
                        <span class="text-indigo-700">Customer ID</span>
                        <span class="font-bold text-indigo-900" id="prof_id_disp">...</span>
                    </div>
                    <div class="flex justify-between border-b border-indigo-100 pb-2">
                        <span class="text-indigo-700">Status</span>
                        <span class="font-bold text-green-600">Active</span>
                    </div>
                    <div class="flex justify-between pb-1">
                        <span class="text-indigo-700">Member Since</span>
                        <span class="font-bold text-indigo-900" id="prof_created_disp">...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const backdrop = document.getElementById('prof-modal-backdrop');
    const modal = document.getElementById('prof-modal');
    const alertBox = document.getElementById('prof-master-alert');
    let isOpen = false;

    // Tabs logic
    const tabBtns = document.querySelectorAll('.prof-tab-btn');
    const tabContents = document.querySelectorAll('.prof-tab-content');
    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            tabBtns.forEach(b => b.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
            alertBox.className = 'prof-alert'; // hide alert on tab switch
        });
    });

    // Open/Close
    window.openProfileModal = function() {
        backdrop.classList.add('is-open');
        modal.classList.add('is-open');
        isOpen = true;
        document.body.style.overflow = 'hidden';
        fetchProfileData();
    };

    window.closeProfileModal = function() {
        backdrop.classList.remove('is-open');
        modal.classList.remove('is-open');
        isOpen = false;
        document.body.style.overflow = '';
        alertBox.className = 'prof-alert';
        document.getElementById('prof-pass-form').reset();
    };

    document.querySelectorAll('[data-prof-close]').forEach(btn => {
        btn.addEventListener('click', closeProfileModal);
    });
    backdrop.addEventListener('click', closeProfileModal);
    document.addEventListener('keydown', e => {
        if(e.key === 'Escape' && isOpen) closeProfileModal();
    });
    
    // Bind to the nav dropdown button
    document.addEventListener('click', e => {
        const trigger = e.target.closest('[data-modal="profile"]');
        if (trigger) {
            e.preventDefault();
            openProfileModal();
        }
    });

    // Fetch data
    function fetchProfileData() {
        fetch('<?php echo $base_path; ?>//printflow/customer/api_profile.php?action=get')
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    const c = data.customer;
                    document.getElementById('prof_first_name').value = c.first_name || '';
                    document.getElementById('prof_last_name').value = c.last_name || '';
                    document.getElementById('prof_email').value = c.email || '';
                    document.getElementById('prof_contact').value = c.contact_number || '';
                    document.getElementById('prof_gender').value = c.gender || '';
                    document.getElementById('prof_id_disp').textContent = '#' + c.customer_id;
                    const d = new Date(c.created_at);
                    document.getElementById('prof_created_disp').textContent = d.toLocaleDateString();
                }
            });
    }

    // Handle Forms
    document.getElementById('prof-info-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const res = await fetch('<?php echo $base_path; ?>//printflow/customer/api_profile.php', { method: 'POST', body: fd });
        const data = await res.json();
        if(data.success) showAlert('success', 'Profile updated successfully!');
        else showAlert('error', data.error || 'Failed to update');
        
        // Update nav name if changed
        if(data.success) {
            const nameEl = document.querySelector('.nav-profile-name');
            if(nameEl) nameEl.textContent = fd.get('first_name');
            const letter = document.querySelector('.nav-profile-btn div');
            if(letter) letter.textContent = fd.get('first_name').charAt(0).toUpperCase();
        }
    });

    document.getElementById('prof-pass-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        if(fd.get('new_password') !== fd.get('confirm_password')) {
            showAlert('error', 'New passwords do not match');
            return;
        }
        const res = await fetch('<?php echo $base_path; ?>//printflow/customer/api_profile.php', { method: 'POST', body: fd });
        const data = await res.json();
        if(data.success) {
            showAlert('success', 'Password changed successfully!');
            e.target.reset();
        } else {
            showAlert('error', data.error || 'Failed to change password');
        }
    });

    function showAlert(type, msg) {
        alertBox.className = 'prof-alert ' + type;
        alertBox.textContent = msg;
    }
});
</script>
