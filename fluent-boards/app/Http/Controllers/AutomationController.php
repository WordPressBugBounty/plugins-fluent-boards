<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Services\Automations\AutomationFieldOptions;
use FluentBoards\App\Services\Automations\AutomationRegistry;
use FluentBoards\App\Services\Automations\AutomationService;
use FluentBoards\Framework\Http\Request\Request;

class AutomationController extends Controller
{
    protected $service;

    public function __construct(AutomationService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $page = max(1, $request->getSafe('page', 'intval', 1));
        $search = $request->getSafe('search', 'sanitize_text_field', '');
        $status = $request->getSafe('status', 'sanitize_key', '');
        $matchingTriggers = (array) $request->get('matching_triggers', []);

        return $this->service->getAutomationPage($page, $search, $status, $matchingTriggers);
    }

    public function registry()
    {
        return [
            'registry' => AutomationRegistry::getInstance()->toArray(),
        ];
    }

    public function options(Request $request, $optionKey)
    {
        return [
            'options' => (new AutomationFieldOptions())->get($optionKey, [
                'board_id' => $request->getSafe('board_id', 'intval'),
                'search' => $request->getSafe('search', 'sanitize_text_field'),
                'include' => array_map('absint', (array) $request->get('include', [])),
            ]),
        ];
    }

    public function find($id)
    {
        $automation = $this->service->find($id);

        if (!$automation) {
            return $this->sendError(__('Automation not found', 'fluent-boards'), 404);
        }

        return [
            'automation' => $automation,
            'logs' => $this->service->logs($automation->id, 10),
        ];
    }

    public function activityLogs(Request $request)
    {
        return [
            'logs' => $this->service->activityLogs(
                $request->getSafe('limit', 'intval'),
                $request->getSafe('automation_id', 'intval')
            ),
        ];
    }

    public function analytics(Request $request)
    {
        return [
            'analytics' => $this->service->analytics(
                $request->getSafe('limit', 'intval'),
                $request->getSafe('automation_id', 'intval')
            ),
        ];
    }

    public function create(Request $request)
    {
        try {
            $automation = $this->service->create((array) $request->get('automation', []));

            return [
                'automation' => $automation,
                'message' => __('Automation created', 'fluent-boards'),
            ];
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $automation = $this->service->update($id, (array) $request->get('automation', []));

            return [
                'automation' => $automation,
                'message' => __('Automation updated', 'fluent-boards'),
            ];
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function bulkActions(Request $request)
    {
        try {
            $rawAutomationIds = $request->getSafe('automation_ids');
            $automationIds = is_array($rawAutomationIds) ? array_filter(array_map('intval', $rawAutomationIds)) : [];
            $action = $request->getSafe('action', 'sanitize_text_field');

            return $this->sendSuccess($this->service->bulkActions($automationIds, $action));
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function duplicate($id)
    {
        try {
            $automation = $this->service->duplicate($id);

            return [
                'automation' => $automation,
                'message' => __('Automation duplicated', 'fluent-boards'),
            ];
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function delete($id)
    {
        try {
            $this->service->delete($id);

            return [
                'message' => __('Automation deleted', 'fluent-boards'),
            ];
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function logs($id)
    {
        return [
            'logs' => $this->service->logs($id),
        ];
    }
}
