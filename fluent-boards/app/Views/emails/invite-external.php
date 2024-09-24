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
            <a style="background: none; text-decoration: none; color: #FFF" target="_blank" href=" <?php echo $boardLink ?> "> <?php echo $btn_title ?> </a>
        </div>
        <p class="fbs_email_details fbs_invite_error">If you’re having trouble clicking the "<?php echo $btn_title ?>" button, copy and paste the URL below into your web browser:</p>
        <p class="fbs_email_details"><?php echo $boardLink ?></p>
    </div>


    <!--end your code here -->
<?php
$content = ob_get_clean(); // Get the content and clean the buffer
// Include the template and pass the content
include 'template.php';