<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Services\PermissionManager;
use FluentBoards\App\Services\ReportService;
use FluentBoards\Framework\Http\Request\Request;
use FluentBoardsPro\App\Modules\TimeTracking\Model\TimeTrack;

class ReportController extends Controller
{
    /**
     * Reports → Overview aggregates for the selected board and date range.
     */
    public function getOverviewReport(Request $request)
    {
        return $this->sendReport($request, 'getOverviewReport');
    }

    /**
     * Reports → Tasks aggregates for the selected board and date range.
     */
    public function getTasksReport(Request $request)
    {
        return $this->sendReport($request, 'getTasksReport');
    }

    /**
     * Reports → Activity aggregates for the selected board and date range.
     */
    public function getActivityReport(Request $request)
    {
        return $this->sendReport($request, 'getActivityReport');
    }

    /**
     * Reports → Roadmap aggregates for accessible roadmap boards.
     */
    public function getRoadmapReport(Request $request)
    {
        if (!defined('FLUENT_BOARDS_PRO_VERSION')) {
            return $this->sendError(
                esc_html__('This is a pro feature', 'fluent-boards'),
                403
            );
        }

        try {
            $reportService = new ReportService();
            $scope = $reportService->resolveRoadmapScope([
                'board_id'   => $this->getRequestedBoardId($request),
                'start_date' => $request->getSafe('start_date', 'sanitize_text_field'),
                'end_date'   => $request->getSafe('end_date', 'sanitize_text_field'),
            ]);

            return $this->sendSuccess([
                'report' => $reportService->getRoadmapReport($scope),
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    /**
     * The three report screens share their parameters and their envelope; only
     * the aggregate they ask for differs.
     */
    private function sendReport(Request $request, $method)
    {
        try {
            $reportService = new ReportService();

            $scope = $reportService->resolveScope([
                'board_id'   => $this->getRequestedBoardId($request),
                'start_date' => $request->getSafe('start_date', 'sanitize_text_field'),
                'end_date'   => $request->getSafe('end_date', 'sanitize_text_field'),
            ]);

            return $this->sendSuccess([
                'report' => $reportService->{$method}($scope),
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    /**
     * Preserves a non-empty invalid board filter as an impossible ID so it
     * cannot silently widen a report to every accessible board.
     */
    private function getRequestedBoardId(Request $request)
    {
        $rawBoardId = $request->get('board_id');
        $boardId = $request->getSafe('board_id', 'intval');

        if ($rawBoardId !== null && $rawBoardId !== '' && !$boardId) {
            return -1;
        }

        return $boardId;
    }

    /**
     * Returns the timesheet report for accessible boards when Pro is active.
     */
    public function getTimeSheetReport(Request $request)
    {
        if (!defined('FLUENT_BOARDS_PRO_VERSION')) {
            return $this->sendError(
                esc_html__('This is a pro feature', 'fluent-boards'),
                403
            );
        }

        // Sanitize date inputs - validate they are valid date strings
        $startDate = $request->getSafe('start_date', 'sanitize_text_field');
        $endDate = $request->getSafe('end_date', 'sanitize_text_field');
        
        // Validate date format (YYYY-MM-DD) and ensure dates are actually valid
        if ($startDate) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
                $startDate = null;
            } else {
                // Validate that the date is actually valid (e.g., not "2024-13-45")
                $dateParts = explode('-', $startDate);
                if (count($dateParts) !== 3 || !checkdate((int)$dateParts[1], (int)$dateParts[2], (int)$dateParts[0])) {
                    $startDate = null;
                }
            }
        }
        if ($endDate) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                $endDate = null;
            } else {
                // Validate that the date is actually valid (e.g., not "2024-13-45")
                $dateParts = explode('-', $endDate);
                if (count($dateParts) !== 3 || !checkdate((int)$dateParts[1], (int)$dateParts[2], (int)$dateParts[0])) {
                    $endDate = null;
                }
            }
        }

        $authUser = wp_get_current_user();
        $boardIds = PermissionManager::getBoardIdsForUser($authUser->ID);
        $boardId = $request->getSafe('board_id', 'intval');
        if (!empty($boardId)) {
            $boardIds = PermissionManager::getBoardIdsForUser($authUser->ID, $boardId);
        }
        $timings = TimeTrack::where('status', 'commited')->whereIn('board_id', $boardIds);
        if ($startDate && $endDate) {
            $timings = $timings->whereBetween('completed_at', [$startDate, $endDate]);
        }
        $timings = $timings->get();
        $timings = $timings->load('task', 'user', 'board');

        $sortTasks = [];
        foreach ($timings as $timing) {
            $times = $timings->where('task_id', $timing->task_id);

            if (!isset($sortTasks[$timing['task_id']])) {
                $sortTasks[$timing['task_id']] = [
                    'id'    => $timing['task_id'],
                    'title' => $timing->task->title,
                    'board' => $timing->board,
                    'total' => 0,
                    'times' => []
                ];
            }

            $sortTasks[$timing['task_id']]['total'] += $timing['billable_minutes'];

            $formattedTimes = [];
            foreach ($times as $time) {
                $user = $time->user;

                $formattedTimes[] = [
                    'id' => $time['id'],
                    'billable_minutes' => $time['billable_minutes'],
                    'working_minutes'  => $time['working_minutes'],
                    'completed_at'     => $time['completed_at'],
                    'message'          => $time['message'],
                    'user' => [
                        'ID'     => $user->ID,
                        'name'   => $user->display_name,
                        'avatar' => fluent_boards_user_avatar($user->user_email),
                        'email'  => $user->user_email
                    ]
                ];

            }
            $sortTasks[$timing['task_id']]['times'] = $formattedTimes;
        }

        $sortTasks = array_values($sortTasks);

        return $this->sendSuccess([
            'message' => 'Time sheet report',
            'timings' => $sortTasks
        ], 200);
    }

}
