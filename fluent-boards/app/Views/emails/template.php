<?php if (!defined('ABSPATH')) exit; // if accessed directly exit ?>
<?php
/**
 * Shared email layout: preheader, brand row, 560px card, footer.
 *
 * @var string $fluent_boards_email_content Card HTML built by the calling view
 * @var string $pre_header                  Inbox preview text (optional)
 * @var string $email_reason                Why the recipient got this email (optional)
 */
$fluent_boards_font = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif";
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">

    <?php include(FLUENT_BOARDS_PLUGIN_PATH . 'app/Views/emails/common-style.php'); ?>

</head>
<body class="fbs_email_body" style="margin: 0; padding: 0; background: #F5F7FA; font-family: <?php echo esc_attr($fluent_boards_font); ?>;">
<?php if (!empty($pre_header)): ?>
    <div style="display: none; max-height: 0; max-width: 0; overflow: hidden; opacity: 0; mso-hide: all; font-size: 1px; line-height: 1px; color: #F5F7FA;"><?php echo esc_html($pre_header); ?>&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;</div>
<?php endif; ?>
<table role="presentation" class="fbs_email_outer" width="100%" cellpadding="0" cellspacing="0" border="0" style="background: #F5F7FA; padding: 40px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" class="fbs_email_notification" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 560px; width: 100%; font-family: <?php echo esc_attr($fluent_boards_font); ?>;">
                <tr>
                    <td align="left">
                        <?php include(FLUENT_BOARDS_PLUGIN_PATH . 'app/Views/emails/common-header.php'); ?>
                    </td>
                </tr>
                <tr>
                    <td class="fbs_email_card fbs_email_card_pad" style="background: #FFFFFF; border: 1px solid #E1E4EA; border-radius: 12px; padding: 32px; text-align: left;">
                        <?php echo wp_kses_post(isset($fluent_boards_email_content) ? $fluent_boards_email_content : $content); ?>
                    </td>
                </tr>
                <tr>
                    <td class="fbs_email_text2" style="padding: 24px 8px 0; color: #5F6573; font-size: 13px; line-height: 20px; text-align: left;">
                        <?php if (!empty($email_reason)): ?>
                            <p style="margin: 0 0 6px;"><?php echo esc_html($email_reason); ?></p>
                        <?php endif; ?>
                        <p style="margin: 0;"><?php echo esc_html(isset($site_title) && $site_title ? $site_title : get_bloginfo('name')); ?></p>

                        <?php $fluent_boards_footer_text = ''; ?>
                        <?php $fluent_boards_footer_text = apply_filters('fluent_boards/email_footer', $fluent_boards_footer_text); ?>
                        <?php if ($fluent_boards_footer_text): ?>
                            <div class="fbs_email_notification_footer" style="margin-top: 6px;"><?php echo wp_kses_post($fluent_boards_footer_text); ?></div>
                        <?php endif; ?>

                        <?php if (!defined('FLUENT_BOARDS_PRO')): ?>
                            <p class="fbs_email_border" style="margin: 12px 0 0; padding-top: 12px; border-top: 1px solid #E1E4EA;">
                                <?php esc_html_e('Powered by', 'fluent-boards'); ?>
                                <a class="fbs_email_text" href="https://fluentboards.com?utm_source=wp&amp;utm_medium=wp_mail&amp;utm_campaign=footer" style="color: #0E121B; font-weight: 600; text-decoration: none;">Fluent Boards</a>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
