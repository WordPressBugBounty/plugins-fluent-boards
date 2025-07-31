<?php

namespace FluentBoards\App\Hooks\Handlers;

use DateTimeImmutable;
use Exception;
use FluentBoards\App\Models\Activity;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\OptionService;
use FluentCrm\App\Models\Subscriber;
use FluentBoards\App\Models\Relation;
use FluentCrm\App\Services\ContactsQuery;
use FluentCrm\App\Services\PermissionManager as CRMPermissionManager;

class TaskHandler
{
    private $fileHandler;

    public function __construct(FileHandler $fileHandler)
    {
        $this->fileHandler = $fileHandler;
    }
    public function taskDeleted($task)
    {
        // Delete all activities and comments related to this task
        Activity::where('object_id', $task->id)->where('object_type', Constant::ACTIVITY_TASK)->delete();
        Meta::where('object_id', $task->id)->where('object_type', Constant::REPEAT_TASK_META)->delete();
    }

    public function searchAssignees($options, $search, $includedIds)
    {
        $users = get_users([
            'number' => 20,
            'search' => $search,
        ]);

        $formattedUsers = [];

        $pushedIds = [];

        foreach ($users as $user) {
            $pushedIds[] = $user->ID;

            $formattedUsers[] = [
                'id'               => $user->ID,
                'title'            => $user->display_name . ' (' . $user->user_email . ')',
                'photo'            => get_avatar_url($user->user_email),
                'left_side_value'  => $user->display_name,
                'right_side_value' => $user->user_email,
            ];
        }

        return $formattedUsers;

        if (!$includedIds) {
            return $formattedUsers;
        }
        if (!is_array($includedIds)) {
            //	        $includedIds = [$includedIds];
            $includedIds = [$includedIds];
        }

        //	    $includedIds = array_diff($includedIds, $pushedIds);
        //
        if ($includedIds) {
            $users = get_users([
                'include' => $includedIds,
            ]);

            foreach ($users as $user) {
                $formattedUsers[] = [
                    'id3'               => $user->ID,
                    'title'             => $user->display_name . ' (' . $user->user_email . ')',
                    'photo_'            => /* get photo by gravitar or something */ get_avatar_url($user->user_email, ['size' => 50]),
                    'left_side_value'   => $user->display_name,
                    'right_side_value'  => $user->user_email,
                    'right_side_value1' => $user->user_email,
                ];
            }
        }
        //        dd($formattedUsers);

        return $formattedUsers;
    }

    public function searchNonBoardWordpressUsers()
    {
        $superAdmin = Relation::select('user_id')->distinct()->pluck('user_id');
        $users = User::whereDoesntHave('boards')->whereNotIn('ID', $superAdmin)->get();

        $formattedUsers = [];

        foreach ($users as $user) {
            $formattedUsers[] = [
                'id'               => $user->ID,
                'photo'            => $user->photo,
                'title'            => $user->display_name . ' (' . $user->user_email . ')',
                'left_side_value'  => $user->display_name,
                'right_side_value' => $user->user_email,
            ];
        }

        return $formattedUsers;
    }

    public function searchContact($options, $search, $includedIds)
    {
        if (!defined('FLUENTCRM')) {
            return [];
        }

        // check if the use has permission to access contacts
        $contactPermission = CRMPermissionManager::currentUserCan('fcrm_read_contacts');
        if(!$contactPermission) {
            return [];
        }

        $contactsQuery = new ContactsQuery([
            'search' => $search,
            'with'   => [],
            'limit'  => 20,
            'offset' => 0,
        ]);
        $subscribers = $contactsQuery->get();

        $formattedSubscribers = [];

        $pushedIs = [];
        foreach ($subscribers as $subscriber) {
            $pushedIs[] = $subscriber->id;
            $formattedSubscribers[] = [
                'id'               => $subscriber->id,
                'title'            => $subscriber->full_name . ' (' . $subscriber->email . ')',
                'photo'            => $subscriber->photo,
                'name'  => $subscriber->full_name,
                'email' => $subscriber->email,
            ];
        }

        if (!is_array($includedIds)) {
            $includedIds = [$includedIds];
        }

        if (!$includedIds) {
            return $formattedSubscribers;
        }

        $remainingIds = array_diff($includedIds, $pushedIs);

        if ($remainingIds) {
            $remainingContacts = Subscriber::whereIn('id', $remainingIds)->get();
            foreach ($remainingContacts as $subscriber) {
                $formattedSubscribers[] = [
                    'id'               => $subscriber->id,
                    'title'            => $subscriber->full_name . ' (' . $subscriber->email . ')',
                    'photo'            => $subscriber->photo,
                    'name'  => $subscriber->full_name,
                    'email' => $subscriber->email,
                ];
            }
        }

        return $formattedSubscribers;
    }



    /**
     * Summary of taskAttachmentDeleted
     * @param mixed $task
     * @param mixed $deleteUrl
     * @return void
     */
    public function taskAttachmentDeleted($deletedAttachment)
    {
        try {
            $deleteUrl = $deletedAttachment->full_url;
            $this->fileHandler->deleteFileByUrl($deleteUrl);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function onTaskCreated($task)
    {
        $currentSettings = $this->getGlobalNotificationSettings();
        $shouldWatch = isset($currentSettings['watch_on_creating_task']) && $currentSettings['watch_on_creating_task'];

        if ($shouldWatch) {
            $task->watchers()->syncWithoutDetaching([get_current_user_id() => ['object_type' => Constant::OBJECT_TYPE_USER_TASK_WATCH]]);
        }
    }

    public function onCommentCreated($comment)
    {
        $task = $comment->task;
        $currentSettings = $this->getGlobalNotificationSettings();
        $shouldWatch = isset($currentSettings['watch_on_commenting']) && $currentSettings['watch_on_commenting'];

        if ($shouldWatch) {
            $task->watchers()->syncWithoutDetaching([get_current_user_id() => ['object_type' => Constant::OBJECT_TYPE_USER_TASK_WATCH]]);
        }
    }

    public function onAssignAnotherUser($task, $assigneeId)
    {
        $currentSettings = $this->getGlobalNotificationSettings();
        $shouldWatch = isset($currentSettings['watch_on_assigning']) && $currentSettings['watch_on_assigning'];

        if ($shouldWatch) {
            $task->watchers()->syncWithoutDetaching([$assigneeId => ['object_type' => Constant::OBJECT_TYPE_USER_TASK_WATCH]]);
        }
    }

    private function getGlobalNotificationSettings()
    {
        $globalSettings = (new OptionService())->getGlobalNotificationSettings();

        return maybe_unserialize($globalSettings->value);
    }

    public function taskCloned($originalTask, $clonedTask)
    {
        Activity::where('object_id', $clonedTask->id)
            ->where('object_type', Constant::ACTIVITY_TASK)
            ->delete();
        do_action('fluent_boards/task_cloned_activity', $originalTask, $clonedTask);
    }
}
