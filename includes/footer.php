        </main><!-- /#main-content -->
    </div><!-- /.main-area -->
</div><!-- /.app-wrapper -->

<!-- ═══════════════════════════════════════════════
     SHARED JAVASCRIPT
═══════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';

    /* ── Sidebar toggle (mobile) ── */
    const sidebar  = document.getElementById('sidebar');
    const overlay  = document.getElementById('sidebar-overlay');
    const toggle   = document.getElementById('sidebar-toggle');

    function openSidebar() {
        sidebar?.classList.add('open');
        overlay?.classList.add('visible');
        toggle?.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        sidebar?.classList.remove('open');
        overlay?.classList.remove('visible');
        toggle?.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    }

    toggle?.addEventListener('click', () => {
        sidebar?.classList.contains('open') ? closeSidebar() : openSidebar();
    });

    overlay?.addEventListener('click', closeSidebar);

    /* ── Auto-dismiss alerts after 5s ── */
    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s, transform 0.5s';
            alert.style.opacity    = '0';
            alert.style.transform  = 'translateX(10px)';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    });

    /* ── Confirm dangerous actions ── */
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-confirm]');
        if (btn) {
            const msg = btn.dataset.confirm || 'Are you sure? This action cannot be undone.';
            if (!confirm(msg)) e.preventDefault();
        }
    });

    /* ── Toast Notifications ── */
    const toastStyles = `
        .toast-container { position: fixed; bottom: 20px; right: 20px; z-index: 1000; display: flex; flex-direction: column; gap: 10px; }
        .toast { background: var(--bg-surface); color: var(--text-primary); padding: 12px 20px; border-radius: 8px; border-left: 4px solid var(--accent-primary); box-shadow: var(--shadow-lg); font-size: 0.85rem; min-width: 250px; animation: toastIn 0.3s ease forwards; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .toast.success { border-left-color: var(--color-success); }
        .toast.error { border-left-color: var(--color-danger); }
        @keyframes toastIn { from { opacity: 0; transform: translateX(50px); } to { opacity: 1; transform: translateX(0); } }
        @keyframes toastOut { to { opacity: 0; transform: scale(0.95); transition: all 0.2s; } }
    `;
    const styleSheet = document.createElement("style");
    styleSheet.innerText = toastStyles;
    document.head.appendChild(styleSheet);

    const toastContainer = document.createElement("div");
    toastContainer.className = "toast-container";
    document.body.appendChild(toastContainer);

    window.showToast = function(message, type = 'info', duration = 3000) {
        const toast = document.createElement("div");
        toast.className = `toast ${type}`;
        toast.innerHTML = `<span>${message}</span><button style="background:none;border:none;color:inherit;cursor:pointer;opacity:0.6">✕</button>`;
        toastContainer.appendChild(toast);
        
        toast.querySelector('button').onclick = () => toast.remove();

        setTimeout(() => {
            toast.style.animation = 'toastOut 0.3s ease forwards';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    };

    /* ── Keyboard shortcuts ── */
    document.addEventListener('keydown', function (e) {
        // Ctrl+Q -> Focus Query Editor (if exists)
        if ((e.ctrlKey || e.metaKey) && e.key === 'q') {
            e.preventDefault();
            window.sqlEditor?.focus();
        }
        // Escape → close sidebar on mobile
        if (e.key === 'Escape') closeSidebar();
    });

    /* ── Active nav highlight (client-side fallback) ── */
    const currentPath = window.location.pathname.split('/').pop() || 'index.php';
    document.querySelectorAll('.nav-link').forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPath) {
            link.classList.add('active');
            link.setAttribute('aria-current', 'page');
        }
    });

    /* ── Table row click to highlight ── */
    document.querySelectorAll('.table tbody tr').forEach(row => {
        row.style.cursor = 'default';
    });

    /* ── Animate stat numbers (count-up) ── */
    document.querySelectorAll('.stat-number').forEach(el => {
        const target = parseInt(el.textContent, 10);
        if (isNaN(target) || target === 0) return;
        let current = 0;
        const increment = Math.ceil(target / 30);
        const timer = setInterval(() => {
            current += increment;
            if (current >= target) {
                current = target;
                clearInterval(timer);
            }
            el.textContent = current.toLocaleString();
        }, 30);
    });

    /* ── Theme Toggle ── */
    const themeBtn = document.getElementById('theme-toggle');
    const iconDark = themeBtn?.querySelector('.icon-dark');
    const iconLight = themeBtn?.querySelector('.icon-light');

    function updateThemeUI(theme) {
        if (!themeBtn) return;
        if (theme === 'light') {
            iconDark.style.display = 'none';
            iconLight.style.display = 'inline';
        } else {
            iconDark.style.display = 'inline';
            iconLight.style.display = 'none';
        }
    }

    // Set initial icon on load
    updateThemeUI(document.documentElement.getAttribute('data-theme'));

    themeBtn?.addEventListener('click', () => {
        let currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
        let newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('gestionedb_theme', newTheme);
        updateThemeUI(newTheme);

        // Save to backend
        fetch('api/theme.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ theme: newTheme })
        }).catch(err => console.error('Error saving theme', err));
    });

})();
</script>

<?php if (isset($extra_js)): ?>
<script><?= $extra_js ?></script>
<?php endif; ?>

<script src="includes/ui/kit.js"></script>
</body>
</html>
