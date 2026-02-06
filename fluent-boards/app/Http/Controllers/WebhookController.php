<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Models\Webhook;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Relation;
use FluentBoards\Framework\Http\Request\Request;


class WebhookController extends Controller
{
    public function index(Request $request, Webhook $webhook)
    {
        $fields = $webhook->getFields();
        $search = $request->getSafe('search', 'sanitize_text_field', '');

        $webhooks = $webhook->latest()->get()->toArray();
        if ( ! empty($search)) {
            $search   = strtolower($search);
            $webhooks = array_map(function ($row) use ($search) {
                $name = strtolower($row['value']['name']);
                if ($row['value'] && str_contains($name, $search)) {
                    return $row;
                }

                return null;
            }, $webhooks);
        }

        $rows = [];
        foreach ($webhooks as $row) {
            if ($row) {
                $rows[] = $row;
            }
        }


        $response = [
            'webhooks' => $rows,
            'fields'   => $fields['fields']
        ];

        return $response;
    }

    public function create(Request $request, Webhook $webhook)
    {
        $name = $request->name;
        $boardId = $request->getSafe('board', 'intval');
        $stageId = $request->getSafe('stage', 'intval');
        // Sanitize name field
        $name = sanitize_text_field($name);

        if(!$name || !$boardId || !$stageId) {
            return $this->sendError('Name, board and stage are required');
        }

        $webhook = $webhook->store([
            'name' => $name,
            'board' => $boardId,
            'stage' => $stageId,
        ]);

        return [
            'id'       => $webhook->id,
            'webhook'  => $webhook->value,
            'webhooks' => $webhook->latest()->get(),
            'message'  => __('Successfully Created the WebHook', 'fluent-boards'),
        ];
    }

    public function update(Request $request, Webhook $webhook, $id)
    {
        $id = absint($id);
        $data = $request->only(['name']);
        // Sanitize data if needed based on webhook structure
        if (isset($data['name'])) {
            $data['name'] = sanitize_text_field($data['name']);
        }
        $webhook->find($id)->saveChanges($data);

        return [
            'webhooks' => $webhook->latest()->get(),
            'message'  => __('Successfully updated the webhook', 'fluent-boards'),
        ];
    }

    public function delete(Webhook $webhook, $id)
    {
        $id = absint($id);
        $webhook->where('id', $id)->delete();

        return [
            'webhooks' => $webhook->latest()->get(),
            'message'  => __('Successfully deleted the webhook', 'fluent-boards'),
        ];
    }
    
    // Outgoing Webhook Methods
    public function outgoingWebhooks(Request $request, Meta $meta)
    {
        $search = $request->getSafe('search', 'sanitize_text_field', '');
        $boardId = $request->getSafe('board_id', 'intval');
    
        $webhooks = $meta->where('object_type', 'outgoing_webhook')
                         ->latest()
                         ->get()
                         ->toArray();
    
        // Add board filtering logic
        if ($boardId) {
            $webhooks = array_filter($webhooks, function($row) use ($boardId) {
                if (!isset($row['value']['board_id'])) {
                    return false;
                }
                
                $webhookBoardId = $row['value']['board_id'];
                
                // Handle array of board IDs
                if (is_array($webhookBoardId)) {
                    return in_array($boardId, array_map('intval', $webhookBoardId));
                }
                
                // Handle single board ID or null (all boards)
                return $webhookBoardId === null || intval($webhookBoardId) === $boardId;
            });
        }
    
        if (!empty($search)) {
            $search = strtolower($search);
            $webhooks = array_map(function ($row) use ($search) {
                $name = strtolower($row['value']['name']);
                if ($row['value'] && str_contains($name, $search)) {
                    return $row;
                }
                return null;
            }, $webhooks);
        }
    
        $rows = [];
        foreach ($webhooks as $row) {
            if ($row) {
                $rows[] = $row;
            }
        }
    
        return [
            'webhooks' => $rows
        ];
    }

