document.addEventListener('DOMContentLoaded',function(){if(window.lucide&&typeof window.lucide.createIcons==='function')window.lucide.createIcons();const html=document.documentElement,body=document.body,sidebar=document.getElementById('sidebar'),sidebarToggle=document.getElementById('sidebarToggle'),closeBtn=document.getElementById('closeMobileSidebar'),mobileOverlay=document.getElementById('mobileOverlay')||document.body.appendChild(Object.assign(document.createElement('div'),{id:'mobileOverlay'})),settingsPanel=document.getElementById('settingsPanel'),settingsToggle=document.getElementById('settingsToggle'),settingsClose=document.getElementById('settingsClose'),settingsOverlay=document.getElementById('settingsOverlay')||document.body.appendChild(Object.assign(document.createElement('div'),{id:'settingsOverlay'})),darkModeToggle=document.getElementById('darkModeToggle');function isMobile(){return window.innerWidth<1200}function refresh(){if(window.lucide&&typeof window.lucide.createIcons==='function')window.lucide.createIcons()}function closeSidebar(){sidebar?.classList.remove('open');mobileOverlay?.classList.remove('show');body.classList.remove('sidebar-mobile-open');body.style.overflow=''}function openSidebar(){sidebar?.classList.add('open');mobileOverlay?.classList.add('show');body.classList.add('sidebar-mobile-open');body.style.overflow='hidden'}function toggleSidebar(){if(isMobile()){sidebar?.classList.contains('open')?closeSidebar():openSidebar()}else{const c=body.classList.toggle('sidebar-collapsed');localStorage.setItem('subhiksha_sidebar_collapsed',c?'1':'0');refresh()}}window.toggleSidebar=toggleSidebar;if(!isMobile()&&localStorage.getItem('subhiksha_sidebar_collapsed')==='1')body.classList.add('sidebar-collapsed');sidebarToggle?.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();toggleSidebar()},true);closeBtn?.addEventListener('click',function(e){e.preventDefault();closeSidebar()},true);mobileOverlay?.addEventListener('click',closeSidebar,true);function openSettings(){if(!settingsPanel)return;settingsPanel.classList.add('open');settingsOverlay.classList.add('show');settingsPanel.setAttribute('aria-hidden','false');body.style.overflow='hidden';refresh()}function closeSettings(){settingsPanel?.classList.remove('open');settingsPanel?.setAttribute('aria-hidden','true');settingsOverlay?.classList.remove('show');body.style.overflow=''}function toggleSettings(){settingsPanel?.classList.contains('open')?closeSettings():openSettings()}window.toggleSettingsPanel=toggleSettings;settingsToggle?.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();toggleSettings()},true);settingsClose?.addEventListener('click',function(e){e.preventDefault();closeSettings()},true);settingsOverlay?.addEventListener('click',closeSettings,true);function applyDark(isDark){html.setAttribute('data-theme',isDark?'dark':'light');body.classList.toggle('dark-mode',isDark);localStorage.setItem('subhiksha_dark_mode',isDark?'1':'0');const icon=darkModeToggle?.querySelector('[data-lucide]');if(icon)icon.setAttribute('data-lucide',isDark?'sun':'moon');refresh()}applyDark(localStorage.getItem('subhiksha_dark_mode')==='1');darkModeToggle?.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();applyDark(!body.classList.contains('dark-mode'))},true);window.addEventListener('resize',function(){closeSidebar();if(!isMobile())body.classList.toggle('sidebar-collapsed',localStorage.getItem('subhiksha_sidebar_collapsed')==='1');else body.classList.remove('sidebar-collapsed')});document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeSidebar();closeSettings()}});});


/* =========================================================
   SUBHIKSHA COLLAPSED SIDEBAR FLYOUT FIX
   Included directly inside app.js
   ========================================================= */
