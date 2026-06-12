/**
 * LokaGest - Dynamisation du Tableau de Bord (Dashboard)
 */

document.addEventListener('DOMContentLoaded', () => {
    console.log("[LokaGest] Tableau de bord propriétaire initialisé.");
    
    // Animation d'apparition des cartes de statistiques
    const cards = document.querySelectorAll('.card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(10px)';
        card.style.transition = 'all 0.3s ease-out';
        
        setTimeout(() => {
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, 80 * index);
    });
});
