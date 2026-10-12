<?php if (!defined('ABSPATH')) exit; // if accessed directly exit ?>
<?php
/**
 * Shared card body for event notifications. Every block is optional; senders pass only
 * what the event has. Output is buffered into $fluent_boards_email_content for template.php.
 *
 * @var array  $badge          ['label' => string, 'tone' => accent|neutral|warn|ok]
 * @var string $heading        Plain-text headline
 * @var string $context        HTML line under the headline (task / board links)
 * @var array  $userData       ['display_name', 'photo'] of the actor, used for comment blocks
 * @var string $comment        Comment HTML
 * @var string $comment_time   Human readable comment time
 * @var string $parent_comment HTML of the comment being replied to
 * @var array  $task_card      ['title', 'url', 'parent_title', 'parent_url', 'rows' => [[label, html]], 'muted', 'description']
 * @var array  $change         ['from' => string, 'to' => string, 'strike' => bool]
 * @var string $note           Plain-text note under the content
 * @var array  $cta            ['label', 'url', 'style' => primary|secondary]
 * @var string $fallback_url   Raw URL printed under the button for clients that block it
 * @var string $body           Legacy HTML sentence, rendered when no $heading is passed
 */

$fluent_boards_tones = [
    'accent'  => ['#EEF2FF', '#2547D0'],
    'neutral' => ['#F2F5F8', '#3F4552'],
    'warn'    => ['#FFF6DB', '#7A4D00'],
    'ok'      => ['#E0FAEC', '#0B6B3A'],
];

ob_start();
?>
<?php if (!empty($badge['label'])):
    $fluent_boards_tone = isset($badge['tone'], $fluent_boards_tones[$badge['tone']]) ? $badge['tone'] : 'accent';
    list($fluent_boards_pill_bg, $fluent_boards_pill_fg) = $fluent_boards_tones[$fluent_boards_tone];
    ?>
    <p style="margin: 0 0 16px;">
        <span class="fbs_email_pill_<?php echo esc_attr($fluent_boards_tone); ?>" style="display: inline-block; padding: 4px 10px; border-radius: 999px; background: <?php echo esc_attr($fluent_boards_pill_bg); ?>; color: <?php echo esc_attr($fluent_boards_pill_fg); ?>; font-size: 12px; font-weight: 600; line-height: 18px;"><?php echo esc_html($badge['label']); ?></span>
    </p>
<?php endif; ?>

<?php if (!empty($heading)): ?>
    <h1 class="fbs_email_text fbs_email_heading" style="margin: 0; color: #0E121B; font-size: 22px; font-weight: 600; line-height: 30px;"><?php echo esc_html($heading); ?></h1>
    <?php if (!empty($context)): ?>
        <p class="fbs_email_text2" style="margin: 6px 0 0; color: #525866; font-size: 14px; line-height: 22px;"><?php echo wp_kses_post($context); ?></p>
    <?php endif; ?>
<?php elseif (!empty($body)): ?>
    <?php // Legacy payload from senders that still pass a single body sentence. ?>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <?php if (!empty($userData['photo'])): ?>
                <td width="44" valign="top" style="width: 44px; padding-right: 12px;">
                    <img src="<?php echo esc_url($userData['photo']); ?>" width="36" height="36" alt="<?php echo esc_attr($userData['display_name']); ?>" style="display: block; width: 36px; height: 36px; border-radius: 999px;">
                </td>
            <?php endif; ?>
            <td valign="top" class="fbs_email_text2" style="color: #525866; font-size: 15px; line-height: 24px;">
                <?php if (!empty($userData['display_name'])): ?>
                    <strong class="fbs_email_text" style="color: #0E121B;"><?php echo esc_html($userData['display_name']); ?></strong>
                <?php endif; ?>
                <?php echo wp_kses_post($body); ?>
            </td>
        </tr>
    </table>
<?php endif; ?>

