    </div><!-- End container -->
    
    <footer style="background: #2c3e50; color: white; text-align: center; padding: 20px; margin-top: 50px;">
        <?php if (function_exists('company_display_name')): ?>
        <p>&copy; <?= date('Y') ?> <?= htmlspecialchars(company_display_name()) ?>. <?= e(t('all_rights_reserved')) ?></p>
        <?php else: ?>
        <p>&copy; <?= date('Y') ?> Block Plant ERP System. <?= e(t('all_rights_reserved')) ?></p>
        <?php endif; ?>
    </footer>
    
    <!-- ===================== Command Palette (Ctrl+K) ===================== -->
    <div class="cmdk-overlay" id="cmdkOverlay" role="dialog" aria-modal="true" aria-label="Command palette">
        <div class="cmdk-box">
            <div class="cmdk-input-row">
                <span class="icon">🔍</span>
                <input type="text" class="cmdk-input" id="cmdkInput" placeholder="<?= e(t('command_search_placeholder')) ?>" autocomplete="off">
                <span class="cmdk-kbd">Esc</span>
            </div>
            <div class="cmdk-results" id="cmdkResults"></div>
            <div class="cmdk-footer">
                <span><span class="cmdk-kbd">↑</span> <span class="cmdk-kbd">↓</span> <?= t('navigate') ?></span>
                <span><span class="cmdk-kbd">Enter</span> <?= t('open') ?></span>
                <span><span class="cmdk-kbd">Ctrl</span> <span class="cmdk-kbd">K</span> toggle</span>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var overlay = document.getElementById('cmdkOverlay');
        var input = document.getElementById('cmdkInput');
        var resultsEl = document.getElementById('cmdkResults');
        if (!overlay || !input || !resultsEl) return;

        // Build the static navigation index from the permission-filtered navbar.
        var navItems = [];
        document.querySelectorAll('.navbar a').forEach(function (a) {
            var href = a.getAttribute('href') || '';
            var text = (a.textContent || '').replace(/[▼▲]/g, '').trim();
            if (!href || href === '#' || !text) return;
            navItems.push({ group: <?= json_encode(t('navigate')) ?>, icon: '🧭', title: text, sub: href, url: href });
        });
        navItems.push({ group: <?= json_encode(t('navigate')) ?>, icon: '📨', title: <?= json_encode(t('notifications_inbox')) ?>, sub: '/block_plant/notifications/index.php', url: '/block_plant/notifications/index.php' });
        navItems.push({ group: <?= json_encode(t('navigate')) ?>, icon: '⌨️', title: <?= json_encode(t('keyboard_shortcuts')) ?>, sub: <?= json_encode(t('press_question_to_view')) ?>, url: '#kbd-help' });

        var activeIndex = 0;
        var flat = [];
        var fetchTimer = null;
        var lastTerm = '';
        var recordResults = [];

        function isOpen() { return overlay.classList.contains('open'); }

        function open() {
            overlay.classList.add('open');
            input.value = '';
            recordResults = [];
            render('');
            setTimeout(function () { input.focus(); }, 30);
        }

        function close() { overlay.classList.remove('open'); }

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function matches(item, term) {
            if (!term) return true;
            var t = term.toLowerCase();
            return (item.title || '').toLowerCase().indexOf(t) !== -1 ||
                   (item.sub || '').toLowerCase().indexOf(t) !== -1 ||
                   (item.group || '').toLowerCase().indexOf(t) !== -1;
        }

        function render(term) {
            var navHits = navItems.filter(function (i) { return matches(i, term); }).slice(0, term ? 8 : 6);
            var recHits = term ? recordResults : [];
            flat = navHits.concat(recHits);
            activeIndex = Math.min(activeIndex, Math.max(0, flat.length - 1));

            if (!flat.length) {
                resultsEl.innerHTML = '<div class="cmdk-empty"><?= e(t('no_search_matches')) ?> “' + esc(term) + '”. <?= e(t('try_search_hint')) ?></div>';
                return;
            }

            var html = '';
            var lastGroup = null;
            flat.forEach(function (item, i) {
                if (item.group !== lastGroup) {
                    html += '<div class="cmdk-group-title">' + esc(item.group) + '</div>';
                    lastGroup = item.group;
                }
                html += '<a class="cmdk-item' + (i === activeIndex ? ' active' : '') + '" data-i="' + i + '" href="' + esc(item.url) + '">' +
                            '<span class="cmdk-ico">' + esc(item.icon || '•') + '</span>' +
                            '<span class="cmdk-text">' +
                                '<span class="cmdk-title">' + esc(item.title) + '</span>' +
                                (item.sub ? '<span class="cmdk-sub" style="display:block;">' + esc(item.sub) + '</span>' : '') +
                            '</span>' +
                        '</a>';
            });
            resultsEl.innerHTML = html;

            resultsEl.querySelectorAll('.cmdk-item').forEach(function (el) {
                el.addEventListener('mouseenter', function () {
                    activeIndex = parseInt(el.getAttribute('data-i'), 10);
                    highlight();
                });
            });
        }

        function highlight() {
            resultsEl.querySelectorAll('.cmdk-item').forEach(function (el) {
                el.classList.toggle('active', parseInt(el.getAttribute('data-i'), 10) === activeIndex);
            });
            var active = resultsEl.querySelector('.cmdk-item.active');
            if (active) active.scrollIntoView({ block: 'nearest' });
        }

        function go() {
            if (!flat[activeIndex]) return;
            var url = flat[activeIndex].url;
            if (url === '#kbd-help') {
                close();
                document.dispatchEvent(new CustomEvent('bp:open-shortcuts'));
                return;
            }
            window.location.href = url;
        }

        function query(term) {
            lastTerm = term;
            if (!term || term.length < 2) { recordResults = []; render(term); return; }
            clearTimeout(fetchTimer);
            fetchTimer = setTimeout(function () {
                fetch('/block_plant/api/quick_search.php?q=' + encodeURIComponent(term), { credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : { results: [] }; })
                    .then(function (data) {
                        if (lastTerm !== term) return;
                        recordResults = (data.results || []).map(function (r) {
                            return { group: r.group, icon: r.icon, title: r.title, sub: r.sub, url: r.url };
                        });
                        render(term);
                    })
                    .catch(function () { recordResults = []; render(term); });
            }, 180);
        }

        input.addEventListener('input', function () { activeIndex = 0; query(input.value.trim()); });

        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                e.preventDefault();
                isOpen() ? close() : open();
                return;
            }
            if (!isOpen()) return;
            if (e.key === 'Escape') { e.preventDefault(); close(); }
            else if (e.key === 'ArrowDown') { e.preventDefault(); activeIndex = Math.min(activeIndex + 1, flat.length - 1); highlight(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); activeIndex = Math.max(activeIndex - 1, 0); highlight(); }
            else if (e.key === 'Enter') { e.preventDefault(); go(); }
        });

        overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) close(); });

        var openBtn = document.getElementById('cmdkOpen');
        if (openBtn) openBtn.addEventListener('click', open);

        document.addEventListener('DOMContentLoaded', function () { render(''); });
    })();
    </script>

    <!-- ===================== Quick-action FAB + shortcuts ===================== -->
    <div class="fab-wrap" id="fabWrap">
        <div class="fab-menu" id="fabMenu">
            <?php if (has_permission('sales', 'create')): ?>
            <a class="fab-action" href="/block_plant/sales/create.php"><span class="fab-ico">💰</span> <?= t('new_sale') ?></a>
            <?php endif; ?>
            <?php if (has_permission('purchases', 'create')): ?>
            <a class="fab-action" href="/block_plant/purchases/create.php"><span class="fab-ico">🛒</span> <?= t('new_purchase') ?></a>
            <?php endif; ?>
            <?php if (has_permission('production', 'create')): ?>
            <a class="fab-action" href="/block_plant/production/create.php"><span class="fab-ico">📦</span> <?= t('new_production') ?></a>
            <?php endif; ?>
            <?php if (has_permission('expenses', 'create')): ?>
            <a class="fab-action" href="/block_plant/expenses/create.php"><span class="fab-ico">💳</span> <?= t('new_expense') ?></a>
            <?php endif; ?>
            <?php if (has_permission('customers', 'create')): ?>
            <a class="fab-action" href="/block_plant/customers/create.php"><span class="fab-ico">👤</span> <?= t('add_customer') ?></a>
            <?php endif; ?>
            <a class="fab-action" href="#" id="fabShortcuts"><span class="fab-ico">⌨️</span> <?= t('keyboard_shortcuts') ?></a>
        </div>
        <button class="fab-btn" id="fabBtn" title="<?= e(t('quick_actions')) ?>" aria-label="<?= e(t('quick_actions')) ?>">+</button>
    </div>

    <div class="kbd-overlay" id="kbdOverlay" role="dialog" aria-modal="true" aria-label="<?= e(t('keyboard_shortcuts')) ?>">
        <div class="kbd-box">
            <h3>⌨️ <?= t('keyboard_shortcuts') ?></h3>
            <div class="kbd-row"><span><?= t('shortcut_open_palette') ?></span><span><span class="kbd-key">Ctrl</span> <span class="kbd-key">K</span></span></div>
            <div class="kbd-row"><span><?= t('shortcut_close_palette') ?></span><span class="kbd-key">Esc</span></div>
            <div class="kbd-row"><span><?= t('shortcut_move_results') ?></span><span><span class="kbd-key">↑</span> <span class="kbd-key">↓</span></span></div>
            <div class="kbd-row"><span><?= t('shortcut_open_result') ?></span><span class="kbd-key">Enter</span></div>
            <div class="kbd-row"><span><?= t('shortcut_show_help') ?></span><span class="kbd-key">?</span></div>
            <div style="margin-top:16px;text-align:right;">
                <button class="btn btn-secondary" id="kbdClose" type="button"><?= t('close') ?></button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        function showSuccessToasts() {
            var messages = document.querySelectorAll('.flash.flash-success');
            if (!messages.length) return;

            var container = document.getElementById('appToastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'appToastContainer';
                container.className = 'app-toast-container';
                container.setAttribute('aria-live', 'polite');
                container.setAttribute('aria-atomic', 'false');
                document.body.appendChild(container);
            }

            Array.prototype.forEach.call(messages, function (message) {
                var toast = document.createElement('div');
                toast.className = 'app-success-toast';
                toast.setAttribute('role', 'status');

                var icon = document.createElement('span');
                icon.className = 'app-success-toast__icon';
                icon.setAttribute('aria-hidden', 'true');
                icon.textContent = '\u2713';

                var body = document.createElement('div');
                body.className = 'app-success-toast__body';
                while (message.firstChild) body.appendChild(message.firstChild);

                var close = document.createElement('button');
                close.className = 'app-success-toast__close';
                close.type = 'button';
                close.setAttribute('aria-label', <?= json_encode(t('dismiss_success_message')) ?>);
                close.textContent = '\u00d7';

                toast.appendChild(icon);
                toast.appendChild(body);
                toast.appendChild(close);
                container.appendChild(toast);
                message.remove();

                var timer;
                function dismiss() {
                    window.clearTimeout(timer);
                    toast.classList.remove('is-visible');
                    toast.classList.add('is-closing');
                    window.setTimeout(function () { toast.remove(); }, 240);
                }
                function startTimer() { timer = window.setTimeout(dismiss, 6500); }

                close.addEventListener('click', dismiss);
                toast.addEventListener('mouseenter', function () { window.clearTimeout(timer); });
                toast.addEventListener('mouseleave', startTimer);
                toast.addEventListener('focusin', function () { window.clearTimeout(timer); });
                toast.addEventListener('focusout', startTimer);
                window.requestAnimationFrame(function () { toast.classList.add('is-visible'); });
                startTimer();
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', showSuccessToasts);
        } else {
            showSuccessToasts();
        }
    })();
    </script>

    <script>
    (function () {
        var fabWrap = document.getElementById('fabWrap');
        var fabBtn = document.getElementById('fabBtn');
        var kbdOverlay = document.getElementById('kbdOverlay');
        var kbdClose = document.getElementById('kbdClose');
        var fabShortcuts = document.getElementById('fabShortcuts');

        function openKbd() { if (kbdOverlay) kbdOverlay.classList.add('open'); }
        function closeKbd() { if (kbdOverlay) kbdOverlay.classList.remove('open'); }

        if (fabBtn) {
            fabBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                fabWrap.classList.toggle('open');
            });
        }
        document.addEventListener('click', function (e) {
            if (fabWrap && !fabWrap.contains(e.target)) fabWrap.classList.remove('open');
        });

        if (fabShortcuts) {
            fabShortcuts.addEventListener('click', function (e) {
                e.preventDefault();
                fabWrap.classList.remove('open');
                openKbd();
            });
        }
        if (kbdClose) kbdClose.addEventListener('click', closeKbd);
        if (kbdOverlay) kbdOverlay.addEventListener('mousedown', function (e) { if (e.target === kbdOverlay) closeKbd(); });

        // "?" opens shortcuts when not typing in a field; Esc closes.
        document.addEventListener('keydown', function (e) {
            var tag = (document.activeElement && document.activeElement.tagName) || '';
            var typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (document.activeElement && document.activeElement.isContentEditable);
            if (e.key === '?' && !typing) { e.preventDefault(); openKbd(); }
            else if (e.key === 'Escape') { closeKbd(); }
        });

        // Palette can request the shortcuts modal.
        document.addEventListener('bp:open-shortcuts', openKbd);
    })();
    </script>
</body>
</html>
