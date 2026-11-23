<?php if (!defined('ABSPATH')) exit; // if accessed directly exit ?>
    <div id="<?php echo esc_attr(sanitize_text_field($slug)); ?>-app"
         class="warp fconnector_app">
        <div class="fframe_app">
            <div class="fframe_body">
                <div id="fluent-framework-app" class="fs_route_wrapper"></div>
            </div>
        </div>
    </div>
<?php