<?php if (!empty($comment)): ?>
    <table role="presentation" class="fbs_email_inset" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top: 24px; background: #F5F7FA; border: 1px solid #E1E4EA; border-radius: 10px;">
        <?php if (!empty($parent_comment)): ?>
            <tr>
                <td class="fbs_email_border" style="padding: 14px 16px; border-bottom: 1px solid #E1E4EA;">
                    <p class="fbs_email_text2" style="margin: 0 0 2px; color: #525866; font-size: 13px; line-height: 18px;"><?php esc_html_e('Your comment', 'fluent-boards'); ?></p>
                    <div class="fbs_email_text2 fbs_email_comment_body" style="color: #525866; font-size: 14px; line-height: 22px;"><?php echo wp_kses_post($parent_comment); ?></div>
                </td>
            </tr>
        <?php endif; ?>
        <tr>
            <td style="padding: 16px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                    <tr>
                        <?php if (!empty($userData['photo'])): ?>
                            <td width="48" valign="top" style="width: 48px; padding-right: 12px;">
                                <img src="<?php echo esc_url($userData['photo']); ?>" width="36" height="36" alt="<?php echo esc_attr($userData['display_name']); ?>" style="display: block; width: 36px; height: 36px; border-radius: 999px;">
                            </td>
                        <?php endif; ?>
                        <td valign="top">
                            <p class="fbs_email_text2" style="margin: 0 0 4px; color: #525866; font-size: 14px; line-height: 20px;">
                                <strong class="fbs_email_text" style="color: #0E121B; font-weight: 600;"><?php echo esc_html(isset($userData['display_name']) ? $userData['display_name'] : ''); ?></strong>
                                <?php if (!empty($comment_time)): ?>
                                    &middot; <?php echo esc_html($comment_time); ?>
                                <?php endif; ?>
                            </p>
                            <div class="fbs_email_text fbs_email_comment_body" style="color: #0E121B; font-size: 15px; line-height: 24px;"><?php echo wp_kses_post($comment); ?></div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
<?php endif; ?>

<?php if (!empty($task_card['title'])):
    $fluent_boards_title_color = !empty($task_card['muted']) ? '#525866' : '#0E121B';
    $fluent_boards_title_class = !empty($task_card['muted']) ? 'fbs_email_text2' : 'fbs_email_text';
    ?>
    <table role="presentation" class="fbs_email_border" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top: 24px; border: 1px solid #E1E4EA; border-radius: 10px;">
        <tr>
            <td style="padding: 18px 20px;">
                <?php if (!empty($task_card['parent_title'])): ?>
                    <p class="fbs_email_text2" style="margin: 0 0 4px; color: #525866; font-size: 13px; line-height: 18px;">
                        <?php esc_html_e('Subtask of', 'fluent-boards'); ?>
                        <?php if (!empty($task_card['parent_url'])): ?>
                            <a class="fbs_email_link" href="<?php echo esc_url($task_card['parent_url']); ?>" target="_blank" style="color: #335CFF; font-weight: 600; text-decoration: none;"><?php echo esc_html($task_card['parent_title']); ?></a>
                        <?php else: ?>
                            <?php echo esc_html($task_card['parent_title']); ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <p class="<?php echo esc_attr($fluent_boards_title_class); ?>" style="margin: 0; color: <?php echo esc_attr($fluent_boards_title_color); ?>; font-size: 16px; font-weight: 600; line-height: 24px;"><?php echo esc_html($task_card['title']); ?></p>

                <?php if (!empty($task_card['rows'])): ?>
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top: 12px;">
                        <?php foreach ($task_card['rows'] as $fluent_boards_row): ?>
                            <tr>
                                <td class="fbs_email_text2 fbs_email_kv_label" width="96" valign="top" style="width: 96px; padding: 4px 12px 4px 0; color: #525866; font-size: 14px; line-height: 20px;"><?php echo esc_html($fluent_boards_row[0]); ?></td>
                                <td class="fbs_email_text" valign="top" style="padding: 4px 0; color: #0E121B; font-size: 14px; line-height: 20px;"><?php echo wp_kses_post($fluent_boards_row[1]); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>

                <?php if (!empty($task_card['description'])): ?>
                    <div class="fbs_email_text2 fbs_email_border fbs_email_comment_body" style="margin-top: 14px; padding-top: 14px; border-top: 1px solid #E1E4EA; color: #525866; font-size: 14px; line-height: 22px;"><?php echo wp_kses_post($task_card['description']); ?></div>
                <?php endif; ?>
            </td>
        </tr>
    </table>
