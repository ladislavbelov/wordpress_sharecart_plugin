jQuery(document).ready(function($) {
    // Generate share link
    $('#sharecart-generate-btn').on('click', function(e) {
        e.preventDefault();
        
        console.log('ShareCart: Generate button clicked');
        
        const name = $('#sharecart-name').val();
        const note = $('#sharecart-note').val();
        
        console.log('ShareCart: Form data:', { name, note });
        console.log('ShareCart: AJAX URL:', sharecart_params.ajax_url);
        console.log('ShareCart: Nonce:', sharecart_params.nonce);
        
        if (!name) {
            console.log('ShareCart: Name is required');
            alert(sharecart_params.i18n.name_required);
            return;
        }
        
        const formData = {
            action: 'sharecart_generate_link',
            security: sharecart_params.nonce,
            name: name,
            note: note
        };
        
        console.log('ShareCart: Sending AJAX request with data:', formData);
        
        $.ajax({
            url: sharecart_params.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                console.log('ShareCart: AJAX response:', response);
                if (response.success) {
                    $('#sharecart-link').val(response.data.url);
                    $('#sharecart-result').show();
                } else {
                    console.log('ShareCart: Error:', response.data);
                    alert(response.data);
                }
            },
            error: function(xhr, status, error) {
                console.log('ShareCart: AJAX error:', { xhr, status, error });
                console.log('ShareCart: Response text:', xhr.responseText);
                alert(sharecart_params.i18n.error);
            }
        });
    });
    
    // Copy link to clipboard
    $('#sharecart-copy-btn').on('click', function(e) {
        e.preventDefault();
        const linkInput = $('#sharecart-link');
        linkInput.select();
        document.execCommand('copy');
        
        const successMsg = $('.sharecart-success-msg');
        successMsg.show();
        setTimeout(function() {
            successMsg.hide();
        }, 2000);
    });
    
    // Add single item to cart
    $('.add-item-btn').on('click', function(e) {
        e.preventDefault();
        const product = $(this).data('product');
        addItemToCart(product);
    });
    
    // Add all items to cart
    $('#sharecart-add-all').on('click', function(e) {
        e.preventDefault();
        const cartKey = $(this).data('cart-key');
        
        $.ajax({
            url: sharecart_params.ajax_url,
            type: 'POST',
            data: {
                action: 'sharecart_add_items',
                security: sharecart_params.nonce,
                cart_key: cartKey
            },
            success: function(response) {
                if (response.success) {
                    window.location.href = response.data.cart_url;
                } else {
                    alert(response.data);
                }
            },
            error: function() {
                alert(sharecart_params.i18n.error);
            }
        });
    });
    
    function addItemToCart(product) {
        $.ajax({
            url: sharecart_params.ajax_url,
            type: 'POST',
            data: {
                action: 'sharecart_add_single_item',
                security: sharecart_params.nonce,
                product: product
            },
            success: function(response) {
                if (response.success) {
                    window.location.href = response.data.cart_url;
                } else {
                    alert(response.data);
                }
            },
            error: function() {
                alert(sharecart_params.i18n.error);
            }
        });
    }
}); 