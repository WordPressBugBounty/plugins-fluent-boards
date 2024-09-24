<?php
ob_start(); // Start output buffering
?>


    <!--Start you code here -->
    <div class="fbs_daily_reminder">
        <div class="fbs_email_greeting">
            <strong class="fbs_bg_white"><?php echo __('Good Morning', 'fluent-boards').', '
                               .esc_html($name).'!' ?></strong>
            <p class="fbs_bg_white"> It's <?php echo date('l, F j, Y').'. '
                                    .__("Here's your task summary for today:") ?> </p> <?php // This will display the current date as "Today is Monday, January 1, 2022" ?>
        </div>

        <strong class="fbs_bg_white" style="font-size: 15px"><?php echo __('Tasks Due Today:', 'fluent-boards'); ?></strong>

        <ul class="fbs_email_task_list_group">
            <?php foreach ($tasks as $task): ?>
                <li class="fbs_email_task_list_item">
                    <a target="_blank"
                       href="<?php echo htmlspecialchars($page_url.'boards/'
                                                         .$task->board->id
                                                         .'/tasks/'.$task->id
                                                         .'-'
                                                         .substr($task->title,
                               0, 10)); ?>">
                        <?php echo esc_html($task->title). ' '; ?>
                    </a>
                    <?php echo __('task of board', 'fluent-boards'); ?>
                    <a target="_blank"
                       href="<?php echo htmlspecialchars($page_url.'boards/'
                                                         .$task->board->id); ?>">
                        <?php echo esc_html($task->board->title); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>


    <!--end your code here -->


<?php
$content = ob_get_clean(); // Get the content and clean the buffer
// Include the template and pass the content
include 'template.php';