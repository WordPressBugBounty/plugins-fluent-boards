<?php if (!defined('ABSPATH')) exit; // if accessed directly exit ?>
<?php
/**
 * Task activity email: a headline, who did it, then a timeline of what changed.
 * One view for a single event and for grouped updates. Output is buffered into
 * $fluent_boards_email_content for template.php, which adds the logo and breadcrumb bar.
 *
 * @var string $headline     Headline HTML, already escaped (holds the linked task title)
 * @var array  $actor        ['display_name', 'photo'] when one person did everything, else null
 * @var string $actor_names  Names joined with commas, used when several people made changes
 * @var string $time_label   e.g. "10:42 AM" or "10:42 AM – 10:55 AM"
 * @var array  $items        [['type', 'title', 'style' => pills|text|quote|comment, 'from', 'to',
 *                             'html', 'parent_html', 'note', 'time', 'by']]
 * @var array  $cta          ['label', 'url']
 * @var string $board_url    "View board" link
 */

// Icon file, background and color per activity type, plus a tone class for dark mode.
// The icons are the plugin's own (Pieces/Icons.vue) saved as PNGs, since email clients drop SVG.
// Each has a lighter "-dark" copy that common-style.php shows in dark mode.
$fluent_boards_icons = [
    'comment'     => ['comment', '#E7EDFF', '#3B5BDB', 'blue'],
    'mention'     => ['mention', '#F0E9FF', '#7048E8', 'purple'],
    'assign'      => ['assign', '#E3F9EC', '#2B8A3E', 'green'],
    'unassign'    => ['unassign', '#F1F3F7', '#4A5263', 'gray'],
    'stage'       => ['stage', '#E7EDFF', '#3B5BDB', 'blue'],
    'date'        => ['date', '#FFF4E0', '#E8590C', 'orange'],
    'priority'    => ['priority', '#FFE8E8', '#E03131', 'red'],
    'description' => ['description', '#F1F3F7', '#4A5263', 'gray'],
    'archive'     => ['archive', '#F1F3F7', '#4A5263', 'gray'],
];
$fluent_boards_icon_url = FLUENT_BOARDS_PLUGIN_URL . 'assets/images/email-icons/';
$fluent_boards_pill = function ($text, $tone) use ($fluent_boards_icons) {
    $colors = isset($fluent_boards_icons[$tone]) ? $fluent_boards_icons[$tone] : ['', '#F1F3F7', '#4A5263', 'gray'];
    return '<span class="fbs_email_tone_' . esc_attr($colors[3]) . '" style="display: inline-block; padding: 2px 9px; border-radius: 999px; background: ' . esc_attr($colors[1]) . '; color: ' . esc_attr($colors[2]) . '; font-size: 13px; font-weight: 600; line-height: 20px;">' . esc_html($text) . '</span>';
};
$fluent_boards_last = count($items) - 1;

ob_start();
?>
<h1 class="fbs_email_text fbs_email_heading" style="margin: 0; color: #0E121B; font-size: 22px; font-weight: 600; line-height: 30px;"><?php echo wp_kses_post($headline); ?></h1>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top: 8px;">
    <tr>
        <?php if (!empty($actor['photo'])): ?>
            <td valign="middle" style="padding-right: 8px;">
                <img src="<?php echo esc_url($actor['photo']); ?>" width="22" height="22" alt="" style="display: block; width: 22px; height: 22px; border-radius: 999px;">
            </td>
        <?php endif; ?>
        <td valign="middle" class="fbs_email_text2" style="color: #5F6573; font-size: 14px; line-height: 22px;">
            <?php
            $fluent_boards_who = !empty($actor['display_name']) ? $actor['display_name'] : (isset($actor_names) ? $actor_names : '');
            echo esc_html(implode(' · ', array_filter([$fluent_boards_who, isset($time_label) ? $time_label : ''])));
            ?>
        </td>
    </tr>
</table>

