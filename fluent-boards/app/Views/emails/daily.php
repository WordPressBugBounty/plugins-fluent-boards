<?php
ob_start(); // Start output buffering
?>


    <!--Start you code here -->
    <div class="fbs_daily_reminder">
        <div class="fbs_email_greeting">
            <strong class="fbs_bg_white"><?php echo esc_html__('Good Morning', 'fluent-boards') . ', ' . esc_html($name) . '!'; ?></strong>
            <p class="fbs_bg_white"> It's <?php echo esc_html(gmdate('l, F j, Y')); ?>. <?php echo esc_html__("Here's your task summary for today:", 'fluent-boards'); ?> </p> <?php // This will display the current date as "Today is Monday, January 1, 2022" ?>
        </div>

    <strong class="fbs_bg_white" style="font-size: 15px"><?php echo esc_html__('Tasks Due Today:', 'fluent-boards'); ?></strong>

        <ul class="fbs_email_task_list_group">
            <?php foreach ($tasks as $fluent_boards_task): ?>
                <li class="fbs_email_task_list_item">
                    <a target="_blank"
                       href="<?php echo esc_url($page_url . 'boards/' . $fluent_boards_task->board->id . '/tasks/' . $fluent_boards_task->id . '-' . substr($fluent_boards_task->title, 0, 10)); ?>">
                        <?php echo esc_html($fluent_boards_task->title) . ' '; ?>
                    </a>
                    <?php echo esc_html__('task of board', 'fluent-boards'); ?>
                    <a target="_blank"
                       href="<?php echo esc_url($page_url . 'boards/' . $fluent_boards_task->board->id); ?>">
                        <?php echo esc_html($fluent_boards_task->board->title); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>


    <!--end your code here -->


<?php
$fluent_boards_email_content = ob_get_clean();
include 'template.php';