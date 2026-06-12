/**
 * LokaGest - Validation et formatage des formulaires de paiement MoMo
 */

document.addEventListener('DOMContentLoaded', () => {
    const momoPhoneInput = document.getElementById('momo_phone');
    
    if (momoPhoneInput) {
        momoPhoneInput.addEventListener('input', function() {
            // Nettoyer pour ne garder que les chiffres
            this.value = this.value.replace(/[^0-9]/g, '');
            
            // Validation visuelle à 8 chiffres béninois
            if (this.value.length === 8) {
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            } else {
                this.classList.remove('is-valid');
                if (this.value.length > 0) {
                    this.classList.add('is-invalid');
                } else {
                    this.classList.remove('is-invalid');
                }
            }
        });
    }
});
