jQuery(document).ready(function($) {
    let filePath = CSV.file;
    let totalRows = 0;   
    let offset = CSV.offset;
    let maxRetries = 3;
    let retryCount = 0;

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

        let percentage = parseInt((offset / (totalRows + 1)) * 100);
        $("#import-progress").percircle({
            text: percentage+"%",
            percent: percentage,
            progressBarColor: percentage < 25 ? "#CC3366" : (percentage < 50 ? "yellow" : (percentage < 75 ? 'orange' : "green"))
          });
          $('#import-status').html('<p>Actual progress ' + offset + '/'+totalRows+'('+percentage+'%) products detected.</p>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'process_import_batch',
                file_path: filePath,
                offset: offset                
            },
            success: function(response) {
                if (response.error) {
                    $('#import-status').html('<p style="color: red;">' + response.error + '</p>');
                    return;
                }
                
                offset = response.next_offset;
                retryCount = 0;
                $('#import-progress').css('width', (offset / totalRows * 100) + '%');
                
                if(offset && !isNaN(offset) && offset < totalRows){
                    processBatch(response.insertCount, response.updateCount);   
                }else{
                    console.log('oops! bad offset provided: '+offset);
                }
            },
            error: function (xhr, textStatus, errorThrown) {
                console.error("Import Failed:", textStatus, errorThrown);
                
                if (xhr.status === 504 && retryCount < maxRetries) {
                    retryCount++;
                    console.log(`Retrying import (${retryCount}/${maxRetries})...`);
                    setTimeout(startImport, 3000); // Wait 3 seconds before retrying
                } else {
                    alert("L'importation a échoué après plusieurs tentatives.");
                }
            }
        });
    }

    function startImport() {        
        $('#start-import').trigger('click');
    }
});

