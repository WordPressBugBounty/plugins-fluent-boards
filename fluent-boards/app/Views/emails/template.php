<?php if (!defined('ABSPATH')) exit; // if accessed directly exit ?>
<?php
/**
 * Shared email layout: preheader, brand row, 560px card, footer.
 *
 * @var string $fluent_boards_email_content Card HTML built by the calling view
 * @var string $pre_header                  Inbox preview text (optional)
 * @var string $email_reason                Why the recipient got this email (optional)
 * @var string $card_head                   Breadcrumb HTML; when set the logo moves into a bar at the top of the card
 * @var string $settings_url                Recipient's notification preferences link (optional)
 */
$fluent_boards_has_head = !empty($card_head);
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
<table role="presentation" class="fbs_email_outer" width="100%" cellpadding="0" cellspacing="0" border="0" style="background: #F5F7FA; border-collapse: separate; border-spacing: 0;">
    <tr>
        <?php // Keep outer spacing on a cell: table padding is ignored in collapsed layouts. ?>
        <td class="fbs_email_outer_pad" align="center" style="padding: 40px 16px;">
            <table role="presentation" class="fbs_email_notification" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: separate; border-spacing: 0; max-width: 560px; width: 100%; font-family: <?php echo esc_attr($fluent_boards_font); ?>;">
                <?php if (!$fluent_boards_has_head): ?>
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
                <?php else: ?>
                    <?php // Card with a top bar: logo on the left, board and stage on the right. ?>
                    <tr>
                        <td class="fbs_email_card" style="background: #FFFFFF; border: 1px solid #E1E4EA; border-radius: 12px; padding: 0; text-align: left;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: separate; border-spacing: 0;">
                                <tr>
                                    <td class="fbs_email_border fbs_email_card_head_pad" style="padding: 18px 32px; border-bottom: 1px solid #EEF0F5;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td valign="middle" align="left" class="fbs_email_stack">
                                                    <?php $fluent_boards_header_inline = true; include(FLUENT_BOARDS_PLUGIN_PATH . 'app/Views/emails/common-header.php'); ?>
                                                </td>
                                                <td valign="middle" align="right" class="fbs_email_text2 fbs_email_crumb fbs_email_stack" style="padding-left: 12px; color: #6B7385; font-size: 13px; line-height: 20px; text-align: right;">
                                                    <?php echo wp_kses_post($card_head); ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="fbs_email_card_pad" style="padding: 30px 32px 34px; text-align: left;">
                                        <?php echo wp_kses_post($fluent_boards_email_content); ?>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td class="fbs_email_text2" style="padding: 24px 8px 0; color: #5F6573; font-size: 13px; line-height: 20px; text-align: <?php echo $fluent_boards_has_head ? 'center' : 'left'; ?>;">
                        <?php if (!empty($email_reason)): ?>
                            <p style="margin: 0 0 6px;"><?php echo esc_html($email_reason); ?></p>
                        <?php endif; ?>
                        <p style="margin: 0;">
                            <?php echo esc_html(isset($site_title) && $site_title ? $site_title : get_bloginfo('name')); ?>
                            <?php if (!empty($settings_url)): ?>
                                &middot; <a class="fbs_email_text2" href="<?php echo esc_url($settings_url); ?>" target="_blank" style="color: #5F6573; text-decoration: underline;"><?php esc_html_e('Notification settings', 'fluent-boards'); ?></a>
                            <?php endif; ?>
                        </p>

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
