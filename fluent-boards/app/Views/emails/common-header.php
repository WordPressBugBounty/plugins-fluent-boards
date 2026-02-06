<div class="fbs_email_notification_head">
    <?php
    $fluent_boards_email_header = '';

    if ($site_logo) {
        $fluent_boards_email_header .= '<img src="' . esc_url($site_logo) . '" width="100px" alt="' . esc_attr($site_title) . '">';
    } else {
        $fluent_boards_email_header .= '<div class="fbs_head_text">' . esc_html($site_title) . '</div>';
    }

    // Apply the filter to the email header
    $fluent_boards_email_header = apply_filters('fluent_boards/email_header', $fluent_boards_email_header);
    // Output the final email header (allow safe HTML)
    echo wp_kses_post($fluent_boards_email_header);
    ?>
</div>