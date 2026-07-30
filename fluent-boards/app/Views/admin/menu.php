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
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">
                                        <path d="M13.0582 8.01999L6.49492 14.5833L5.41667 13.505L11.9792 6.94173H6.19524V5.41663H14.5833V13.8047H13.0582V8.01999Z" fill="#99A0AE"/>
                                    </svg>
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
                <?php
                if (!defined('FLUENT_BOARDS_PRO')) {
                    ?>
                    <a
                        href="<?php echo esc_url(fluent_boards_get_upgrade_url('top_bar')); ?>"
                        class="el-button el-button--primary no-underline fbs_menu_action__upgrade">
                        <span>
                            <?php esc_html_e('Upgrade to Pro', 'fluent-boards'); ?>
                        </span>
                    </a>
                <?php
                }
                ?>
                <?php do_action('fluent_boards/in_menu_actions'); ?>
            </div>
        </div>
        <div class="fframe_menu_overlay" data-fbs-menu-overlay></div>
        <ul class="fframe_menu fframe_menu_small_screen" id="fframe_mobile_menu"
            aria-label="<?php esc_attr_e('Navigation Menu', 'fluent-boards'); ?>">
            <li class="fframe_menu_close_item">
                <button type="button" class="fframe_offcanvas_close" data-fbs-menu-close
                        aria-label="<?php esc_attr_e('Close Navigation Menu', 'fluent-boards'); ?>">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                        <path d="M9.99956 8.93955L13.7121 5.22705L14.7726 6.28755L11.0601 10.0001L14.7726 13.7126L13.7121 14.7731L9.99956 11.0606L6.28706 14.7731L5.22656 13.7126L8.93906 10.0001L5.22656 6.28755L6.28706 5.22705L9.99956 8.93955Z" fill="currentColor"/>
                    </svg>
                </button>
            </li>
            <?php foreach ($menuItems as $fluent_boards_item) { ?>
                <?php $fluent_boards_has_submenu = !empty($fluent_boards_item['sub_items']); ?>
                <li data-key="<?php echo esc_attr($fluent_boards_item['key']); ?>"
                    class="fframe_menu_item <?php echo ($fluent_boards_has_submenu) ? 'fframe_has_sub_items' : ''; ?> fframe_item_<?php echo esc_attr($fluent_boards_item['key']); ?> <?php echo esc_attr($fluent_boards_item['class'] ?? '') ?? ''; ?>">
                    <a class="fframe_menu_primary"
                       <?php if (!empty($fluent_boards_item['target'])) : ?>target="_blank" rel="noopener" <?php endif; ?>
                       href="<?php echo esc_url($fluent_boards_item['permalink']); ?>">
                        <?php echo esc_html(sanitize_text_field($fluent_boards_item['label'])); ?>
                        <?php if(!empty($fluent_boards_item['target'])): ?>
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">
                                <path d="M13.0582 8.01999L6.49492 14.5833L5.41667 13.505L11.9792 6.94173H6.19524V5.41663H14.5833V13.8047H13.0582V8.01999Z" fill="#99A0AE"/>
                            </svg>
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
            <?php if (!defined('FLUENT_BOARDS_PRO')) { ?>
                <li data-key="get_pro" class="fframe_menu_item fframe_item_get_pro">
                    <a class="fframe_menu_primary"
                       href="<?php echo esc_url(fluent_boards_get_upgrade_url('sidebar_cta')); ?>">
                        <?php esc_html_e('Upgrade to Pro', 'fluent-boards'); ?>
                    </a>
                </li>
            <?php } ?>
        </ul>
        <div class="fframe_body">
            <div id="fluent-framework-app" class="fs_route_wrapper"></div>
        </div>
    </div>
</div>
