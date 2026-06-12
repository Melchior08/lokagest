/**
 * LokaGest - Validation des demandes de retrait du portefeuille
 */

document.addEventListener('DOMContentLoaded', () => {
    const retraitMontantInput = document.getElementById('montant');
    const momoRetraitPhone = document.getElementById('numero_momo');
    
    if (retraitMontantInput && momoRetraitPhone) {
        const form = retraitMontantInput.closest('form');
        
        if (form) {
            form.addEventListener('submit', function(e) {
                const montant = parseFloat(retraitMontantInput.value);
                const phone = momoRetraitPhone.value.replace(/[^0-9]/g, '');
                
                if (montant < 5000) {
                    e.preventDefault();
                    alert("Le montant minimum de retrait est de 5 000 FCFA.");
                }
                
                if (phone.length !== 8) {
                    e.preventDefault();
                    alert("Veuillez entrer un numéro MoMo béninois valide à 8 chiffres.");
                }
            });
        }
    }
});