<?php // Timeline: an icon column with a thin rail behind it, then each change. ?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top: 26px;">
    <?php foreach ($items as $fluent_boards_index => $fluent_boards_item):
        $fluent_boards_type = isset($fluent_boards_icons[$fluent_boards_item['type']]) ? $fluent_boards_item['type'] : 'description';
        list($fluent_boards_icon, $fluent_boards_bg, $fluent_boards_fg, $fluent_boards_tone) = $fluent_boards_icons[$fluent_boards_type];
        $fluent_boards_is_last = $fluent_boards_index === $fluent_boards_last;
        $fluent_boards_style = isset($fluent_boards_item['style']) ? $fluent_boards_item['style'] : 'text';
        ?>
        <tr>
            <td width="48" valign="top" class="<?php echo $fluent_boards_is_last ? '' : 'fbs_email_rail'; ?>" style="width: 48px; padding: 0 0 <?php echo $fluent_boards_is_last ? '0' : '22px'; ?>;<?php echo $fluent_boards_is_last ? '' : ' background-image: linear-gradient(#EEF0F5, #EEF0F5); background-repeat: no-repeat; background-position: 17px 0; background-size: 2px 100%;'; ?>">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td class="fbs_email_icon fbs_email_icon_<?php echo esc_attr($fluent_boards_icon); ?> fbs_email_tone_<?php echo esc_attr($fluent_boards_tone); ?>" width="30" height="30" align="center" valign="middle" style="width: 30px; height: 30px; border: 3px solid #FFFFFF; border-radius: 999px; background: <?php echo esc_attr($fluent_boards_bg); ?>; text-align: center; font-size: 0; line-height: 0;"><img class="fbs_email_icon_img" src="<?php echo esc_url($fluent_boards_icon_url . $fluent_boards_icon . '.png'); ?>" width="18" height="18" alt="" style="display: inline-block; width: 18px; height: 18px; border: 0; vertical-align: middle;"></td>
                    </tr>
                </table>
            </td>
            <td valign="top" style="padding: 0 0 <?php echo $fluent_boards_is_last ? '0' : '22px'; ?>;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                    <tr>
                        <td valign="top" class="fbs_email_text" style="padding-top: 7px; color: #0E121B; font-size: 15px; font-weight: 600; line-height: 22px;">
                            <?php echo esc_html($fluent_boards_item['title']); ?>
                            <?php if (!empty($fluent_boards_item['by'])): ?>
                                <span class="fbs_email_text2 fbs_email_by" style="color: #8A93A6; font-size: 13px; font-weight: 400;">
                                    <?php
                                    /* translators: %s is the name of the person who made the change */
                                    echo esc_html(sprintf(__('by %s', 'fluent-boards'), $fluent_boards_item['by']));
                                    ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <?php if (!empty($fluent_boards_item['time'])): ?>
                            <td valign="top" align="right" class="fbs_email_text2" style="padding: 9px 0 0 12px; color: #8A93A6; font-size: 12px; line-height: 18px; white-space: nowrap; text-align: right;"><?php echo esc_html($fluent_boards_item['time']); ?></td>
                        <?php endif; ?>
                    </tr>
                </table>

                <?php if ($fluent_boards_style === 'pills' && isset($fluent_boards_item['to']) && $fluent_boards_item['to'] !== ''): ?>
                    <p class="fbs_email_text2" style="margin: 6px 0 0; color: #4A5263; font-size: 14px; line-height: 24px;">
                        <?php if (isset($fluent_boards_item['from']) && $fluent_boards_item['from'] !== ''): ?>
                            <?php echo wp_kses_post($fluent_boards_pill($fluent_boards_item['from'], 'description')); ?>
                            <span style="padding: 0 4px;">&rarr;</span>
                        <?php endif; ?>
                        <?php echo wp_kses_post($fluent_boards_pill($fluent_boards_item['to'], $fluent_boards_type)); ?>
                    </p>
                <?php elseif ($fluent_boards_style === 'quote' && ($fluent_boards_item['to'] ?? '') !== ''): ?>
                    <p class="fbs_email_text2" style="margin: 4px 0 0; color: #4A5263; font-size: 14px; line-height: 22px;">&ldquo;<?php echo esc_html($fluent_boards_item['to']); ?>&rdquo;</p>
                <?php elseif ($fluent_boards_style === 'comment' && !empty($fluent_boards_item['html'])): ?>
                    <?php // A single comment is shown in full, with the comment it replies to above it. ?>
                    <table role="presentation" class="fbs_email_inset" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse: separate; border-spacing: 0; margin-top: 10px; background: #F5F7FA; border: 1px solid #E1E4EA; border-radius: 10px;">
                        <?php if (!empty($fluent_boards_item['parent_html'])): ?>
                            <tr>
                                <td class="fbs_email_border" style="padding: 12px 16px; border-bottom: 1px solid #E1E4EA;">
                                    <p class="fbs_email_text2" style="margin: 0 0 2px; color: #5F6573; font-size: 12px; line-height: 18px;"><?php esc_html_e('Your comment', 'fluent-boards'); ?></p>
                                    <div class="fbs_email_text2 fbs_email_comment_body" style="color: #5F6573; font-size: 14px; line-height: 22px;"><?php echo wp_kses_post($fluent_boards_item['parent_html']); ?></div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="fbs_email_text fbs_email_comment_body" style="padding: 14px 16px; color: #0E121B; font-size: 15px; line-height: 24px;"><?php echo wp_kses_post($fluent_boards_item['html']); ?></td>
                        </tr>
                    </table>
                <?php elseif (!empty($fluent_boards_item['to'])): ?>
                    <p class="fbs_email_text2" style="margin: 4px 0 0; color: #4A5263; font-size: 14px; line-height: 22px;"><?php echo esc_html($fluent_boards_item['to']); ?></p>
                <?php endif; ?>

                <?php if (!empty($fluent_boards_item['note'])): ?>
                    <p class="fbs_email_text2" style="margin: 6px 0 0; color: #8A93A6; font-size: 13px; line-height: 20px;"><?php echo esc_html($fluent_boards_item['note']); ?></p>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<?php if (!empty($cta['url']) || !empty($board_url)): ?>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top: 30px;">
        <tr>
            <?php if (!empty($cta['url'])): ?>
                <td style="padding-right: 20px;">
                    <a class="fbs_email_btn_accent" href="<?php echo esc_url($cta['url']); ?>" target="_blank" style="display: inline-block; padding: 11px 22px; border-radius: 8px; background: #3B5BDB; color: #FFFFFF; font-size: 14px; font-weight: 600; line-height: 20px; text-decoration: none;"><?php echo esc_html($cta['label']); ?></a>
                </td>
            <?php endif; ?>
            <?php if (!empty($board_url)): ?>
                <td>
                    <a class="fbs_email_link" href="<?php echo esc_url($board_url); ?>" target="_blank" style="color: #3B5BDB; font-size: 14px; font-weight: 600; line-height: 20px; text-decoration: none;"><?php esc_html_e('View board', 'fluent-boards'); ?> &rarr;</a>
                </td>
            <?php endif; ?>
        </tr>
    </table>
<?php endif; ?>
<?php
$fluent_boards_email_content = ob_get_clean();
include __DIR__ . '/template.php';
