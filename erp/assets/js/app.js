document.addEventListener('DOMContentLoaded',function(){if(window.lucide&&typeof window.lucide.createIcons==='function')window.lucide.createIcons();const html=document.documentElement,body=document.body,sidebar=document.getElementById('sidebar'),sidebarToggle=document.getElementById('sidebarToggle'),closeBtn=document.getElementById('closeMobileSidebar'),mobileOverlay=document.getElementById('mobileOverlay')||document.body.appendChild(Object.assign(document.createElement('div'),{id:'mobileOverlay'})),settingsPanel=document.getElementById('settingsPanel'),settingsToggle=document.getElementById('settingsToggle'),settingsClose=document.getElementById('settingsClose'),settingsOverlay=document.getElementById('settingsOverlay')||document.body.appendChild(Object.assign(document.createElement('div'),{id:'settingsOverlay'})),darkModeToggle=document.getElementById('darkModeToggle');function isMobile(){return window.innerWidth<1200}function refresh(){if(window.lucide&&typeof window.lucide.createIcons==='function')window.lucide.createIcons()}function closeSidebar(){sidebar?.classList.remove('open');mobileOverlay?.classList.remove('show');body.classList.remove('sidebar-mobile-open');body.style.overflow=''}function openSidebar(){sidebar?.classList.add('open');mobileOverlay?.classList.add('show');body.classList.add('sidebar-mobile-open');body.style.overflow='hidden'}function toggleSidebar(){if(isMobile()){sidebar?.classList.contains('open')?closeSidebar():openSidebar()}else{const c=body.classList.toggle('sidebar-collapsed');localStorage.setItem('subhiksha_sidebar_collapsed',c?'1':'0');refresh()}}window.toggleSidebar=toggleSidebar;if(!isMobile()&&localStorage.getItem('subhiksha_sidebar_collapsed')==='1')body.classList.add('sidebar-collapsed');sidebarToggle?.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();toggleSidebar()},true);closeBtn?.addEventListener('click',function(e){e.preventDefault();closeSidebar()},true);mobileOverlay?.addEventListener('click',closeSidebar,true);function openSettings(){if(!settingsPanel)return;settingsPanel.classList.add('open');settingsOverlay.classList.add('show');settingsPanel.setAttribute('aria-hidden','false');body.style.overflow='hidden';refresh()}function closeSettings(){settingsPanel?.classList.remove('open');settingsPanel?.setAttribute('aria-hidden','true');settingsOverlay?.classList.remove('show');body.style.overflow=''}function toggleSettings(){settingsPanel?.classList.contains('open')?closeSettings():openSettings()}window.toggleSettingsPanel=toggleSettings;settingsToggle?.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();toggleSettings()},true);settingsClose?.addEventListener('click',function(e){e.preventDefault();closeSettings()},true);settingsOverlay?.addEventListener('click',closeSettings,true);function applyDark(isDark){html.setAttribute('data-theme',isDark?'dark':'light');body.classList.toggle('dark-mode',isDark);localStorage.setItem('subhiksha_dark_mode',isDark?'1':'0');const icon=darkModeToggle?.querySelector('[data-lucide]');if(icon)icon.setAttribute('data-lucide',isDark?'sun':'moon');refresh()}applyDark(localStorage.getItem('subhiksha_dark_mode')==='1');darkModeToggle?.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();applyDark(!body.classList.contains('dark-mode'))},true);window.addEventListener('resize',function(){closeSidebar();if(!isMobile())body.classList.toggle('sidebar-collapsed',localStorage.getItem('subhiksha_sidebar_collapsed')==='1');else body.classList.remove('sidebar-collapsed')});document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeSidebar();closeSettings()}});});


/* =========================================================
   SUBHIKSHA COLLAPSED SIDEBAR FLYOUT FINAL FIX
   IMPORTANT: Replaces old flyout JS. Do not keep old flyout blocks.
   Fix: Master Controls flyout stays open while scrolling.
   ========================================================= */