    public function createOutgoingWebhook(Request $request)
    {
        $data = $this->validate(
            $request->only(['name', 'url', 'board_id', 'triggered_events', 'header_type', 'headers']),
            [
                'name' => 'required',
                'url' => 'required',
                'board_id' => 'array',
                'triggered_events' => 'array',
            ]
        );

        $data['name'] = sanitize_text_field($data['name']);
        $data['url'] = sanitize_url($data['url']);
        
        // Sanitize header_type if present
        if (isset($data['header_type'])) {
            $data['header_type'] = sanitize_text_field($data['header_type']);
        }

        if(isset($data['header_type']) && $data['header_type'] == 'with_headers') {
            // sanitize all headers key and value 
            if (!empty($data['headers']) && is_array($data['headers'])) {
                foreach ($data['headers'] as $index => $header) {
                    if (isset($header['name'])) {
                        $data['headers'][$index]['name'] = sanitize_text_field($header['name']);
                    }
                    if (isset($header['value'])) {
                        $data['headers'][$index]['value'] = sanitize_text_field($header['value']);
                    }
                }
            }
        }

        // events cannot be null 
        if (!isset($data['triggered_events']) || empty($data['triggered_events'])) {
            return $this->sendError('At least one event must be selected for the webhook to be triggered.');
        }

        // Sanitize triggered_events array
        if (is_array($data['triggered_events'])) {
            $data['triggered_events'] = array_filter(array_map('sanitize_text_field', $data['triggered_events']));
        }
        $data['triggered_events'] = array_values($data['triggered_events']);

        // Sanitize board_id array to integers
        if (!empty($data['board_id']) && is_array($data['board_id'])) {
            $data['board_id'] = array_filter(array_map('intval', $data['board_id']));
        } else {
            $data['board_id'] = [];
        }

        $webhook = Meta::create([
            'object_type' => 'outgoing_webhook',
            'value' => $data
        ]);

        foreach ($data['board_id'] as $boardId) {
            $boardId = absint($boardId);
            Relation::create([
                'object_id' => $webhook->id,
                'object_type' => 'outgoing_webhook_board',
                'foreign_id' => $boardId,
                'settings' => \maybe_serialize($data['triggered_events'] ?? []),
                'preferences' => ''
            ]);
        }
    

        return [
            'id' => $webhook->id,
            'webhook' => $webhook->value,
            'webhooks' => Meta::where('object_type', 'outgoing_webhook')->latest()->get(),
            'message' => __('Successfully Created the Outgoing WebHook', 'fluent-boards'),
        ];
    }

    public function updateOutgoingWebhook(Request $request, $id)
    {
        $id = absint($id);
        $data = $this->validate(
            $request->all(),
            [
                'name' => 'required',
                'url' => 'required',
                'board_id' => 'array',
                'triggered_events' => 'array',
            ]
        );

        $data['name'] = sanitize_text_field($data['name']);
        $data['url'] = sanitize_url($data['url']);
        
        // Sanitize header_type if present
        if (isset($data['header_type'])) {
            $data['header_type'] = sanitize_text_field($data['header_type']);
        }

        if(isset($data['header_type']) && $data['header_type'] == 'with_headers') {
            // sanitize all headers key and value 
            if (!empty($data['headers']) && is_array($data['headers'])) {
                foreach ($data['headers'] as $index => $header) {
                    if (isset($header['name'])) {
                        $data['headers'][$index]['name'] = sanitize_text_field($header['name']);
                    }
                    if (isset($header['value'])) {
                        $data['headers'][$index]['value'] = sanitize_text_field($header['value']);
                    }
                }
            }
        }

        // Sanitize triggered_events array
        if (is_array($data['triggered_events'])) {
            $data['triggered_events'] = array_filter(array_map('sanitize_text_field', $data['triggered_events']));
        }

        // Sanitize board_id array to integers
        if (!empty($data['board_id']) && is_array($data['board_id'])) {
            $data['board_id'] = array_filter(array_map('intval', $data['board_id']));
        } else {
            $data['board_id'] = [];
        }
    
        $webhook = Meta::find($id);
        $webhook->value = $data;
        $webhook->save();


        // Update relations
        Relation::where('object_type', 'outgoing_webhook_board')
                ->where('object_id', $webhook->id)
                ->delete();

        // Recreate relations
        foreach ($data['board_id'] as $boardId) {
            $boardId = absint($boardId);
            Relation::create([
                'object_id' => $webhook->id,
                'object_type' => 'outgoing_webhook_board',
                'foreign_id' => $boardId,
                'settings' => \maybe_serialize($data['triggered_events'] ?? []),
                'preferences' => ''
            ]);
        }


        return [
            'webhooks' => Meta::where('object_type', 'outgoing_webhook')->latest()->get(),
            'message' => __('Successfully updated the outgoing webhook', 'fluent-boards'),
        ];
    }

    public function deleteOutgoingWebhook($id)
    {
        $id = absint($id);
        $webhook =  Meta::find($id);

        if (!$webhook || $webhook->object_type !== 'outgoing_webhook') {
            return $this->sendError('Outgoing webhook not found.', 404);
        }

        // Delete associated relations
        Relation::where('object_type', 'outgoing_webhook_board')
                ->where('object_id', $webhook->id)
                ->delete();


        $webhook->delete();

        return [
            'webhooks' => Meta::where('object_type', 'outgoing_webhook')->latest()->get(),
            'message' => __('Successfully deleted the outgoing webhook', 'fluent-boards'),
        ];
    }
}
