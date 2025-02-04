 
<?php

class WC_CSV_Category_Handler {
    public function create_categories($category_string) {
        $categories = explode('|', $category_string);
        // Code pour créer les catégories et sous-catégories
    }

    public function delete_all_categories() {
        // Code pour supprimer toutes les catégories existantes
    }

    public function create_hierarchical_menu() {
        $terms = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ));
    
        $menu = '<ul>';
        foreach ($terms as $term) {
            // Créez un menu hiérarchique ici
            $menu .= '<li>' . $term->name . '</li>';
        }
        $menu .= '</ul>';
        return $menu;
    }
}