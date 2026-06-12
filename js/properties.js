/**
 * LokaGest - Dynamisation de la création de propriétés
 */

document.addEventListener('DOMContentLoaded', () => {
    const photoInput = document.getElementById('photo');
    
    if (photoInput) {
        photoInput.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                // Créer une zone de prévisualisation temporaire
                let preview = document.getElementById('photo-preview');
                if (!preview) {
                    preview = document.createElement('img');
                    preview.id = 'photo-preview';
                    preview.className = 'img-thumbnail mt-2 rounded-3 w-100 object-fit-cover';
                    preview.style.height = '150px';
                    photoInput.parentNode.appendChild(preview);
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        });
    }
});
