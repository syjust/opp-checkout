window.OPP = window.OPP || {};

OPP.checkMembership = function(email, suffix) {
    var adhesionEl = document.getElementById('adhesion-section' + suffix);
    var sectionAdhDon = document.getElementById('section-adhesion-don' + suffix);
    var sectionPayment = document.getElementById('section-payment' + suffix);

    if (!OPP.isValidEmail(email)) {
        if (sectionAdhDon) sectionAdhDon.style.display = 'none';
        if (sectionPayment) sectionPayment.style.display = 'none';
        if (suffix === '') {
            OPP._membershipChecked = false;
            if (OPP.updateCart) OPP.updateCart();
        }
        return;
    }

    fetch(OPP._membershipUrl + '?email=' + encodeURIComponent(email))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var adhesionHtml;
            if (data.has_membership) {
                adhesionHtml = '<div class="alert alert-success py-2 mb-3"><small>✓ Adhésion ' + OPP._schoolYear + ' déjà validée</small></div><input type="hidden" name="adhesion_amount" value="0">';
            } else {
                adhesionHtml = '<div class="mb-3"><label class="form-label fw-bold">Adhésion ' + OPP._schoolYear + ' <span class="text-danger">*</span></label><div class="input-group" style="max-width:200px;"><input type="number" class="form-control" name="adhesion_amount" min="1" step="1" value="10" required><span class="input-group-text">€</span></div><div class="form-text">Prix libre, minimum 1 €. Obligatoire pour s\'inscrire.</div></div>';
            }

            adhesionEl.innerHTML = adhesionHtml;
            if (sectionAdhDon) sectionAdhDon.style.display = '';
            if (sectionPayment) sectionPayment.style.display = '';

            if (suffix === '') {
                OPP._hasMembership = data.has_membership;
                OPP._hasReductionFromPurchase = data.has_reduction || false;
                OPP._membershipChecked = true;
                if (!data.has_membership) {
                    var adhInput = adhesionEl.querySelector('input[name="adhesion_amount"]');
                    if (adhInput && OPP.updateCart) adhInput.addEventListener('input', OPP.updateCart);
                }
                if (OPP.updateAllCards) OPP.updateAllCards();
            } else {
                var updateFn = OPP['updateTotal' + suffix.replace('-', '_')];
                if (!data.has_membership) {
                    var adhInput2 = adhesionEl.querySelector('input[name="adhesion_amount"]');
                    if (adhInput2 && updateFn) adhInput2.addEventListener('input', updateFn);
                }
                if (updateFn) updateFn();
            }
        });
};

OPP.setupEmailDebounce = function(inputId, suffix) {
    var el = document.getElementById(inputId);
    if (!el) return;
    var timer = null;
    el.addEventListener('input', function() {
        var email = this.value;
        clearTimeout(timer);
        timer = setTimeout(function() { OPP.checkMembership(email, suffix); }, 500);
    });
};
