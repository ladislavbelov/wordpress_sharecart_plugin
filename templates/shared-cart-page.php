<?php
/**
 * Template for displaying shared cart page
 */
get_header(); ?>

<div class="shared-cart-container">
    <div class="shared-cart-title">
        <h1><?php echo sprintf(__('Cart shared by %s', 'sharecart'), esc_html($shared_cart->referrer_name)); ?></h1>
        <?php if ($shared_cart->note) : ?>
            <p class="shared-cart-note"><?php echo esc_html($shared_cart->note); ?></p>
        <?php endif; ?>
    </div>
    
    <div class="shared-cart-items">
        <?php 
        $items = json_decode($shared_cart->cart_data, true);
        foreach ($items as $item) : 
            $product = wc_get_product($item['product_id']);
            if (!$product) continue;
        ?>
            <div class="shared-cart-item">
                <div class="shared-cart-item-image">
                    <?php echo $product->get_image(); ?>
                </div>
                <div class="shared-cart-item-info">
                    <h3 class="shared-cart-item-name"><?php echo esc_html($product->get_name()); ?></h3>
                    <div class="shared-cart-item-price">
                        <?php echo $product->get_price_html(); ?>
                    </div>
                    <div class="shared-cart-item-quantity">
                        <strong><?php _e('Quantity:', 'sharecart'); ?></strong> <?php echo $item['quantity']; ?>
                    </div>
                    <div class="shared-cart-item-actions">
                        <button class="button add-item-btn" 
                                data-product='<?php echo json_encode($item); ?>'>
                            <?php _e('Add to my cart', 'sharecart'); ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <div class="shared-cart-actions">
        <button id="sharecart-add-all" class="button" 
                data-cart-key="<?php echo esc_attr($shared_cart->cart_key); ?>">
            <?php _e('Add all items to cart', 'sharecart'); ?>
        </button>
        <a href="<?php echo wc_get_cart_url(); ?>" class="button">
            <?php _e('View my cart', 'sharecart'); ?>
        </a>
    </div>
</div>

<?php get_footer(); ?>