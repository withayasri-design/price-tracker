/**
 * Price Tracker - Main JavaScript
 * Vanilla JS utilities for the application
 */

const PriceTracker = {
    /**
     * Get CSRF token from meta tag
     */
    getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    },

    /**
     * Make API request with CSRF token
     */
    async api(endpoint, options = {}) {
        const defaults = {
            headers: {
                'Content-Type': 'application/json',
            },
        };

        if (options.method && options.method !== 'GET') {
            if (options.body && typeof options.body === 'object') {
                options.body.csrf_token = this.getCsrfToken();
                options.body = JSON.stringify(options.body);
            }
        }

        const config = { ...defaults, ...options };

        try {
            const response = await fetch(endpoint, config);
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || 'Request failed');
            }

            return data;
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    },

    /**
     * Format price in Thai Baht
     */
    formatPrice(price, decimals = 2) {
        return new Intl.NumberFormat('th-TH', {
            style: 'currency',
            currency: 'THB',
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }).format(price);
    },

    /**
     * Format date in Thai locale
     */
    formatDate(dateStr, options = {}) {
        const date = new Date(dateStr);
        const defaults = {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        };
        return date.toLocaleDateString('th-TH', { ...defaults, ...options });
    },

    /**
     * Format relative time
     */
    formatRelativeTime(dateStr) {
        const date = new Date(dateStr);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 1) return 'เมื่อสักครู่';
        if (diffMins < 60) return `${diffMins} นาทีที่แล้ว`;
        if (diffHours < 24) return `${diffHours} ชั่วโมงที่แล้ว`;
        if (diffDays < 7) return `${diffDays} วันที่แล้ว`;

        return this.formatDate(dateStr, { hour: undefined, minute: undefined });
    },

    /**
     * Show toast notification
     */
    toast(message, type = 'info') {
        const container = document.querySelector('.toast-container') || this.createToastContainer();

        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-white bg-${type} border-0 show`;
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;

        container.appendChild(toast);

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 5000);
    },

    createToastContainer() {
        const container = document.createElement('div');
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        document.body.appendChild(container);
        return container;
    },

    /**
     * Show loading overlay
     */
    showLoading() {
        let overlay = document.querySelector('.spinner-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'spinner-overlay';
            overlay.innerHTML = `
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            `;
            document.body.appendChild(overlay);
        }
        overlay.style.display = 'flex';
    },

    hideLoading() {
        const overlay = document.querySelector('.spinner-overlay');
        if (overlay) {
            overlay.style.display = 'none';
        }
    },

    /**
     * Confirm dialog
     */
    async confirm(message, title = 'ยืนยัน') {
        return new Promise((resolve) => {
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">${title}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">${message}</div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                            <button type="button" class="btn btn-primary btn-confirm">ยืนยัน</button>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);
            const bsModal = new bootstrap.Modal(modal);

            modal.querySelector('.btn-confirm').addEventListener('click', () => {
                bsModal.hide();
                resolve(true);
            });

            modal.addEventListener('hidden.bs.modal', () => {
                modal.remove();
                resolve(false);
            });

            bsModal.show();
        });
    },

    /**
     * Debounce function
     */
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },

    /**
     * Copy to clipboard
     */
    async copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            this.toast('คัดลอกแล้ว', 'success');
            return true;
        } catch (err) {
            this.toast('ไม่สามารถคัดลอกได้', 'danger');
            return false;
        }
    },
};

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    // Auto-dismiss alerts after 5 seconds
    document.querySelectorAll('.alert-dismissible').forEach(alert => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            bsAlert.close();
        }, 5000);
    });

    // Handle image load errors
    document.querySelectorAll('img[data-fallback]').forEach(img => {
        img.addEventListener('error', function() {
            this.src = this.dataset.fallback || '/assets/img/no-image.svg';
        });
    });
});

// Export for use
window.PriceTracker = PriceTracker;
