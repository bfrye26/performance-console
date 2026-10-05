(function () {
    'use strict';
    var root = document.querySelector('.cgm-pfc-root');
    if (!root) { return; }

    var legacyView = (window.location.hash || '').replace(/^#/, '');
    var allowedViews = ['overview', 'findings', 'database', 'profiling', 'monitoring', 'system'];
    var activeView = root.getAttribute('data-active-view') || 'overview';
    if (allowedViews.indexOf(legacyView) !== -1 && legacyView !== activeView) {
        var legacyUrl = new URL(window.location.href);
        legacyUrl.searchParams.set('view', legacyView);
        window.location.replace(legacyUrl.toString());
        return;
    }

    Array.prototype.forEach.call(root.querySelectorAll('[data-pfc-submit-change]'), function (control) {
        control.addEventListener('change', function () { if (control.form) { control.form.submit(); } });
    });

    var incidentCards = Array.prototype.slice.call(root.querySelectorAll('.pfc-incident'));
    var severityFilter = root.querySelector('[data-pfc-filter="severity"]');
    var searchFilter = root.querySelector('[data-pfc-filter="search"]');
    var resultCount = root.querySelector('[data-pfc-result-count]');
    var searchTimer = null;
    function filterIncidents() {
        var severity = severityFilter ? severityFilter.value : '';
        var search = searchFilter ? searchFilter.value.trim().toLowerCase() : '';
        var visible = 0;
        incidentCards.forEach(function (card) {
            var matchesSeverity = !severity || card.getAttribute('data-severity') === severity;
            var matchesSearch = !search || (card.getAttribute('data-search') || '').indexOf(search) !== -1;
            card.hidden = !(matchesSeverity && matchesSearch);
            if (!card.hidden) { visible++; }
        });
        if (resultCount) { resultCount.textContent = String(visible); }
    }
    if (severityFilter) { severityFilter.addEventListener('change', filterIncidents); }
    if (searchFilter) { searchFilter.addEventListener('input', function () { window.clearTimeout(searchTimer); searchTimer = window.setTimeout(filterIncidents, 120); }); }

    Array.prototype.forEach.call(root.querySelectorAll('form[method="post"]'), function (form) {
        form.addEventListener('submit', function (event) {
            var button = event.submitter || form.querySelector('button[type="submit"], button:not([type])');
            if (!button || button.disabled) { return; }
            form.setAttribute('aria-busy', 'true');
            window.setTimeout(function () {
                button.classList.add('is-busy');
                button.setAttribute('aria-busy', 'true');
                button.disabled = true;
            }, 0);
        });
    });
}());
