/**
 * Admin Reports Scripts
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Initialize reports functionality.
     */
    function init() {
        bindExportButton();
        bindPeriodFilter();
    }

    /**
     * Bind export button click handler.
     */
    function bindExportButton() {
        $('.sd-export-btn').on('click', function(e) {
            e.preventDefault();

            var $btn = $(this);
            var tab = $btn.data('tab') || 'donations';
            var period = $('#sd-period-filter').val() || 'month';
            var campaignId = parseInt($('#sd-campaign-filter').val(), 10) || 0;

            var params = {
                action: 'sd_export_report',
                report: tab,
                period: period,
                _wpnonce: sdReports.nonce
            };
            // Only include campaign_id when the filter is set so the
            // exported filename stays clean (no &campaign_id=0).
            if (campaignId > 0) {
                params.campaign_id = campaignId;
            }

            window.location.href = sdReports.ajaxUrl + '?' + $.param(params);
        });
    }

    /**
     * Bind period filter auto-submit.
     */
    function bindPeriodFilter() {
        $('#sd-period-filter').on('change', function() {
            $(this).closest('form').submit();
        });
    }

    // Initialize when document is ready.
    $(document).ready(init);

})(jQuery);
