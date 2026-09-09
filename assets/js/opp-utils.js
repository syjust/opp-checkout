window.OPP = window.OPP || {};

OPP.formatEuros = function(cents) {
    var val = cents / 100;
    if (val % 1 === 0) return val.toLocaleString('fr-FR') + ' €';
    return val.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
};

OPP.isValidEmail = function(email) {
    if (!email) return false;
    var input = document.createElement('input');
    input.type = 'email';
    input.value = email;
    return input.validity.valid;
};
