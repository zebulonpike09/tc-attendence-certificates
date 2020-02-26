jQuery(document).ready(function($) {
	function getParameterByName(name, url) {
		if (!url) url = window.location.href;
		name = name.replace(/[\[\]]/g, '\\$&');
		var regex = new RegExp('[?&]' + name + '(=([^&#]*)|&|#|$)'),
			results = regex.exec(url);
		if (!results) return null;
		if (!results[2]) return '';
		return decodeURIComponent(results[2].replace(/\+/g, ' '));
	}
	
	var postId = getParameterByName('post');
	
	jQuery('#tcac-send-emails-btn').click(function(){
		var data = {
			'action': 'tcac_email_certs',
			'nonce': ajax_object.nonce,
			'event_id': postId    // We pass php values differently!
		};
		// We can also pass the url value separately from ajaxurl for front end AJAX implementations
		jQuery.post(ajax_object.ajax_url, data, function(response) {
			console.log('Got this from the server: ' + response);
		});
	});
});