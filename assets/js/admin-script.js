/**
 * Admin scripts for MyWorks QB Cost Adjuster
 */
(function($) {
    'use strict';
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        // Accordion functionality for detailed logs
        $('.accordion-section-title').on('click', function() {
            var section = $(this).closest('.accordion-section');
            var content = section.find('.accordion-section-content');
            
            if (content.is(':visible')) {
                content.slideUp();
                $(this).find('.accordion-section-arrow').removeClass('open');
            } else {
                content.slideDown();
                $(this).find('.accordion-section-arrow').addClass('open');
            }
        });
        
        // Filter form submission
        $('#filter-form').on('submit', function() {
            // Add any custom filter logic here if needed
            return true;
        });
        
        // Confirmation for clearing logs
        $('.clear-logs-button').on('click', function(e) {
            if (!confirm('Are you sure you want to clear all logs? This action cannot be undone.')) {
                e.preventDefault();
                return false;
            }
            return true;
        });
        
        // Tabs functionality (if not using WordPress built-in tabs)
        $('.nav-tab').on('click', function() {
            var tabId = $(this).attr('href').replace('#', '');
            
            // Update active tab
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            
            // Show selected tab content
            $('.tab-content > div').hide();
            $('#' + tabId).show();
            
            return false;
        });
        
        // Highlight cost fields in log data
        $('.highlight-costs').each(function() {
            var text = $(this).html();
            var costFields = ['PurchaseCost', 'UnitPrice', 'qb_p_cost', '_wc_cog_cost', '_product_cost'];
            
            // Highlight each cost field
            costFields.forEach(function(field) {
                var fieldPattern = new RegExp('(' + field + '(?:["\':]\\s*[\\d\\.]+)?)', 'g');
                text = text.replace(fieldPattern, '<span class="cost-field">$1</span>');
            });
            
            $(this).html(text);
        });
        
        // Copy to clipboard functionality
        $('.copy-to-clipboard').on('click', function() {
            var textToCopy = $($(this).data('target')).text();
            
            navigator.clipboard.writeText(textToCopy).then(function() {
                // Show success message
                alert('Copied to clipboard!');
            }, function() {
                // Show error message
                alert('Failed to copy to clipboard.');
            });
        });
    });
    
})(jQuery); 