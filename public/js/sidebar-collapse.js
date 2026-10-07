(function () {
    'use strict';

    var storageKey = 'r_erp_sidebar_groups_v1';
    var knownIds = ['operation', 'inventory', 'catalogs', 'organization', 'administration', 'account'];
    var groups = Array.prototype.slice.call(document.querySelectorAll('[data-sidebar-group]'));

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