(function () {
    'use strict';

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    ready(function () {
        const body = document.body;
        const sidebar = document.getElementById('sidebar');

        if (!sidebar) {
            return;
        }

        function isCollapsedDesktop() {
            return window.innerWidth >= 1200 && body.classList.contains('sidebar-collapsed');
        }

        function closeAllFlyouts() {
            document.querySelectorAll('#sidebar .sidebar-submenu.sidebar-flyout-open').forEach(function (submenu) {
                submenu.classList.remove('sidebar-flyout-open', 'sidebar-flyout-measuring');
                submenu.style.removeProperty('left');
                submenu.style.removeProperty('top');
                submenu.style.removeProperty('max-height');
            });

            document.querySelectorAll('#sidebar .sidebar-collapse-link.flyout-parent-open').forEach(function (link) {
                link.classList.remove('flyout-parent-open');
            });
        }

        function getSubmenuFromLink(link) {
            const href = link.getAttribute('href') || '';
            const target = link.getAttribute('data-bs-target') || '';
            let selector = '';

            if (href.startsWith('#')) {
                selector = href;
            } else if (target.startsWith('#')) {
                selector = target;
            }

            if (selector) {
                try {
                    const found = document.querySelector(selector);
                    if (found && found.classList.contains('sidebar-submenu')) {
                        return found;
                    }
                } catch (error) {}
            }

            const next = link.nextElementSibling;
            if (next && next.classList.contains('sidebar-submenu')) {
                return next;
            }

            return null;
        }

        function placeFlyout(link, submenu) {
            const sidebarRect = sidebar.getBoundingClientRect();
            const linkRect = link.getBoundingClientRect();
            const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
            const gap = 12;
            const leftGap = 14;

            submenu.classList.add('sidebar-flyout-open', 'sidebar-flyout-measuring');
            submenu.style.left = Math.round(sidebarRect.right + leftGap) + 'px';
            submenu.style.top = gap + 'px';
            submenu.style.maxHeight = Math.max(220, viewportHeight - (gap * 2)) + 'px';

            const flyoutHeight = Math.min(
                submenu.scrollHeight || submenu.offsetHeight || 300,
                viewportHeight - (gap * 2)
            );

            let top = linkRect.top - 18;

            if (top + flyoutHeight + gap > viewportHeight) {
                top = viewportHeight - flyoutHeight - gap;
            }

            if (top < gap) {
                top = gap;
            }

            submenu.style.left = Math.round(sidebarRect.right + leftGap) + 'px';
            submenu.style.top = Math.round(top) + 'px';
            submenu.style.maxHeight = Math.round(viewportHeight - top - gap) + 'px';

            submenu.classList.remove('sidebar-flyout-measuring');
        }

        document.querySelectorAll('#sidebar .sidebar-collapse-link, #sidebar [data-bs-toggle="collapse"]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                if (!isCollapsedDesktop()) {
                    return;
                }

                const submenu = getSubmenuFromLink(link);

                if (!submenu) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();

                const wasOpen = submenu.classList.contains('sidebar-flyout-open');
                closeAllFlyouts();

                if (!wasOpen) {
                    link.classList.add('flyout-parent-open');
                    placeFlyout(link, submenu);
                }
            }, true);

            link.addEventListener('mouseenter', function () {
                if (!isCollapsedDesktop()) {
                    return;
                }

                const submenu = getSubmenuFromLink(link);
                if (!submenu) {
                    return;
                }

                closeAllFlyouts();
                link.classList.add('flyout-parent-open');
                placeFlyout(link, submenu);
            });
        });

        sidebar.addEventListener('mouseleave', function (event) {
            if (!isCollapsedDesktop()) {
                return;
            }

            const related = event.relatedTarget;
            if (related && related.closest && related.closest('#sidebar .sidebar-submenu.sidebar-flyout-open')) {
                return;
            }

            setTimeout(function () {
                if (!document.querySelector('#sidebar:hover')) {
                    closeAllFlyouts();
                }
            }, 150);
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('#sidebar')) {
                closeAllFlyouts();
            }
        }, true);

        window.addEventListener('resize', closeAllFlyouts);
        window.addEventListener('scroll', closeAllFlyouts, true);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeAllFlyouts();
            }
        });

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
