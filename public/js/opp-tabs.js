window.OPP = window.OPP || {};

OPP.initTabs = function() {
    var hashMap = {
        '#cours-annee': '#tab-annee',
        '#cours-unite': '#tab-unite',
        '#soutenir': '#tab-soutien'
    };

    var hash = location.hash;
    if (hash && hashMap[hash]) {
        var tabBtn = document.querySelector('[data-bs-target="' + hashMap[hash] + '"]');
        if (tabBtn) new bootstrap.Tab(tabBtn).show();
    }

    document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function(btn) {
        btn.addEventListener('shown.bs.tab', function() {
            var target = btn.getAttribute('data-bs-target');
            for (var h in hashMap) {
                if (hashMap[h] === target) {
                    history.replaceState(null, '', h);
                    return;
                }
            }
        });
    });
};
