window.OPP = window.OPP || {};

OPP.setupDonationPresets = function(formSelector, suffix, onChange) {
    document.querySelectorAll(formSelector + ' input[name="donation_preset"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            var group = document.getElementById('custom-donation-group' + suffix);
            if (group) group.style.display = this.value === 'custom' ? '' : 'none';
            if (onChange) onChange();
        });
    });

    var customInput = document.querySelector('#custom-donation-group' + suffix + ' input[name="donation_custom_amount"]');
    if (customInput && onChange) customInput.addEventListener('input', onChange);
};
