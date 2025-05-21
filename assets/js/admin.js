jQuery(document).ready(function($) {
    console.log('QB Cost Adjuster - Admin script loaded');
    
    // Force update all products
    var $button = $('#mwqbca-force-update-all');
    console.log('Force update button found:', $button.length > 0);
    
    if ($button.length) {
        console.log('Button HTML:', $button.prop('outerHTML'));
        console.log('Button disabled state:', $button.prop('disabled'));
        
        $button.on('click', function(e) {
            e.preventDefault();
            console.log('Force update button clicked');
            
            var $spinner = $button.find('.spinner');
            var $result = $('#mwqbca-force-update-result');
            var $progress = $('#mwqbca-force-update-progress');
            var $progressBar = $progress.find('.progress-bar-fill');
            var $progressText = $progress.find('.progress-text');
            var $log = $('#mwqbca-force-update-log');
            var $logEntries = $log.find('.log-entries');
            
            console.log('Progress section found:', $progress.length > 0);
            console.log('Log section found:', $log.length > 0);
            console.log('Spinner found:', $spinner.length > 0);
            
            // Disable button and show spinner
            $button.prop('disabled', true);
            $spinner.show();
            $result.html('');
            
            // Reset progress elements
            $progressBar.css('width', '0%');
            $progressText.text('0%');
            $logEntries.empty();
            $progress.show();
            $log.show();
            
            console.log('AJAX URL:', mwqbcaAdmin.ajaxUrl);
            console.log('Nonce:', mwqbcaAdmin.nonce);
            
            // Make AJAX request to start the process
            $.ajax({
                url: mwqbcaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mwqbca_force_update_all',
                    nonce: mwqbcaAdmin.nonce
                },
                success: function(response) {
                    console.log('Force update response:', response);
                    
                    if (response.success) {
                        $result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                        
                        // Save initial data for progress tracking
                        if (response.data && response.data.total) {
                            window.mwqbcaTotalProducts = response.data.total;
                            console.log('Total products to process:', window.mwqbcaTotalProducts);
                        }
                        
                        // Start polling for progress updates
                        pollProgress();
                    } else {
                        $button.prop('disabled', false);
                        $spinner.hide();
                        $result.html('<div class="notice notice-error"><p>' + 
                            (response.data ? response.data.message : mwqbcaAdmin.i18n.error) + 
                            '</p></div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.log('Force update error:', {xhr: xhr, status: status, error: error});
                    $button.prop('disabled', false);
                    $spinner.hide();
                    $result.html('<div class="notice notice-error"><p>' + 
                        mwqbcaAdmin.i18n.error + 
                        (xhr.responseText ? ': ' + xhr.responseText : '') + 
                        '</p></div>');
                }
            });
            
            function pollProgress() {
                $.ajax({
                    url: mwqbcaAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'mwqbca_get_progress',
                        nonce: mwqbcaAdmin.nonce
                    },
                    success: function(response) {
                        console.log('Progress response:', response);
                        
                        if (response.success && response.data) {
                            var data = response.data;
                            console.log('Progress data:', data);
                            
                            // Update progress bar if we have valid data
                            if (data.total > 0 && data.processed >= 0) {
                                var percent = Math.round((data.processed / data.total) * 100);
                                console.log('Calculated progress:', data.processed, '/', data.total, '=', percent + '%');
                                
                                $progressBar.css('width', percent + '%');
                                $progressText.text(percent + '% (' + data.processed + '/' + data.total + ')');
                            } else {
                                console.log('Invalid progress data - total:', data.total, 'processed:', data.processed);
                            }
                            
                            // Update log entries
                            if (data.recent_logs && data.recent_logs.length) {
                                $logEntries.empty();
                                $.each(data.recent_logs, function(index, log) {
                                    var logClass = log.status === 'success' ? 'log-success' : 'log-error';
                                    $logEntries.append(
                                        '<div class="log-entry ' + logClass + '">' +
                                        '<span class="log-time">[' + log.timestamp + ']</span> ' +
                                        '<span class="log-product">' + log.product_name + ':</span> ' +
                                        '<span class="log-message">' + log.message + '</span>' +
                                        '</div>'
                                    );
                                });
                            }
                            
                            // Check if process is complete
                            if (data.complete) {
                                // Process complete, enable button
                                $button.prop('disabled', false);
                                $spinner.hide();
                                
                                // Final summary
                                var summaryClass = data.failed > 0 ? 'notice-warning' : 'notice-success';
                                $result.html('<div class="notice ' + summaryClass + '"><p>' + 
                                    'Completed! Total processed: ' + data.processed + 
                                    ', Success: ' + data.success + 
                                    ', Failed: ' + data.failed + 
                                    '</p></div>');
                            } else {
                                // Process still running, poll again after a delay (reduced from 1000ms to 500ms)
                                setTimeout(pollProgress, 500);
                            }
                        } else {
                            // Error with progress data, poll again after a delay
                            console.log('Error with progress data response:', response);
                            setTimeout(pollProgress, 500);
                        }
                    },
                    error: function(xhr, status, error) {
                        // Error fetching progress, retry after delay
                        console.log('Progress check error:', {xhr: xhr, status: status, error: error});
                        setTimeout(pollProgress, 1000);
                    }
                });
            }
        });
    }
}); 