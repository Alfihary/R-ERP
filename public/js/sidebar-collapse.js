(function () {
    'use strict';

    if (window.__rErpSidebarInitialized) {
        return;
    }
    window.__rErpSidebarInitialized = true;

    var storageKey = 'r_erp_sidebar_groups_v1';
    var knownIds = ['operation', 'inventory', 'catalogs', 'organization', 'administration', 'account'];
    var groups = Array.prototype.slice.call(document.querySelectorAll('[data-sidebar-group]'));
    var sidebar = document.querySelector('.app-sidebar');
    var workspace = document.querySelector('.app-workspace');
    var openControl = document.querySelector('[data-sidebar-open]');
    var closeControl = document.querySelector('[data-sidebar-close]');
    var overlay = document.querySelector('[data-sidebar-overlay]');
    var mobileQuery = typeof window.matchMedia === 'function'
        ? window.matchMedia('(max-width: 48rem)')
        : {
            matches: false,
            addEventListener: function () {},
            addListener: function () {},
        };
    var lastOpenControl = null;

    function setMobileOpen(open, returnFocus) {
        if (!sidebar) {
            return;
        }

        if (!mobileQuery.matches) {
            open = false;
        }

        sidebar.setAttribute('data-mobile-open', open ? 'true' : 'false');
        sidebar.setAttribute('aria-hidden', open || !mobileQuery.matches ? 'false' : 'true');

        if (open) {
            sidebar.removeAttribute('inert');
            if (workspace) {
                workspace.setAttribute('inert', '');
            }
        } else if (mobileQuery.matches) {
            sidebar.setAttribute('inert', '');
            if (workspace) {
                workspace.removeAttribute('inert');
            }
        } else {
            sidebar.removeAttribute('inert');
            if (workspace) {
                workspace.removeAttribute('inert');
            }
        }

        if (openControl) {
            openControl.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        if (overlay) {
            overlay.hidden = !open;
            overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
        }

        if (open && closeControl) {
            closeControl.focus();
        } else if (!open && returnFocus && lastOpenControl) {
            lastOpenControl.focus();
        }
    }

    if (sidebar && openControl && closeControl && overlay) {
        openControl.addEventListener('click', function () {
            lastOpenControl = openControl;
            setMobileOpen(true, false);
        });
        closeControl.addEventListener('click', function () {
            setMobileOpen(false, true);
        });
        overlay.addEventListener('click', function () {
            setMobileOpen(false, true);
        });
        sidebar.addEventListener('click', function (event) {
            if (event.target.closest('a')) {
                setMobileOpen(false, false);
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && mobileQuery.matches && sidebar.getAttribute('data-mobile-open') === 'true') {
                setMobileOpen(false, true);
            }
        });
        var handleViewportChange = function () {
            setMobileOpen(false, false);
        };
        if (typeof mobileQuery.addEventListener === 'function') {
            mobileQuery.addEventListener('change', handleViewportChange);
        } else if (typeof mobileQuery.addListener === 'function') {
            mobileQuery.addListener(handleViewportChange);
        }
        setMobileOpen(false, false);
    }

    if (groups.length === 0) {
        return;
    }

    var activeGroup = groups.find(function (group) {
        return group.classList.contains('has-active-item');
    });
    var activeId = activeGroup ? activeGroup.getAttribute('data-sidebar-group') : null;
    var storedOpenIds = [];

    try {
        var stored = window.localStorage.getItem(storageKey);
        var parsed = stored ? JSON.parse(stored) : [];

        if (Array.isArray(parsed)) {
            storedOpenIds = parsed.filter(function (id) {
                return typeof id === 'string' && knownIds.indexOf(id) !== -1;
            });
        }
    } catch (error) {
        storedOpenIds = [];
    }

    function saveOpenIds() {
        var visibleOpenIds = groups.filter(function (group) {
            return group.querySelector('.app-navigation__toggle').getAttribute('aria-expanded') === 'true';
        }).map(function (group) {
            return group.getAttribute('data-sidebar-group');
        });
        var visibleIds = groups.map(function (group) {
            return group.getAttribute('data-sidebar-group');
        });
        var openIds = storedOpenIds.filter(function (id) {
            return visibleIds.indexOf(id) === -1;
        }).concat(visibleOpenIds);
        storedOpenIds = openIds;

        try {
            window.localStorage.setItem(storageKey, JSON.stringify(openIds));
        } catch (error) {
            // Navigation remains usable when storage is unavailable.
        }
    }

    function setGroupOpen(group, open, persist) {
        var id = group.getAttribute('data-sidebar-group');
        var toggle = group.querySelector('.app-navigation__toggle');
        var content = group.querySelector('.app-navigation__group-content');

        if (id === activeId && !open) {
            open = true;
        }

        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        content.hidden = !open;

        if (persist) {
            saveOpenIds();
        }
    }

    groups.forEach(function (group) {
        var id = group.getAttribute('data-sidebar-group');
        var toggle = group.querySelector('.app-navigation__toggle');
        var shouldOpen = id === activeId || storedOpenIds.indexOf(id) !== -1;

        setGroupOpen(group, shouldOpen, false);

        toggle.addEventListener('click', function () {
            var isOpen = toggle.getAttribute('aria-expanded') === 'true';
            setGroupOpen(group, !isOpen, true);
        });
    });

    saveOpenIds();
}());