<?php endif; ?>

<?php if (!empty($change['to'])): ?>
    <table role="presentation" class="fbs_email_inset" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top: 24px; background: #F5F7FA; border: 1px solid #E1E4EA; border-radius: 10px;">
        <tr>
            <td style="padding: 14px 16px; font-size: 13px; line-height: 20px;">
                <?php if (!empty($change['from'])): ?>
                    <span class="fbs_email_pill_neutral" style="display: inline-block; padding: 3px 10px; border-radius: 6px; background: #F2F5F8; color: #3F4552; font-weight: 600;<?php echo !empty($change['strike']) ? ' text-decoration: line-through;' : ''; ?>"><?php echo esc_html($change['from']); ?></span>
                    <span class="fbs_email_text2" style="color: #525866; padding: 0 6px;">&rarr;</span>
                <?php elseif (!empty($change['label'])): ?>
                    <span class="fbs_email_text2" style="color: #525866; padding-right: 8px;"><?php echo esc_html($change['label']); ?></span>
                <?php endif; ?>
                <span class="fbs_email_pill_accent" style="display: inline-block; padding: 3px 10px; border-radius: 6px; background: #EEF2FF; color: #2547D0; font-weight: 600;"><?php echo esc_html($change['to']); ?></span>
            </td>
        </tr>
    </table>
<?php endif; ?>

<?php if (!empty($note)): ?>
    <p class="fbs_email_text2" style="margin: 24px 0 0; color: #525866; font-size: 14px; line-height: 22px;"><?php echo esc_html($note); ?></p>
<?php endif; ?>

<?php if (!empty($cta['url']) && !empty($cta['label'])): ?>
    <p style="margin: 24px 0 0;">
        <?php if (isset($cta['style']) && 'secondary' === $cta['style']): ?>
            <?php // Regular button: 32px tall including the 1px border. ?>
            <a class="fbs_email_btn_secondary" href="<?php echo esc_url($cta['url']); ?>" target="_blank" style="display: inline-block; padding: 5px 14px; border: 1px solid #E1E4EA; border-radius: 8px; color: #0E121B; font-size: 14px; font-weight: 500; line-height: 20px; text-decoration: none;"><?php echo esc_html($cta['label']); ?></a>
        <?php else: ?>
            <?php // Primary button: 36px tall, the product maximum. ?>
            <a class="fbs_email_btn" href="<?php echo esc_url($cta['url']); ?>" target="_blank" style="display: inline-block; padding: 8px 16px; border-radius: 8px; background: #222530; color: #FFFFFF; font-size: 14px; font-weight: 500; line-height: 20px; text-decoration: none;"><?php echo esc_html($cta['label']); ?></a>
        <?php endif; ?>
    </p>
<?php endif; ?>

<?php if (!empty($fallback_url)): ?>
    <div class="fbs_email_border" style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #E1E4EA;">
        <p class="fbs_email_text2" style="margin: 0 0 8px; color: #525866; font-size: 13px; line-height: 20px;"><?php esc_html_e('Button not working? Paste this link into your browser:', 'fluent-boards'); ?></p>
        <p class="fbs_email_inset fbs_email_link fbs_email_url" style="margin: 0; padding: 10px 12px; border-radius: 8px; background: #F5F7FA; color: #335CFF; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; line-height: 18px;"><?php echo esc_html($fallback_url); ?></p>
    </div>
<?php endif; ?>
<?php
$fluent_boards_email_content = ob_get_clean();
include __DIR__ . '/template.php';
