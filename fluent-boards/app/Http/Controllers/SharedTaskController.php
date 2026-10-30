<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Models\Stage;
use FluentBoards\App\Services\PublicTaskSerializer;
use FluentBoards\App\Services\TaskShareService;
use FluentBoards\Framework\Http\Request\Request;

/**
 * Read-only endpoints for task share links (?share_token=). SharedTaskPolicy has already
 * resolved the token; the share's own settings decide what is shown.
 */
class SharedTaskController extends Controller
{
    private $taskShareService;

    private $serializer;

    public function __construct(TaskShareService $taskShareService, PublicTaskSerializer $serializer)
    {
        parent::__construct();
        $this->taskShareService = $taskShareService;
        $this->serializer = $serializer;
    }

    public function findSharedTask(Request $request)
    {
        $token = $request->getSafe('share_token', 'sanitize_text_field');
        $shared = $this->taskShareService->findSharedTask($token);
        if (!$shared) {
            return $this->notFound(esc_html__('Task not found', 'fluent-boards'));
        }

        $task = $shared['task'];
        $data = $this->serializer->formatTaskDetail($task, $shared['settings']);
        $stage = $task->stage_id ? Stage::find($task->stage_id) : null;
        $data['stage_title'] = $stage ? $stage->title : '';

        return [
            'task'     => $data,
            'settings' => $shared['settings'],
        ];
    }

    public function getSharedTaskComments(Request $request)
    {
        $token = $request->getSafe('share_token', 'sanitize_text_field');
        $shared = $this->taskShareService->findSharedTask($token);
        if (!$shared || !$shared['settings']['task_comments']) {
            return $this->notFound(esc_html__('Task not found', 'fluent-boards'));
        }

        $page = $request->getSafe('page', 'intval', 1);

        return $this->serializer->commentsPage($shared['task'], $page);
    }

    /**
     * Hidden features answer as plain 404s. The message goes in an array because
     * WPFluent's REST 404 customizer writes into the response data and fatals on a string.
     */
    private function notFound($message)
    {
        return $this->sendError(['message' => $message], 404);
    }
}
