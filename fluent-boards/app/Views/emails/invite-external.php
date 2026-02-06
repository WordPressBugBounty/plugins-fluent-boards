<?php
ob_start(); // Start output buffering
?>

    <!--Start you code here -->
    <div class="fbs_email_content_left">
        <img src="<?php echo esc_url($userData['photo']);?>" alt="<?php echo esc_attr($userData['display_name']); ?>" class="fbs-avatar">
    </div>
    <div class="fbs_email_content_right">
        <p class="fbs_user_name"><?php echo esc_html($userData['display_name']); ?></p>
        <p class="fbs_email_details"><?php echo wp_kses_post($body); ?></p>
        <div class="fbs_invitation_button">
            <a style="background: none; text-decoration: none; color: #FFF" target="_blank" href="<?php echo esc_url($boardLink); ?>"><?php echo esc_html($btn_title); ?></a>
        </div>
        <p class="fbs_email_details fbs_invite_error">
            <?php
            // translators: %s is the button title
            printf(esc_html__("If you're having trouble clicking the \"%s\" button, copy and paste the URL below into your web browser:", 'fluent-boards'), esc_html($btn_title));
            ?>
        </p>
        <p class="fbs_email_details"><?php echo esc_url($boardLink); ?></p>
    </div>


    <!--end your code here -->
<?php
$fluent_boards_email_content = ob_get_clean();
include 'template.php';