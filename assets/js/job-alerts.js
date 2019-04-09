jQuery(document).ready(function($) {

	var wpjm_alerts_select2_args = {
		minimumResultsForSearch: 10,
		width: '100%'
	};
	if ( 1 === parseInt( job_manager_alerts.is_rtl, 10 ) ) {
		wpjm_alerts_select2_args.dir = 'rtl';
	}

	if ( $.isFunction( $.fn.select2 ) ) {
		$( '.job-manager-enhanced-select:visible' ).select2( wpjm_alerts_select2_args );
	} else if ( $.isFunction( $.fn.chosen ) ) {
		$( '.job-manager-enhanced-select:visible' ).chosen();
	}

	$('.job-alerts-action-delete').click(function() {
		var answer = confirm( job_manager_alerts.i18n_confirm_delete );

		if (answer)
			return true;

		return false;
	});

});
