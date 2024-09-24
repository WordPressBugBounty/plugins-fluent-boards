<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Models\Webhook;
use FluentBoards\Framework\Http\Request\Request;


class WebhookController extends Controller
{
    public function index(Request $request, Webhook $webhook)
    {
        $fields = $webhook->getFields();
        $search = $request->getSafe('search', '');

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
        $webhook = $webhook->store(
            $this->validate(
                $request->all(),
                ['name' => 'required']
            )
        );

        return [
            'id'       => $webhook->id,
            'webhook'  => $webhook->value,
            'webhooks' => $webhook->latest()->get(),
            'message'  => __('Successfully Created the WebHook', 'fluent-boards'),
        ];
    }

    public function update(Request $request, Webhook $webhook, $id)
    {
        $webhook->find($id)->saveChanges($request->all());

        return [
            'webhooks' => $webhook->latest()->get(),
            'message'  => __('Successfully updated the webhook', 'fluent-boards'),
        ];
    }

    public function delete(Webhook $webhook, $id)
    {
        $webhook->where('id', $id)->delete();

        return [
            'webhooks' => $webhook->latest()->get(),
            'message'  => __('Successfully deleted the webhook', 'fluent-boards'),
        ];
    }
}