(function () {
    'use strict';

    function onReady(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    onReady(function () {
        const body = document.body;
        const sidebar = document.getElementById('sidebar');

        if (!sidebar) return;

        function isCollapsedDesktop() {
            return window.innerWidth >= 1200 && body.classList.contains('sidebar-collapsed');
        }

        function getSubmenu(link) {
            const href = link.getAttribute('href') || '';
            const target = link.getAttribute('data-bs-target') || '';
            const selector = href.startsWith('#') ? href : (target.startsWith('#') ? target : '');

            if (selector) {
                try {
                    const menu = document.querySelector(selector);
                    if (menu && menu.classList.contains('sidebar-submenu')) return menu;
                } catch (e) {}
            }

            const next = link.nextElementSibling;
            if (next && next.classList.contains('sidebar-submenu')) return next;

            return null;
        }

        function closeAllFlyouts() {
            sidebar.querySelectorAll('.sidebar-submenu.sidebar-flyout-open').forEach(function (menu) {
                menu.classList.remove('sidebar-flyout-open');
                menu.classList.remove('show');
                menu.style.removeProperty('left');
                menu.style.removeProperty('top');
                menu.style.removeProperty('max-height');
            });

            sidebar.querySelectorAll('.sidebar-collapse-link.flyout-parent-open').forEach(function (link) {
                link.classList.remove('flyout-parent-open');
                link.setAttribute('aria-expanded', 'false');
            });
        }

        function openFlyout(link, menu) {
            closeAllFlyouts();

            const sidebarRect = sidebar.getBoundingClientRect();
            const linkRect = link.getBoundingClientRect();
            const gap = 12;
            const viewportHeight = window.innerHeight || document.documentElement.clientHeight;

            link.classList.add('flyout-parent-open');
            link.setAttribute('aria-expanded', 'true');

            menu.classList.add('sidebar-flyout-open');
            menu.classList.add('show');

            const maxHeight = Math.max(240, viewportHeight - (gap * 2));
            menu.style.left = Math.round(sidebarRect.right + 18) + 'px';
            menu.style.maxHeight = maxHeight + 'px';

            const menuHeight = Math.min(menu.scrollHeight || 300, maxHeight);
            let top = linkRect.top - 18;

            if (top + menuHeight + gap > viewportHeight) {
                top = viewportHeight - menuHeight - gap;
            }

            if (top < gap) top = gap;

            menu.style.top = Math.round(top) + 'px';
        }

        function toggleFlyout(link) {
            const menu = getSubmenu(link);
            if (!menu) return;

            if (menu.classList.contains('sidebar-flyout-open')) {
                closeAllFlyouts();
            } else {
                openFlyout(link, menu);
            }
        }

        // Click to open/close in collapsed mode. Disable Bootstrap collapse behavior only in collapsed mode.
        sidebar.addEventListener('click', function (event) {
            const link = event.target.closest('.sidebar-collapse-link');

            if (!link || !sidebar.contains(link)) return;
            if (!isCollapsedDesktop()) return;

            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();

            toggleFlyout(link);
        }, true);

        // No mouseleave close. This was the actual reason it disappeared while scrolling.
        // Keep flyout open until click outside / ESC / resize / expand sidebar.

        // Stop scrolling inside flyout from reaching window/sidebar.
        sidebar.addEventListener('wheel', function (event) {
            const menu = event.target.closest('.sidebar-submenu.sidebar-flyout-open');

            if (!menu || !isCollapsedDesktop()) return;

            const delta = event.deltaY;
            const atTop = menu.scrollTop <= 0;
            const atBottom = Math.ceil(menu.scrollTop + menu.clientHeight) >= menu.scrollHeight;

            if ((delta < 0 && atTop) || (delta > 0 && atBottom)) {
                event.preventDefault();
            }

            event.stopPropagation();
            event.stopImmediatePropagation();
        }, { passive: false, capture: true });

        sidebar.addEventListener('touchmove', function (event) {
            const menu = event.target.closest('.sidebar-submenu.sidebar-flyout-open');
            if (!menu || !isCollapsedDesktop()) return;
            event.stopPropagation();
        }, { passive: true, capture: true });

        // Prevent old/global scroll handlers from closing flyout when the scroll target is the flyout.
        document.addEventListener('scroll', function (event) {
            const target = event.target;
            if (target && target.closest && target.closest('#sidebar .sidebar-submenu.sidebar-flyout-open')) {
                event.stopPropagation();
                event.stopImmediatePropagation();
            }
        }, true);

        document.addEventListener('click', function (event) {
            if (!isCollapsedDesktop()) return;

            if (
                event.target.closest('#sidebar .sidebar-submenu.sidebar-flyout-open') ||
                event.target.closest('#sidebar .sidebar-collapse-link.flyout-parent-open')
            ) {
                return;
            }

            closeAllFlyouts();
        }, true);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeAllFlyouts();
        });

        window.addEventListener('resize', closeAllFlyouts);

        const observer = new MutationObserver(function () {
            if (!body.classList.contains('sidebar-collapsed')) {
                closeAllFlyouts();
            }
        });

        observer.observe(body, {
            attributes: true,
            attributeFilter: ['class']
        });
    });
})();

