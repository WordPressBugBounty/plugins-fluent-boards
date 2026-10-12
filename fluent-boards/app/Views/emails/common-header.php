<?php if (!defined('ABSPATH')) exit; // if accessed directly exit ?>
<div class="fbs_email_notification_head" style="padding: 0 4px 20px;">
    <?php
    $fluent_boards_site_title = isset($site_title) ? (string) $site_title : get_bloginfo('name');
    $fluent_boards_site_logo  = isset($site_logo) ? $site_logo : '';

    if ($fluent_boards_site_logo) {
        $fluent_boards_email_header = '<img src="' . esc_url($fluent_boards_site_logo) . '" height="32" alt="' . esc_attr($fluent_boards_site_title) . '" style="display: block; height: 32px; width: auto; max-width: 200px;">';
    } else {
        // No logo: a solid initial tile keeps the brand row visible when clients invert colors.
        $fluent_boards_initial = function_exists('mb_substr') ? mb_substr($fluent_boards_site_title, 0, 1) : substr($fluent_boards_site_title, 0, 1);
        $fluent_boards_email_header = '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td class="fbs_email_logo_tile" width="28" height="28" align="center" style="width: 28px; height: 28px; border-radius: 7px; background: #222530; color: #FFFFFF; font-size: 14px; font-weight: 700; line-height: 28px; text-align: center;">' . esc_html(strtoupper($fluent_boards_initial)) . '</td>'
            . '<td class="fbs_email_text" style="padding-left: 10px; color: #0E121B; font-size: 15px; font-weight: 600; line-height: 20px;">' . esc_html($fluent_boards_site_title) . '</td>'
            . '</tr></table>';
    }

    // Apply the filter to the email header
    $fluent_boards_email_header = apply_filters('fluent_boards/email_header', $fluent_boards_email_header);
    // Output the final email header (allow safe HTML)
    echo wp_kses_post($fluent_boards_email_header);
    ?>
</div>
