<?php
/**
 * @var $body string
 * @var $pre_header string
 * @var $show_footer bool
 * @var $task object
 * @var $parent_task object
 * @var $board object
 * @var $task_url string
 * @var $site_url string
 * @var $site_title string
 * @var $site_logo string
 */

ob_start(); // Start output buffering
?>


<div style="font-family: Arial, sans-serif; color:#333; line-height:1.5; background: #ffff !important;">
    <?php
    // Resolve a safe recipient name to avoid broken greeting when $user is missing
    $recipient_name = '';
    if (isset($user) && is_object($user)) {
        $recipient_name = $user->display_name ?? '';
        if (!$recipient_name && !empty($user->user_nicename)) {
            $recipient_name = $user->user_nicename;
        }
        if (!$recipient_name && !empty($user->user_login)) {
            $recipient_name = $user->user_login;
        }
    }
    if (!$recipient_name) {
        $recipient_name = __('there', 'fluent-boards');
    }
    ?>
    <p style="font-size:16px; font-weight:600; margin:0 0 10px 0;background: #fff !important;">
        <?php printf(__('Hey %s,', 'fluent-boards'), esc_html($recipient_name)); ?>
    </p>

    <p style="margin:0 0 15px 0; font-size:14px; color:#555; background: #fff !important;">
        <?php echo wp_kses_post($body); ?>
    </p>

    <div style="margin: 15px 0; padding: 15px; border:1px solid #eee; border-radius: 8px;">
        <h4 style="margin: 0 0 10px 0; color: #333; font-size: 16px;">
            <?php echo $task->parent_id ? __('Subtask Details:', 'fluent-boards') : __('Task Details:', 'fluent-boards'); ?>
        </h4>
        
        <p style="margin: 5px 0; font-size:14px;">
            <strong><?php echo $task->parent_id ? __('Subtask:', 'fluent-boards') : __('Task:', 'fluent-boards'); ?></strong> 
            <?php echo esc_html($task->title); ?>
        </p>
        
        <?php if ($task->parent_id && $parent_task): ?>
        <p style="margin: 5px 0; font-size:14px;">
            <strong><?php echo __('Parent Task:', 'fluent-boards'); ?></strong> 
            <?php echo esc_html($parent_task->title); ?>
        </p>
        <?php endif; ?>
        
        <p style="margin: 5px 0; font-size:14px;">
            <strong><?php echo __('Board:', 'fluent-boards'); ?></strong> 
            <?php echo esc_html($board->title); ?>
        </p>

        <p style="margin: 5px 0; font-size:14px;">
            <strong><?php echo __('Stage:', 'fluent-boards'); ?></strong> 
            <?php echo esc_html($stage); ?>
        </p>
        
        <?php if ($task->due_at): ?>
        <p style="margin: 5px 0; font-size:14px;">
            <strong><?php echo __('Due Date:', 'fluent-boards'); ?></strong> 
            <?php echo date('M j, Y g:i A', strtotime($task->due_at)); ?>
        </p>
        <?php endif; ?>
        
        <?php if ($task->description): ?>
        <p style="margin: 10px 0 5px 0; font-size:14px;">
            <strong><?php echo __('Description:', 'fluent-boards'); ?></strong>
        </p>
    <div style="margin: 5px 0; padding: 10px; border-radius: 4px; font-size: 14px;">
            <?php echo wp_kses_post(wp_trim_words($task->description, 30)); ?>
        </div>
        <?php endif; ?>
    </div>
    
    <div style="margin-top:20px; background: #fff !important;">
        <a href="<?php echo esc_url($task_url); ?>" 
           target="_blank"
           style="background: #6268F1; color: #FFF; padding:7px 12px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: 500; font-size:14px;">
            <?php echo $task->parent_id ? __('View Subtask', 'fluent-boards') : __('View Task', 'fluent-boards'); ?>
        </a>
    </div>
</div>


<!--end your code here -->

<?php
$content = ob_get_clean(); // Get the content and clean the buffer
// Include the template and pass the content
include 'template.php';
?>