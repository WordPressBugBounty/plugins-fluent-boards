<?php if (!defined('ABSPATH')) exit; // if accessed directly exit
/**
 * Daily task summary (sent by Fluent Boards Pro). Tasks due today, grouped by board.
 *
 * @var iterable $tasks    Tasks with a loaded ->board
 * @var string   $name     Recipient display name
 * @var string   $page_url App base URL ending in "#/"
 */

$fluent_boards_groups = [];
foreach ($tasks as $fluent_boards_task) {
    $fluent_boards_board_id = $fluent_boards_task->board->id;
    if (!isset($fluent_boards_groups[$fluent_boards_board_id])) {
        $fluent_boards_groups[$fluent_boards_board_id] = [
            'board' => $fluent_boards_task->board,
            'tasks' => [],
        ];
    }
    $fluent_boards_groups[$fluent_boards_board_id]['tasks'][] = $fluent_boards_task;
}
$fluent_boards_task_count = array_sum(array_map(function ($group) {
    return count($group['tasks']);
}, $fluent_boards_groups));

ob_start();
?>
<p style="margin: 0 0 16px;">
    <span class="fbs_email_pill_accent" style="display: inline-block; padding: 4px 10px; border-radius: 999px; background: #EEF2FF; color: #2547D0; font-size: 12px; font-weight: 600; line-height: 18px;"><?php esc_html_e('Daily summary', 'fluent-boards'); ?></span>
</p>
<h1 class="fbs_email_text fbs_email_heading" style="margin: 0; color: #0E121B; font-size: 22px; font-weight: 600; line-height: 30px;">
    <?php
    /* translators: %s is the recipient's name */
    echo esc_html(sprintf(__('Good morning, %s', 'fluent-boards'), $name));
    ?>
</h1>
<p class="fbs_email_text2" style="margin: 6px 0 0; color: #525866; font-size: 14px; line-height: 22px;">
    <?php
    echo esc_html(date_i18n(__('l, F j, Y', 'fluent-boards')));
    echo ' &middot; ';
    /* translators: %d is the number of tasks due today */
    echo esc_html(sprintf(_n('%d task due today', '%d tasks due today', $fluent_boards_task_count, 'fluent-boards'), $fluent_boards_task_count));
    ?>
</p>

<p class="fbs_email_text2" style="margin: 24px 0 8px; color: #525866; font-size: 12px; font-weight: 600; line-height: 16px; letter-spacing: 0.06em; text-transform: uppercase;"><?php esc_html_e('Due today', 'fluent-boards'); ?></p>

<?php foreach ($fluent_boards_groups as $fluent_boards_group):
    $fluent_boards_board = $fluent_boards_group['board'];
    $fluent_boards_board_url = $page_url . 'boards/' . $fluent_boards_board->id;
    ?>
    <table role="presentation" class="fbs_email_border" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom: 12px; border: 1px solid #E1E4EA; border-radius: 10px;">
        <tr>
            <td class="fbs_email_inset" style="padding: 12px 16px; background: #F5F7FA; border-bottom: 1px solid #E1E4EA; border-radius: 10px 10px 0 0; font-size: 13px; line-height: 18px;">
                <a class="fbs_email_text" href="<?php echo esc_url($fluent_boards_board_url); ?>" target="_blank" style="color: #0E121B; font-weight: 600; text-decoration: none;"><?php echo esc_html($fluent_boards_board->title); ?></a>
                <span class="fbs_email_text2" style="color: #525866;">
                    &middot;
                    <?php
                    $fluent_boards_board_count = count($fluent_boards_group['tasks']);
                    /* translators: %d is the number of tasks */
                    echo esc_html(sprintf(_n('%d task', '%d tasks', $fluent_boards_board_count, 'fluent-boards'), $fluent_boards_board_count));
                    ?>
                </span>
            </td>
        </tr>
        <?php foreach ($fluent_boards_group['tasks'] as $fluent_boards_index => $fluent_boards_task):
            $fluent_boards_task_url = $page_url . 'boards/' . $fluent_boards_board->id . '/tasks/' . $fluent_boards_task->id . '-' . substr($fluent_boards_task->title, 0, 10);
            $fluent_boards_stage = $fluent_boards_task->stage ? $fluent_boards_task->stage->title : '';
            ?>
            <tr>
                <td class="fbs_email_border" style="padding: 14px 16px;<?php echo $fluent_boards_index ? ' border-top: 1px solid #E1E4EA;' : ''; ?>">
                    <a class="fbs_email_text" href="<?php echo esc_url($fluent_boards_task_url); ?>" target="_blank" style="display: block; color: #0E121B; font-size: 15px; font-weight: 600; line-height: 22px; text-decoration: none;"><?php echo esc_html($fluent_boards_task->title); ?></a>
                    <?php if ($fluent_boards_stage || $fluent_boards_task->due_at): ?>
                        <span class="fbs_email_text2" style="display: block; color: #525866; font-size: 13px; line-height: 18px;">
                            <?php echo esc_html($fluent_boards_stage); ?>
                            <?php if ($fluent_boards_stage && $fluent_boards_task->due_at): ?>&middot;<?php endif; ?>
                            <?php if ($fluent_boards_task->due_at): ?>
                                <?php echo esc_html(date_i18n(get_option('time_format'), strtotime($fluent_boards_task->due_at))); ?>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endforeach; ?>

<p style="margin: 24px 0 0;">
    <a class="fbs_email_btn" href="<?php echo esc_url($page_url); ?>" target="_blank" style="display: inline-block; padding: 8px 16px; border-radius: 8px; background: #222530; color: #FFFFFF; font-size: 14px; font-weight: 500; line-height: 20px; text-decoration: none;"><?php esc_html_e('Open dashboard', 'fluent-boards'); ?></a>
</p>
<?php
$fluent_boards_email_content = ob_get_clean();

if (empty($email_reason)) {
    $email_reason = __('You’re getting this daily summary because it’s on in your notification settings.', 'fluent-boards');
}

include __DIR__ . '/template.php';
