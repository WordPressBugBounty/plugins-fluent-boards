<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\App;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\AiService;
use FluentBoards\App\Services\LabelService;
use FluentBoards\App\Services\TaskService;
use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\Framework\Support\Arr;

/**
 * AI writing assistant endpoints for the task description editor.
 *
 * Settings routes are admin-only; `generate` is available to any authenticated
 * Fluent Boards user (see AiPolicy).
 */
class AiController extends Controller
{
    /** Caps on the comment thread pulled into a task summary prompt. */
    const MAX_SUMMARY_COMMENTS = 30;
    const MAX_COMMENT_CHARS    = 1000;

    private AiService $aiService;

    public function __construct(AiService $aiService)
    {
        parent::__construct();
        $this->aiService = $aiService;
    }

    public function getSettings(Request $request)
    {
        return $this->sendSuccess([
            'settings'         => $this->aiService->getDisplaySettings(),
            'has_wordpress_ai' => $this->aiService->hasWordPressAi(),
            'connectors_url'   => admin_url('options-connectors.php'),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $result = $this->aiService->saveSettings($request->get('settings', []));

        if (is_wp_error($result)) {
            return $this->sendError(['message' => $result->get_error_message()], 422);
        }

        return $this->sendSuccess($result);
    }

    public function getModels(Request $request)
    {
        $provider = sanitize_text_field(Arr::get($request->get('settings', []), 'provider', ''));
        $models   = $this->aiService->getModelOptions($provider);

        if (is_wp_error($models)) {
            return $this->sendError(['message' => $models->get_error_message()], 422);
        }

        return $this->sendSuccess(['models' => $models]);
    }

    public function testConnection(Request $request)
    {
        $result = $this->aiService->testConnection($request->get('settings', []));

        if (is_wp_error($result)) {
            return $this->sendError(['message' => $result->get_error_message()], 422);
        }

        return $this->sendSuccess([
            'message' => __('Connection successful! Your API key is valid.', 'fluent-boards'),
        ]);
    }

    public function generate(Request $request)
    {
        $context = $request->get('context', []);
        if (!is_array($context)) {
            $context = [];
        }

        $result = $this->aiService->generate(
            $request->get('action', ''),
            $request->get('content', ''),
            sanitize_text_field($request->get('tone', '')),
            $request->get('prompt', ''),
            $context
        );

        if (is_wp_error($result)) {
            return $this->sendError(['message' => $result->get_error_message()], 422);
        }

        return $this->sendSuccess(['content' => $result]);
    }

    /**
     * Task-level AI actions (board-scoped via SingleBoardPolicy):
     * summarize | subtasks | suggestions. Returns structured data the frontend
     * previews and applies through existing task endpoints.
     */
    public function taskAssist(Request $request, $board_id, $task_id)
    {
        $action = sanitize_text_field($request->get('action', ''));

        $task = Task::where('id', $task_id)->where('board_id', $board_id)->first();
        if (!$task) {
            return $this->sendError(['message' => __('Task not found.', 'fluent-boards')], 404);
        }

        $board = Board::find($board_id);
        $context = [
            'task_title'  => $task->title,
            'board_title' => $board ? $board->title : '',
            'description' => (string) $task->description,
        ];

        if ($action === 'summarize') {
            $context['comments'] = $this->collectTaskComments($task_id);
            $result = $this->aiService->taskSummary($context);
            if (is_wp_error($result)) {
                return $this->sendError(['message' => $result->get_error_message()], 422);
            }
            return $this->sendSuccess(['type' => 'summary', 'content' => $result]);
        }

        if ($action === 'subtasks') {
            $result = $this->aiService->taskSubtasks($context);
            if (is_wp_error($result)) {
                return $this->sendError(['message' => $result->get_error_message()], 422);
            }
            return $this->sendSuccess(['type' => 'subtasks', 'items' => $result]);
        }

        if ($action === 'suggestions') {
            $labels = array_values(array_filter(array_map('sanitize_text_field', (array) $request->get('labels', []))));
            $result = $this->aiService->taskSuggestions($context, $labels, ['urgent', 'high', 'medium', 'low']);
            if (is_wp_error($result)) {
                return $this->sendError(['message' => $result->get_error_message()], 422);
            }
            return $this->sendSuccess([
                'type'     => 'suggestions',
                'labels'   => $result['labels'],
                'priority' => $result['priority'],
            ]);
        }

        return $this->sendError(['message' => __('Invalid AI action.', 'fluent-boards')], 422);
    }

    /**
     * Apply suggested labels and priority in one transactional request, returning
     * the committed state so the frontend refreshes once.
     */
    public function applySuggestions(Request $request, $board_id, $task_id)
    {
        $labelIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) $request->get('label_ids', [])
        ))));
        $priority = sanitize_text_field($request->get('priority', ''));

        if ($priority && !in_array($priority, ['urgent', 'high', 'medium', 'low'], true)) {
            return $this->sendError(['message' => __('Invalid priority.', 'fluent-boards')], 422);
        }

        if (!$labelIds && !$priority) {
            return $this->sendError(['message' => __('Nothing to apply.', 'fluent-boards')], 422);
        }

        $task = Task::where('id', $task_id)->where('board_id', $board_id)->first();
        if (!$task) {
            return $this->sendError(['message' => __('Task not found.', 'fluent-boards')], 404);
        }

        $db = App::getInstance('db');
        $db->beginTransaction();

        try {
            $labelService = new LabelService();
            foreach ($labelIds as $labelId) {
                // syncWithoutDetaching keeps this idempotent on retry.
                $labelService->createLabelForTask([
                    'task_id'       => $task->id,
                    'board_term_id' => $labelId,
                ], $board_id);
            }

            if ($priority) {
                (new TaskService())->updateTaskProperty('priority', $priority, $task);
            }

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            return $this->sendError(['message' => $e->getMessage()], 400);
        }

        $task->load('labels');

        return $this->sendSuccess([
            'task'     => $task,
            'labels'   => $task->labels,
            'priority' => $task->priority,
            'message'  => __('Suggestions applied.', 'fluent-boards'),
        ]);
    }

    /**
     * Most recent comments, oldest-first, with each one capped so a single long
     * comment cannot dominate the prompt budget.
     */
    private function collectTaskComments($taskId)
    {
        $comments = Comment::where('task_id', $taskId)
            ->where('type', 'comment')
            ->orderBy('id', 'desc')
            ->limit(self::MAX_SUMMARY_COMMENTS)
            ->get();

        $lines = [];
        foreach ($comments as $comment) {
            $text = trim(wp_strip_all_tags((string) $comment->description));
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) > self::MAX_COMMENT_CHARS) {
                $text = mb_substr($text, 0, self::MAX_COMMENT_CHARS) . '…';
            }
            $lines[] = '- ' . $text;
        }

        // Re-order chronologically now that the newest have been selected.
        return implode("\n", array_reverse($lines));
    }
}
