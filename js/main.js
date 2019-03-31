// =========================================================
// getFeed - gets and cleans data from vendor
// returns JSON
// =========================================================
function getFeed() {

	// init vars
	var parameters = '?type=json';
	
    // show the loading icon, hide previous errors
    showError();
    showLoading('Scanning...');

    // build parameters
	//parameters += $("#edu-form").serialize();

	// hide existing report (if any)
	$('#results').fadeOut();

	// COURSE DATA
	// dig the courses to be added to the selection list
	$.get('feeds/'+$("#term-feed").val()+'.php'+parameters, 

	  	function(json_data){
			// check for errors, stop processing and display error if found.
			if (json_data.error) {
				showLoading();
				showError(json_data.data);
				return;
			}

			// Summary
			$('#summary-text').html('Annihilated scum from <b>'+json_data.info.count+'</b> products from' + $('#summary-text').val() + '</b> in <b>' + json_data.info.executiontime + '</b> seconds!');

			// Details
			$('#detail-table').html(json_data.data);

			// clear loading and show summary
			showLoading();
			$('#summary').fadeIn();
			$('#detail').fadeIn();

		}, "json")
		
		// handle any HTTP errors
		.fail(function(XMLHttpRequest){
			showLoading();
			console.log(XMLHttpRequest);
			showError(XMLHttpRequest.status + ' ' + XMLHttpRequest.statusText);
		});
}

// =========================================================
// showLoading - shows or hides a loading animation
// =========================================================
function showLoading(msg) {
	msg = msg || null;
	
	if (msg == null) {
		// hide loading message
		$('#form_button').html('Scan For Data Feed &raquo;');
		$('#form_button').removeAttr('disabled');
	}else{
		// show loading message
		$('#form_button').attr('disabled','disabled');
		$('#form_button').html('<img src="img/spinner-mini-white.gif" /> ' + msg);
	}
}

// =========================================================
// Show error - shows or hides errors
// =========================================================
function showError(msg) {

	msg = msg || null;

	if (msg == null) {
		// hide error
		$('#error').fadeOut;
		$('#error').html('');
	}else{
		// show error
		$('#error').html('<hr class="noprint" /><div class="alert alert-danger alert-dismissable"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button><strong>Error!</strong> '+ msg +'</div>');
		$('#error').fadeIn();
	}
}

// =========================================================
// Document Ready
// =========================================================
$(function() {

});
