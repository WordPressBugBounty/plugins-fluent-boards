<?php if (!defined('ABSPATH')) exit; // if accessed directly exit ?>
<div id="<?php echo esc_attr(sanitize_text_field($slug)); ?>-app"
     class="warp fconnector_app">
    <div class="fframe_app">
        <div class="fframe_main-menu-items">
            <div class="fframe_header_left">
                <div class="menu_logo_holder">
                    <a href="<?php echo esc_url($baseUrl); ?>">
                        <img style="height: 30px;" src="<?php echo esc_url($icon); ?>"/>
                        <?php if(defined('FLUENT_BOARDS_PRO') && is_admin()): ?>
                            <span><?php esc_html_e('Pro', 'fluent-boards'); ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="fframe_handheld"><span class="dashicons dashicons-menu-alt3"></span></div>
                <ul class="fframe_menu">
                    <?php foreach ($menuItems as $item) { ?>
                        <?php $hasSubMenu = !empty($item['sub_items']); ?>
                        <li data-key="<?php echo esc_attr($item['key']); ?>"
                            class="fframe_menu_item <?php echo ($hasSubMenu) ? 'fframe_has_sub_items' : ''; ?> fframe_item_<?php echo esc_attr($item['key']); ?> <?php echo esc_attr($item['class'] ?? '') ?? ''; ?>">
                            <a class="fframe_menu_primary"
                               <?php if (!empty($item['target'])) : ?>target="_blank" rel="noopener" <?php endif; ?>
                               href="<?php echo esc_url($item['permalink']); ?>">
                                <?php echo esc_html(sanitize_text_field($item['label'])); ?>
                                <?php if(!empty($item['target'])): ?>
                                    <i class="el-icon" data-v-6fbb019e="" style="font-size: 12px;"><svg width="12px" height="12px" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024" data-v-6fbb019e=""><path fill="currentColor" d="M768 256H353.6a32 32 0 1 1 0-64H800a32 32 0 0 1 32 32v448a32 32 0 0 1-64 0z"></path><path fill="currentColor" d="M777.344 201.344a32 32 0 0 1 45.312 45.312l-544 544a32 32 0 0 1-45.312-45.312l544-544z"></path></svg></i>
                                <?php endif; ?>
                                <?php if ($hasSubMenu) { ?>
                                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                                <?php } ?></a>
                            <?php if ($hasSubMenu) { ?>
                                <div class="fframe_submenu_items">
                                    <?php foreach ($item['sub_items'] as $sub_item) { ?>
                                        <a href="<?php echo esc_url($sub_item['permalink']); ?>"><?php echo esc_attr($sub_item['label']); ?></a>
                                    <?php } ?>
                                </div>
                            <?php } ?>
                        </li>
                    <?php } ?>
                </ul>
            </div>
            <div class="fbs_menu_action">
                <div class="fbs_menu_actions" id="fluent_app_actions"></div>
                <?php do_action('fluent_boards/in_menu_actions'); ?>
            </div>
        </div>
        <div class="fframe_body">
            <div id="fluent-framework-app" class="fs_route_wrapper"></div>
        </div>
    </div>
</div>
