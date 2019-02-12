jQuery(document).ready(function($) {

	$('.job-manager-chosen-select').chosen();

	$('.job-alerts-action-delete').click(function() {
		var answer = confirm( job_manager_alerts.i18n_confirm_delete );

		if (answer)
			return true;

		return false;
	});

});