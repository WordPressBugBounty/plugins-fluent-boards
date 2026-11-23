<?php

namespace FluentBoards\App\Services\Automations\Actions;

use FluentBoards\App\Services\Automations\AutomationContext;
use FluentBoards\App\Services\Automations\BaseAction;
use FluentBoards\App\Services\CommentService;
use FluentBoards\App\Services\Constant;

class AddCommentAction extends BaseAction
{
    protected $actionName = 'add_comment';

    public function getBlock()
    {
        return [
            'category' => __('Task', 'fluent-boards'),
            'title' => __('Add comment', 'fluent-boards'),
            'description' => __('Add an internal comment to the task.', 'fluent-boards'),
        ];
    }

    public function getFields()
    {
        return [
            [
                'key' => 'comment',
                'type' => 'textarea',
                'label' => __('Comment', 'fluent-boards'),
                'placeholder' => __('Write the comment text...', 'fluent-boards'),
                'smart_codes' => true,
            ],
        ];
    }

    public function handle(AutomationContext $context, array $settings)
    {
        $task = $context->getTask();
        $commentText = sanitize_textarea_field($settings['comment'] ?? '');

        if (!$task || !$commentText) {
            throw new \Exception(esc_html__('Task and comment are required.', 'fluent-boards'));
        }

        $commentService = new CommentService();
        // Comments render as HTML, so escape smart code values and apply the same
        // allow-list as user-written comments.
        $description = $commentService->sanitizeContent($context->replaceSmartCodesInHtml($commentText));
        if ($description === '') {
            throw new \Exception(esc_html__('Task and comment are required.', 'fluent-boards'));
        }

        $comment = $commentService->create([
            'description' => $description,
            'created_by' => $context->get('actor_id') ?: get_current_user_id(),
            'task_id' => (int) $task->id,
            'board_id' => (int) $task->board_id,
            'type' => 'comment',
            'privacy' => 'private',
            'status' => 'published',
            'settings' => [
                'source' => Constant::AUTOMATION_COMMENT_SOURCE,
                'automation_trigger' => $context->getTriggerName(),
            ],
        ], $task->id, $task->board_id);

        return ['comment_id' => (int) $comment->id];
    }
}
