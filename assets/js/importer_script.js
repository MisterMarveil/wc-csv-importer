jQuery(document).ready(function($) {
    let filePath = '';
    let totalRows = 0;   
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

    function processBatch(insertCount = 0, updateCount = 0) {
        if (offset >= totalRows) {
            $("#import-progress").addClass('hidden');
            $('#import-status').html('<p style="color: green;">Import completed successfully!</p>');
            return;
        }

        let percentage = parseInt((offset / batchSize + 1) * 100);
        $("#import-progress").percircle({
            text: offset+"/"+(batchSize + 1)+"%",
            percent: percentage,
            progressBarColor: percentage < 25 ? "#CC3366" : (percentage < 50 ? "yellow" : (percentage < 75 ? 'orange' : "green"))
          });
        
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
                
                offset = response.next_offset;
                $('#import-progress').css('width', (offset / totalRows * 100) + '%');
                processBatch(response.insertCount, response.updateCount);
            }
        });
    }
});

