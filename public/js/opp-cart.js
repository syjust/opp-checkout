window.OPP = window.OPP || {};

(function() {
    'use strict';

    var PRODUCTS = [];
    var cart = {};
    var currentRhythm = '10x';

    var INSTALLMENTS = { '1x': null, '3x': 3, '10x': 10 };

    function getProductBySlug(slug) {
        for (var i = 0; i < PRODUCTS.length; i++) {
            if (PRODUCTS[i].slug === slug) return PRODUCTS[i];
        }
        return null;
    }

    function cartHasReductionEligible() {
        for (var slug in cart) {
            var p = getProductBySlug(slug);
            if (p && p.grants_reduction) return true;
        }
        return false;
    }

    function getEffectivePrice(product, rhythm) {
        var eligible = product.is_guinguette && (cartHasReductionEligible() || OPP._hasReductionFromPurchase);
        var reducedKey = rhythm + '_reduc';
        if (eligible && product.prices[reducedKey]) {
            return { price: product.prices[reducedKey], reduced: true, normalPrice: product.prices[rhythm] };
        }
        return { price: product.prices[rhythm], reduced: false, normalPrice: null };
    }

    function getAnnualAmount(priceInfo) {
        if (!priceInfo || !priceInfo.price) return 0;
        var inst = INSTALLMENTS[currentRhythm];
        if (inst) return priceInfo.price.amount * inst;
        return priceInfo.price.amount;
    }

    function getPeriodSuffix() {
        return currentRhythm === '3x' ? ' / trim.*' : ' / mois*';
    }

    function getAdhesionAmountCents() {
        var el = document.querySelector('#checkout-form input[name="adhesion_amount"]');
        if (!el) return 0;
        return Math.max(0, parseInt(el.value, 10) || 0) * 100;
    }

    function getDonAmountCents() {
        var checked = document.querySelector('#checkout-form input[name="donation_preset"]:checked');
        if (!checked) return 0;
        if (checked.value === 'custom') {
            var custom = document.querySelector('#checkout-form input[name="donation_custom_amount"]');
            return Math.max(0, parseInt(custom ? custom.value : '0', 10) || 0) * 100;
        }
        return Math.max(0, parseInt(checked.value, 10) || 0) * 100;
    }

    function updateCardDisplay(card, product) {
        var rhythm = currentRhythm;
        var eff = getEffectivePrice(product, rhythm);
        var priceEl = card.querySelector('.course-price');
        var installmentEl = card.querySelector('.course-installment');
        var struckEl = card.querySelector('.guinguette-price-normal');
        var statusEl = card.querySelector('.reduction-status');

        var inst = INSTALLMENTS[rhythm];
        if (eff.price) {
            if (inst) {
                priceEl.textContent = OPP.formatEuros(eff.price.amount) + getPeriodSuffix();
            } else {
                priceEl.textContent = OPP.formatEuros(eff.price.amount) + ' / an';
            }
        } else {
            priceEl.textContent = '';
        }

        if (installmentEl) {
            if (eff.price && inst) {
                var annual = getAnnualAmount(eff);
                if (eff.reduced && eff.normalPrice) {
                    var normalAnnual = eff.normalPrice.amount * inst;
                    installmentEl.innerHTML = '<span class="price-struck">' + OPP.formatEuros(normalAnnual) + ' / an</span> ' + OPP.formatEuros(annual) + ' / an';
                } else {
                    installmentEl.textContent = OPP.formatEuros(annual) + ' / an';
                }
            } else {
                installmentEl.innerHTML = '';
            }
        }

        if (product.is_guinguette) {
            if (struckEl) {
                if (eff.reduced && eff.normalPrice) {
                    if (inst) {
                        struckEl.textContent = OPP.formatEuros(eff.normalPrice.amount) + getPeriodSuffix();
                    } else {
                        struckEl.textContent = OPP.formatEuros(eff.normalPrice.amount) + ' / an';
                    }
                    struckEl.style.display = '';
                } else {
                    struckEl.style.display = 'none';
                }
            }
            if (statusEl) {
                var eligible = cartHasReductionEligible();
                if (eff.reduced) {
                    statusEl.className = 'badge bg-success reduction-badge reduction-status mb-2 align-self-start';
                    statusEl.textContent = 'Tarif réduit appliqué';
                    statusEl.style.display = '';
                } else if (eligible) {
                    statusEl.style.display = 'none';
                } else if (product.reduced_by.length > 0) {
                    statusEl.className = 'badge bg-warning text-dark reduction-badge reduction-status mb-2 align-self-start';
                    statusEl.textContent = 'Tarif réduit appliquable';
                    statusEl.style.display = '';
                } else {
                    statusEl.style.display = 'none';
                }
            }
        }

        var inCart = cart[product.slug] === true;
        var btn = card.querySelector('.cart-toggle-btn');
        card.classList.toggle('in-cart', inCart);
        card.classList.toggle('border-2', inCart);
        if (btn) {
            if (inCart) {
                btn.textContent = 'Retirer';
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-outline-danger');
            } else {
                btn.textContent = 'Ajouter';
                btn.classList.remove('btn-outline-danger');
                btn.classList.add('btn-outline-primary');
            }
        }
    }

    function updateCart() {
        var cartItemsEl = document.getElementById('cart-items');
        var cartEmptyEl = document.getElementById('cart-empty');
        var cartTotals = document.getElementById('cart-totals');
        var items = [];

        cartItemsEl.querySelectorAll('.cart-line').forEach(function(el) { el.remove(); });

        var totalAnnual = 0;
        var totalNormalAnnual = 0;
        var inst = INSTALLMENTS[currentRhythm];
        var suffix = getPeriodSuffix();

        for (var slug in cart) {
            var product = getProductBySlug(slug);
            if (!product) continue;

            var eff = getEffectivePrice(product, currentRhythm);
            if (!eff.price) continue;

            var annual = getAnnualAmount(eff);
            totalAnnual += annual;

            if (eff.reduced && eff.normalPrice) {
                var normalAnnual = inst ? eff.normalPrice.amount * inst : eff.normalPrice.amount;
                totalNormalAnnual += normalAnnual;
            } else {
                totalNormalAnnual += annual;
            }

            var li = document.createElement('li');
            li.className = 'list-group-item px-0 cart-line';
            if (inst) {
                li.innerHTML = '<div class="d-flex justify-content-between"><span>' + product.name + '</span><strong class="text-nowrap ms-2">' + OPP.formatEuros(eff.price.amount) + suffix + '</strong></div>' +
                    '<div class="text-end"><small class="text-muted">' + OPP.formatEuros(annual) + ' / an</small></div>';
            } else {
                li.innerHTML = '<div class="d-flex justify-content-between"><span>' + product.name + '</span><strong class="text-nowrap ms-2">' + OPP.formatEuros(annual) + ' / an</strong></div>';
            }

            cartItemsEl.appendChild(li);
            items.push({ slug: slug, priceId: eff.price.id });
        }

        var adhesionCents = getAdhesionAmountCents();
        var donCents = getDonAmountCents();

        if (adhesionCents > 0) {
            var liAdh = document.createElement('li');
            liAdh.className = 'list-group-item px-0 cart-line';
            liAdh.innerHTML = '<div class="d-flex justify-content-between"><span>Adhésion ' + OPP._schoolYear + '</span><strong class="text-nowrap ms-2">' + OPP.formatEuros(adhesionCents) + '</strong></div>';
            cartItemsEl.appendChild(liAdh);
            totalAnnual += adhesionCents;
            totalNormalAnnual += adhesionCents;
        }

        if (donCents > 0) {
            var liDon = document.createElement('li');
            liDon.className = 'list-group-item px-0 cart-line';
            liDon.innerHTML = '<div class="d-flex justify-content-between"><span>Don à l\'OPP</span><strong class="text-nowrap ms-2">' + OPP.formatEuros(donCents) + '</strong></div>';
            cartItemsEl.appendChild(liDon);
            totalAnnual += donCents;
            totalNormalAnnual += donCents;
        }

        var hasItems = items.length > 0;
        cartEmptyEl.style.display = hasItems ? 'none' : '';
        cartTotals.style.display = hasItems ? '' : 'none';

        var sectionPayment = document.getElementById('section-payment');
        sectionPayment.style.display = (hasItems && OPP._membershipChecked) ? '' : 'none';

        if (hasItems) {
            var perPaymentCourses = 0;
            if (inst) {
                for (var s in cart) {
                    var pp = getProductBySlug(s);
                    if (!pp) continue;
                    var ee = getEffectivePrice(pp, currentRhythm);
                    if (ee.price) perPaymentCourses += ee.price.amount;
                }
            }
            var firstPayment = inst ? perPaymentCourses + adhesionCents + donCents : totalAnnual;

            var totalLabel = document.getElementById('cart-total-label');
            var todayEl = document.getElementById('cart-total-today');
            var scheduleRow = document.getElementById('cart-total-schedule');
            var scheduleRowText = document.getElementById('cart-total-schedule-text');
            var annualRow = document.getElementById('cart-total-annual-row');
            var normalEl = document.getElementById('cart-annual-normal');
            var totalEl = document.getElementById('cart-annual-total');

            if (inst) {
                totalLabel.textContent = "Total aujourd'hui";
                todayEl.textContent = OPP.formatEuros(firstPayment);

                scheduleRowText.textContent = 'puis ' + (inst - 1) + ' paiements de ' + OPP.formatEuros(perPaymentCourses) + (currentRhythm === '3x' ? ' / trimestre*' : ' / mois*');
                scheduleRow.style.display = '';

                if (totalNormalAnnual > totalAnnual) {
                    normalEl.textContent = OPP.formatEuros(totalNormalAnnual);
                    normalEl.style.display = '';
                } else {
                    normalEl.style.display = 'none';
                }
                totalEl.textContent = OPP.formatEuros(totalAnnual);
                annualRow.style.display = '';
            } else {
                totalLabel.textContent = 'Total';
                if (totalNormalAnnual > totalAnnual) {
                    todayEl.innerHTML = '<span class="price-struck">' + OPP.formatEuros(totalNormalAnnual) + '</span> ' + OPP.formatEuros(totalAnnual);
                } else {
                    todayEl.textContent = OPP.formatEuros(totalAnnual);
                }
                scheduleRow.style.display = 'none';
                annualRow.style.display = 'none';
            }

            var paymentLabel = document.getElementById('cart-payment-label');
            var paymentAmount = document.getElementById('cart-payment-amount');
            var scheduleEl = document.getElementById('cart-schedule');
            var scheduleText = document.getElementById('cart-schedule-text');

            if (inst) {
                paymentLabel.innerHTML = '1<sup>er</sup> paiement aujourd\'hui';
                paymentAmount.textContent = OPP.formatEuros(firstPayment);
                scheduleText.textContent = 'puis ' + (inst - 1) + ' paiements de ' + OPP.formatEuros(perPaymentCourses) + (currentRhythm === '3x' ? ' / trimestre*' : ' / mois*');
                scheduleEl.style.display = '';
            } else {
                paymentLabel.textContent = 'Total';
                paymentAmount.textContent = OPP.formatEuros(totalAnnual);
                scheduleEl.style.display = 'none';
            }
        }

        document.getElementById('checkout-form').querySelectorAll('input[name="price_ids[]"]').forEach(function(el) { el.remove(); });
        items.forEach(function(item) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'price_ids[]';
            input.value = item.priceId;
            document.getElementById('checkout-form').appendChild(input);
        });

        var btn = document.getElementById('checkout-btn');
        btn.disabled = !hasItems || !OPP._membershipChecked;
    }

    function updateAllCards() {
        document.querySelectorAll('.course-card[data-slug]').forEach(function(card) {
            var slug = card.dataset.slug;
            var product = getProductBySlug(slug);
            if (product) updateCardDisplay(card, product);
        });
        updateCart();

        var footnote = document.getElementById('installment-footnote');
        if (currentRhythm === '10x') {
            footnote.textContent = '* Tarif mensuel sur 10 mensualités (septembre à juin).';
            footnote.style.display = '';
        } else if (currentRhythm === '3x') {
            footnote.textContent = '* Tarif trimestriel sur 3 échéances.';
            footnote.style.display = '';
        } else {
            footnote.style.display = 'none';
        }
    }

    OPP.updateCart = updateCart;
    OPP.updateAllCards = updateAllCards;

    OPP.initCart = function(products) {
        PRODUCTS = products;

        document.querySelectorAll('.course-card[data-slug]').forEach(function(card) {
            var btn = card.querySelector('.cart-toggle-btn');
            if (btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var slug = card.dataset.slug;
                    if (cart[slug]) {
                        delete cart[slug];
                    } else {
                        cart[slug] = true;
                    }
                    updateAllCards();
                });
            }
        });

        document.querySelectorAll('input[name="rhythm"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                currentRhythm = this.value;
                updateAllCards();
            });
        });

        updateAllCards();
    };
})();
