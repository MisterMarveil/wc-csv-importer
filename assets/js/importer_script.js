jQuery(document).ready(function($) {
    let filePath = '';
    let totalRows = 0;
    let insertCount = 0;
    let updateCount = 0;
    let offset = 80;
    let batchSize = 10;
    $("#import-progress").percircle();
    
    $('#start-import').click(function() {
        $('#import-status').html('<p>Initializing import...</p>');
        //alert(ajaxurl);
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'initialize_import',
                csv_url: $("#csv_url").val()
            },
            success: function(response) {
                if (response.error) {
                    $('#import-status').html('<p style="color: red;">' + response.error + '</p>');
                    return;
                }
                filePath = response.file_path;
                totalRows = response.total_rows;

                $('#import-status').html('<p>File loaded: ' + totalRows + ' products detected.</p>');
                $("#import-progress").removeClass('hidden');
                processBatch();
            }
        });
    });

    function processBatch() {
        if (offset >= totalRows) {
            $("#import-progress").addClass('hidden');
            $('#import-status').html('<p style="color: green;">Import completed successfully!</p>');
            return;
        }

        $("#import-progress").attr('data-text', offset+"/"+(batchSize + 1));
        $("#import-progress").attr('data-percent', parseInt((offset / batchSize + 1) * 100));
        $('#import-status').html('<p>Processing batch ' + (offset / batchSize + 1) + '...</p>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'process_import_batch',
                file_path: filePath,
                offset: offset,
                insert_count: insertCount,
                update_count: updateCount
            },
            success: function(response) {
                if (response.error) {
                    $('#import-status').html('<p style="color: red;">' + response.error + '</p>');
                    return;
                }
                
                insertCount += parseInt(response.insert_count);
                updateCount += parseInt(response.update_count);
                offset = response.next_offset;
                $('#import-progress').css('width', (offset / totalRows * 100) + '%');
                processBatch();
            }
        });
    }
});

