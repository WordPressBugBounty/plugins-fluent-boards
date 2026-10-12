<?php if (!defined('ABSPATH')) exit; // if accessed directly exit
/**
 * Task and subtask reminder email (sent by Fluent Boards Pro).
 *
 * @var object $task        Task or subtask being reminded about
 * @var object $parent_task Parent task (the task itself for top-level tasks)
 * @var object $board
 * @var string $stage       Stage title
 * @var string $task_url
 * @var object $user        Recipient (optional; older senders omit it)
 */

$fluent_boards_is_subtask = !empty($task->parent_id);

// Resolve a safe recipient name to avoid a broken greeting when $user is missing.
$fluent_boards_recipient_name = '';
if (isset($user) && is_object($user)) {
    foreach (['display_name', 'user_nicename', 'user_login'] as $fluent_boards_name_field) {
        if (!empty($user->{$fluent_boards_name_field})) {
            $fluent_boards_recipient_name = $user->{$fluent_boards_name_field};
            break;
        }
    }
}

if ($fluent_boards_recipient_name) {
    $heading = $fluent_boards_is_subtask
        /* translators: %s is the recipient's name */
        ? sprintf(__('Hi %s, a subtask is due soon', 'fluent-boards'), $fluent_boards_recipient_name)
        /* translators: %s is the recipient's name */
        : sprintf(__('Hi %s, a task is due soon', 'fluent-boards'), $fluent_boards_recipient_name);
} else {
    $heading = $fluent_boards_is_subtask
        ? __('A subtask is due soon', 'fluent-boards')
        : __('A task is due soon', 'fluent-boards');
}

$badge = [
    'label' => __('Reminder', 'fluent-boards'),
    'tone'  => 'warn',
];

$fluent_boards_rows = [
    [__('Board', 'fluent-boards'), esc_html($board->title)],
];
if (!empty($stage)) {
    $fluent_boards_rows[] = [__('Stage', 'fluent-boards'), esc_html($stage)];
}
if (!empty($task->due_at)) {
    $fluent_boards_rows[] = [
        __('Due', 'fluent-boards'),
        '<strong class="fbs_email_warn_text" style="color: #7A4D00;">' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($task->due_at))) . '</strong>',
    ];
}

$task_card = [
    'title'        => $task->title,
    'parent_title' => ($fluent_boards_is_subtask && !empty($parent_task)) ? $parent_task->title : '',
    'parent_url'   => $fluent_boards_is_subtask ? $task_url : '',
    'rows'         => $fluent_boards_rows,
    'description'  => !empty($task->description) ? wp_trim_words($task->description, 30) : '',
];

$cta = [
    'label' => $fluent_boards_is_subtask ? __('Open subtask', 'fluent-boards') : __('Open task', 'fluent-boards'),
    'url'   => $task_url,
];

if (empty($email_reason)) {
    $email_reason = __('You’re getting this because you’re assigned and reminders are on.', 'fluent-boards');
}

// The sender's $body repeats the heading; the structured card replaces it.
$body = '';

include __DIR__ . '/notification.php';
