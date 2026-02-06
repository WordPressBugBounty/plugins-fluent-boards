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
                <div class="fframe_handheld">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
                <ul class="fframe_menu">
                    <?php foreach ($menuItems as $fluent_boards_item) { ?>
                        <?php $fluent_boards_has_submenu = !empty($fluent_boards_item['sub_items']); ?>
                        <li data-key="<?php echo esc_attr($fluent_boards_item['key']); ?>"
                            class="fframe_menu_item <?php echo ($fluent_boards_has_submenu) ? 'fframe_has_sub_items' : ''; ?> fframe_item_<?php echo esc_attr($fluent_boards_item['key']); ?> <?php echo esc_attr($fluent_boards_item['class'] ?? '') ?? ''; ?>">
                            <a class="fframe_menu_primary"
                               <?php if (!empty($fluent_boards_item['target'])) : ?>target="_blank" rel="noopener" <?php endif; ?>
                               href="<?php echo esc_url($fluent_boards_item['permalink']); ?>">
                                <?php echo esc_html(sanitize_text_field($fluent_boards_item['label'])); ?>
                                <?php if(!empty($fluent_boards_item['target'])): ?>
                                    <i class="el-icon" data-v-6fbb019e="" style="font-size: 12px;"><svg width="12px" height="12px" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024" data-v-6fbb019e=""><path fill="currentColor" d="M768 256H353.6a32 32 0 1 1 0-64H800a32 32 0 0 1 32 32v448a32 32 0 0 1-64 0z"></path><path fill="currentColor" d="M777.344 201.344a32 32 0 0 1 45.312 45.312l-544 544a32 32 0 0 1-45.312-45.312l544-544z"></path></svg></i>
                                <?php endif; ?>
                                <?php if ($fluent_boards_has_submenu) { ?>
                                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                                <?php } ?></a>
                            <?php if ($fluent_boards_has_submenu) { ?>
                                <div class="fframe_submenu_items">
                                    <?php foreach ($fluent_boards_item['sub_items'] as $fluent_boards_sub_item) { ?>
                                        <a href="<?php echo esc_url($fluent_boards_sub_item['permalink']); ?>"><?php echo esc_attr($fluent_boards_sub_item['label']); ?></a>
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
        <ul class="fframe_menu fframe_menu_small_screen">
            <?php foreach ($menuItems as $fluent_boards_item) { ?>
                <?php $fluent_boards_has_submenu = !empty($fluent_boards_item['sub_items']); ?>
                <li data-key="<?php echo esc_attr($fluent_boards_item['key']); ?>"
                    class="fframe_menu_item <?php echo ($fluent_boards_has_submenu) ? 'fframe_has_sub_items' : ''; ?> fframe_item_<?php echo esc_attr($fluent_boards_item['key']); ?> <?php echo esc_attr($fluent_boards_item['class'] ?? '') ?? ''; ?>">
                    <a class="fframe_menu_primary"
                       <?php if (!empty($fluent_boards_item['target'])) : ?>target="_blank" rel="noopener" <?php endif; ?>
                       href="<?php echo esc_url($fluent_boards_item['permalink']); ?>">
                        <?php echo esc_html(sanitize_text_field($fluent_boards_item['label'])); ?>
                        <?php if(!empty($fluent_boards_item['target'])): ?>
                            <i class="el-icon" data-v-6fbb019e="" style="font-size: 12px;"><svg width="12px" height="12px" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024" data-v-6fbb019e=""><path fill="currentColor" d="M768 256H353.6a32 32 0 1 1 0-64H800a32 32 0 0 1 32 32v448a32 32 0 0 1-64 0z"></path><path fill="currentColor" d="M777.344 201.344a32 32 0 0 1 45.312 45.312l-544 544a32 32 0 0 1-45.312-45.312l544-544z"></path></svg></i>
                        <?php endif; ?>
                        <?php if ($fluent_boards_has_submenu) { ?>
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        <?php } ?></a>
                    <?php if ($fluent_boards_has_submenu) { ?>
                        <div class="fframe_submenu_items">
                            <?php foreach ($fluent_boards_item['sub_items'] as $fluent_boards_sub_item) { ?>
                                <a href="<?php echo esc_url($fluent_boards_sub_item['permalink']); ?>"><?php echo esc_attr($fluent_boards_sub_item['label']); ?></a>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </li>
            <?php } ?>
        </ul>
        <div class="fframe_body">
            <div id="fluent-framework-app" class="fs_route_wrapper"></div>
        </div>
    </div>
</div>
