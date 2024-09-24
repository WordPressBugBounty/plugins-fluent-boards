<?php if(!defined( 'ABSPATH' ))  exit; // if accessed directly exit ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <?php include(FLUENT_BOARDS_PLUGIN_PATH.'app/Views/emails/common-style.php'); ?>

</head>
<body>
<div class="fbs_email_notification">
    <div class="fbs_email_notification_top">
        <?php include(FLUENT_BOARDS_PLUGIN_PATH.'app/Views/emails/common-header.php'); ?>
        <div class="fbs_email_notification_contents">
            <div class="fbs_email_content">
                <div class="fbs_email_content_left">
                    <img src="<?php echo esc_url($userData['photo']);?>" alt="<?php echo esc_attr($userData['display_name']); ?>" class="fbs-avatar">
                </div>
                <div class="fbs_email_content_right">
                    <p class="fbs_user_name"><?php echo esc_html($userData['display_name']); ?></p>
                    <p class="fbs_email_details"><?php echo wp_kses_post($body); ?></p>
                    <div class="fbs_email_comment"><?php echo wp_kses_post($comment); ?></div>
                </div>
            </div>
        </div>
        <div class="fbs_email_notification_bottom">
            <?php if(!defined('FLUENT_BOARDS_PRO')): ?>
                <span class="fbs_email_footer_text"><?php esc_html_e('Powered By', 'fluent-boards'); ?>
                <strong style="color: #6268F1"><a href="https://fluentboards.com?utm_source=wp&utm_medium=wp_mail&utm_campaign=footer">Fluent Boards</a> </strong>
            </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="fbs_email_notification_footer">
        <?php $footer_text = ''; ?>
        <?php echo apply_filters('fluent_boards/email_footer', $footer_text); ?>
    </div>
</div>
</body>
</html>