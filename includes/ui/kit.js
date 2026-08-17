/**
 * GestioneDb - UI Kit Premium JS Library
 * Integrates reusable UI components (Modals, Toasts, Input Validation, Table helper)
 */
(function (window, document) {
    'use strict';

    const UIKit = {
        /**
         * Display a premium toast notification
         * @param {string} message 
         * @param {'info'|'success'|'error'|'danger'} type 
         * @param {number} duration 
         */
        toast: function (message, type = 'info', duration = 3500) {
            let container = document.querySelector('.toast-container');
            if (!container) {
                container = document.createElement('div');
                container.className = 'toast-container';
                document.body.appendChild(container);
            }

            const toast = document.createElement('div');
            toast.className = `toast ${type === 'danger' ? 'error' : type}`;
            
            const iconMap = {
                success: '✓',
                error: '✕',
                danger: '✕',
                info: 'ℹ'
            };
            const icon = iconMap[type] || 'ℹ';

            toast.innerHTML = `
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-weight:bold;font-size:1.1em;">${icon}</span>
                    <span>${message}</span>
                </div>
                <button aria-label="Close notification" style="background:none;border:none;color:inherit;cursor:pointer;opacity:0.7;font-size:1.1em;line-height:1;">✕</button>
            `;

            const closeBtn = toast.querySelector('button');
            closeBtn.addEventListener('click', () => {
                toast.style.animation = 'toastOut 0.25s cubic-bezier(0.4, 0, 0.2, 1) forwards';
                setTimeout(() => toast.remove(), 250);
            });

            container.appendChild(toast);

            setTimeout(() => {
                if (toast.parentNode) {
                    toast.style.animation = 'toastOut 0.25s cubic-bezier(0.4, 0, 0.2, 1) forwards';
                    setTimeout(() => toast.remove(), 250);
                }
            }, duration);
        },

        /**
         * Open a modal dialog by ID
         * @param {string} modalId 
         */
        openModal: function (modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'flex';
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }
        },

        /**
         * Close a modal dialog by ID
         * @param {string} modalId 
         */
        closeModal: function (modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }
        },

        /**
         * Attach form validation feedback to .form-input elements
         */
        initValidation: function () {
            document.querySelectorAll('form').forEach(form => {
                form.querySelectorAll('.form-input[required]').forEach(input => {
                    input.addEventListener('blur', function () {
                        if (!this.checkValidity()) {
                            this.classList.add('is-invalid');
                        } else {
                            this.classList.remove('is-invalid');
                        }
                    });
                });
            });
        },

        /**
         * Auto-initialize interactive kit listeners
         */
        init: function () {
            // Modal trigger handlers [data-modal-target]
            document.addEventListener('click', function (e) {
                const trigger = e.target.closest('[data-modal-target]');
                if (trigger) {
                    const targetId = trigger.getAttribute('data-modal-target');
                    UIKit.openModal(targetId);
                }

                const dismiss = e.target.closest('[data-modal-close]');
                if (dismiss) {
                    const modal = dismiss.closest('.modal');
                    if (modal && modal.id) {
                        UIKit.closeModal(modal.id);
                    }
                }
            });

            // Close modal when clicking backdrop
            document.addEventListener('click', function (e) {
                if (e.target.classList.contains('modal')) {
                    UIKit.closeModal(e.target.id);
                }
            });

            this.initValidation();
        }
    };

    window.UIKit = UIKit;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => UIKit.init());
    } else {
        UIKit.init();
    }
})(window, document);
