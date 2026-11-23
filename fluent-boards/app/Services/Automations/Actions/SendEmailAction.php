<?php

namespace FluentBoards\App\Services\Automations\Actions;

use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\Automations\AutomationContext;
use FluentBoards\App\Services\Automations\BaseAction;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\PermissionManager;

class SendEmailAction extends BaseAction
{
    protected $actionName = 'send_email';

    /** Who one email step sends to. Another audience means another email step. */
    const RECIPIENT_TYPES = ['task_assignees', 'board_managers', 'board_members', 'custom'];

    public function getBlock()
    {
        return [
            'category' => __('Notification', 'fluent-boards'),
            'title' => __('Send email', 'fluent-boards'),
            'description' => __('Send an email when the automation reaches this action.', 'fluent-boards'),
        ];
    }

    public function getSettingsDefaults()
    {
        return ['recipient_type' => 'task_assignees'];
    }

    public function getFields()
    {
        return [
            [
                'key' => 'recipient_type',
                'type' => 'radio',
                'label' => __('Send to', 'fluent-boards'),
                // Steps saved before this choice existed only had typed addresses.
                'default' => 'custom',
                'options' => [
                    ['id' => 'task_assignees', 'title' => __('Task assignees', 'fluent-boards')],
                    ['id' => 'board_managers', 'title' => __('Board managers', 'fluent-boards')],
                    ['id' => 'board_members', 'title' => __('Board members', 'fluent-boards')],
                    ['id' => 'custom', 'title' => __('Custom email addresses', 'fluent-boards')],
                ],
            ],
            [
                'key' => 'user_ids',
                'type' => 'multi-select',
                'option_key' => 'users',
                'label' => __('Members', 'fluent-boards'),
                'placeholder' => __('Search members', 'fluent-boards'),
                'show_if' => ['recipient_type' => 'board_members'],
            ],
            [
                'key' => 'to',
                'type' => 'textarea',
                'label' => __('Email addresses', 'fluent-boards'),
                'placeholder' => __('one@example.com, two@example.com', 'fluent-boards'),
                'show_if' => ['recipient_type' => 'custom'],
            ],
            [
                'key' => 'subject',
                'label' => __('Subject', 'fluent-boards'),
                'placeholder' => __('Task update: {{task.title}}', 'fluent-boards'),
                'smart_codes' => true,
            ],
            [
                'key' => 'body',
                'type' => 'textarea',
                'label' => __('Body', 'fluent-boards'),
                'placeholder' => __('Write the email body...', 'fluent-boards'),
                'smart_codes' => true,
            ],
        ];
    }

    public function handle(AutomationContext $context, array $settings)
    {
        $recipients = $this->resolveRecipients($context, $settings);
        $subject = sanitize_text_field($context->replaceSmartCodes($settings['subject'] ?? ''));
        $body = wp_kses_post($context->replaceSmartCodesInHtml($settings['body'] ?? ''));

        if (!$recipients) {
            // Typed addresses are set up by hand, so none is a broken step. A group (assignees,
            // managers, members) can simply be empty for this task; skip the email, keep the run going.
            if ($this->recipientType($settings) === 'custom') {
                throw new \Exception(esc_html__('No valid email recipients found.', 'fluent-boards'));
            }

            return [
                'queued' => false,
                'skipped' => __('No one to email: the chosen group is empty for this task.', 'fluent-boards'),
            ];
        }

        if (!$subject || !$body) {
            throw new \Exception(esc_html__('Email subject and body are required.', 'fluent-boards'));
        }

        // Sent from the queue (AutomationHandler::sendEmail) so a slow mail server never holds up
        // the request that fired the trigger, e.g. a task drag.
        as_enqueue_async_action(
            'fluent_boards/automation_send_email',
            [$recipients, $subject, wpautop($body)],
            'fluent-boards'
        );

        return [
            'queued' => true,
            'recipients' => $recipients,
        ];
    }

    /** Activation check: a known recipient type, with members picked or valid addresses typed. */
    public function hasValidRecipients(array $settings)
    {
        $type = $this->recipientType($settings);

        if ($type === 'board_members') {
            return (bool) array_filter(array_map('absint', (array) ($settings['user_ids'] ?? [])));
        }

        if ($type !== 'custom') {
            return in_array($type, self::RECIPIENT_TYPES, true);
        }

        $emails = preg_split('/[\s,;]+/', (string) ($settings['to'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (!$emails) {
            return false;
        }

        foreach ($emails as $email) {
            if (!is_email($email)) {
                return false;
            }
        }

        return true;
    }

    /** The emails for this step's recipient type, resolved against the task and board now. */
    protected function resolveRecipients(AutomationContext $context, array $settings)
    {
        $type = $this->recipientType($settings);
        $boardId = $context->getBoardId();
        $task = $context->getTask();

        if ($type === 'custom') {
            return $this->sanitizeRecipients($settings['to'] ?? '');
        }

        $userIds = [];
        if ($type === 'task_assignees' && $task) {
            $userIds = $task->assignees->pluck('ID')->toArray();
        } elseif ($type === 'board_managers' && $boardId) {
            $userIds = $this->boardManagerIds($boardId);
        } elseif ($type === 'board_members' && $boardId) {
            // Picked people may have left the board since the automation was saved.
            $pickedIds = array_map('absint', (array) ($settings['user_ids'] ?? []));
            $userIds = PermissionManager::getUserIdsWithBoardAccess($boardId, $pickedIds);
        }

        $userIds = array_values(array_unique(array_filter(array_map('absint', $userIds))));
        if (!$userIds) {
            return [];
        }

        $emails = User::whereIn('ID', $userIds)->pluck('user_email')->toArray();

        return $this->sanitizeRecipients(implode(',', $emails));
    }

    /** Missing on steps saved before the choice existed; those only had typed addresses. */
    protected function recipientType(array $settings)
    {
        return sanitize_key($settings['recipient_type'] ?? 'custom');
    }

    /** Only people marked manager on this board; plugin and site admins are not included. */
    protected function boardManagerIds($boardId)
    {
        $members = Relation::where('object_id', $boardId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->get(['foreign_id', 'settings']);

        $managerIds = [];
        foreach ($members as $member) {
            if (!empty($member->settings[Constant::IS_BOARD_ADMIN])) {
                $managerIds[] = (int) $member->foreign_id;
            }
        }

        return $managerIds;
    }

    protected function sanitizeRecipients($recipients)
    {
        $items = preg_split('/[\s,;]+/', (string) $recipients);
        $items = array_filter(array_map(function ($recipient) {
            $recipient = sanitize_email($recipient);

            return is_email($recipient) ? $recipient : null;
        }, $items));

        return array_values(array_unique($items));
    }
}
