<?php

namespace FluentBoards\App\Services\Automations\Triggers;

use FluentBoards\App\Models\Comment;
use FluentBoards\App\Services\Automations\AutomationContext;
use FluentBoards\App\Services\Automations\BaseTrigger;
use FluentBoards\App\Services\Constant;

class CommentAddedTrigger extends BaseTrigger
{
    protected $triggerName = 'comment_added';

    protected $hookName = 'fluent_boards/comment_created';

    public function getTrigger()
    {
        return [
            'category' => __('Comment', 'fluent-boards'),
            'title' => __('Comment added', 'fluent-boards'),
            'description' => __('Runs when someone comments on a task.', 'fluent-boards'),
        ];
    }

    public function createContext(array $originalArgs)
    {
        $comment = $originalArgs[0] ?? null;
        if (!$comment instanceof Comment) {
            return null;
        }

        // Comments written by automations never start another automation.
        $settings = $comment->settings;
        if (is_array($settings) && ($settings['source'] ?? '') === Constant::AUTOMATION_COMMENT_SOURCE) {
            return null;
        }

        $task = $comment->task;
        if (!$task) {
            return null;
        }

        return AutomationContext::fromTask($this->triggerName, $task, $originalArgs, [
            'comment_id' => (int) $comment->id,
            'comment_author_id' => (int) $comment->created_by,
        ]);
    }
}
