jQuery(document).ready(function($) {
    // Toggle share popup visibility
    $('#sharecart-button').on('click', function() {
        $('#sharecart-popup').toggle();
        $('#sharecart-result').hide();
    });

    // Generate share link
    $('#sharecart-generate').on('click', function() {
        var $button = $(this);
        var $nameField = $('#sharecart-name');
        var $noteField = $('#sharecart-note');
        
        // Validate name field
        if ($nameField.val().trim() === '') {
            alert(sharecart_vars.i18n.empty_name);
            $nameField.focus();
            return;
        }

        // Show loading state
        $button.prop('disabled', true).text(sharecart_vars.i18n.generating);

        // AJAX request
        $.ajax({
            url: sharecart_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'sharecart_generate_link',
                security: sharecart_vars.nonce,
                referrer_name: $nameField.val().trim(),
                note: $noteField.val().trim()
            },
            success: function(response) {
                if (response.success) {
                    $('#sharecart-link').val(response.data.url);
                    $('#sharecart-result').show();
                    $('#sharecart-popup').css('min-height', 'auto');
                } else {
                    alert(response.data);
                }
            },
            error: function(xhr) {
                alert('Error: ' + xhr.responseText);
            },
            complete: function() {
                $button.prop('disabled', false).text(sharecart_vars.i18n.generate);
            }
        });
    });

    // Copy link to clipboard
    $(document).on('click', '#sharecart-link', function() {
        $(this).select();
        document.execCommand('copy');
        alert('Link copied to clipboard!');
    });
});