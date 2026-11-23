<?php if (!defined('ABSPATH')) exit; // if accessed directly exit ?>
<div class="fbs_email_notification_head" style="padding: <?php echo empty($fluent_boards_header_inline) ? '0 4px 20px' : '0'; ?>;">
    <?php
    $fluent_boards_site_title = isset($site_title) ? (string) $site_title : get_bloginfo('name');
    $fluent_boards_site_logo  = isset($site_logo) ? $site_logo : '';

    if ($fluent_boards_site_logo) {
        $fluent_boards_email_header = '<img src="' . esc_url($fluent_boards_site_logo) . '" height="32" alt="' . esc_attr($fluent_boards_site_title) . '" style="display: block; height: 32px; width: auto; max-width: 200px;">';
    } else {
        $fluent_boards_email_header = '<span class="fbs_email_text" style="color: #0E121B; font-size: 15px; font-weight: 600; line-height: 20px;">' . esc_html($fluent_boards_site_title) . '</span>';
    }

    // Apply the filter to the email header
    $fluent_boards_email_header = apply_filters('fluent_boards/email_header', $fluent_boards_email_header);
    // Output the final email header (allow safe HTML)
    echo wp_kses_post($fluent_boards_email_header);
    ?>
</div>
