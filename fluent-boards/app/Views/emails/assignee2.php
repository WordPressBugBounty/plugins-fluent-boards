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
</div>


<!--end your code here -->
<?php
$fluent_boards_email_content = ob_get_clean();
include 'template.php';