<div class="wrap">
    <h1>Importer les produits depuis CSV</h1>
    <form method="post" action="">
        <input type="text" name="csv_url" placeholder="Entrez l'URL du fichier CSV" required />
        <input type="submit" name="import_csv" value="Importer" class="button button-primary" />
    </form>
    <button id="bulk_import" class="button button-danger">Vider et importer</button>

    <script src="<?php echo plugin_dir_url(__FILE__) . '../assets/js/admin.js'; ?>"></script>
</div>