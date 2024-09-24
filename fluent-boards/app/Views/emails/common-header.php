<div class="fbs_email_notification_head">
    <?php
    $email_header = '';

    if ($site_logo) {
        $email_header .= '<img src="' . esc_url($site_logo) . '" width="100px" alt="' . esc_attr($site_title) . '">';
    } else {
        $email_header .= '<div class="fbs_head_text">' . esc_html($site_title) . '</div>';
    }

    // Apply the filter to the email header
    $email_header = apply_filters('fluent_boards/email_header', $email_header);
    // Output the final email header
    echo $email_header;
    ?>
</div>