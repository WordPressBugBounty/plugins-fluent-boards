<?php

namespace FluentBoards\App\Hooks\Cli;

use FluentBoards\App\Models\Relation;
use FluentBoards\App\Services\Constant;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\Tag;
use WP_CLI;

use FluentBoards\App\Models\Board;

class Commands
{
    public function crm_role_assign()
    {
        if (!defined('FLUENTCRM')) {
            \WP_CLI::error('FluentCRM is not installed');
        }
        
        if (!defined('FLUENT_BOARDS_PRO')) {
            \WP_CLI::error('FluentBoards Pro is required');
        }

        \WP_CLI::line('You are about to add Members to your Project Boards from FluentCRM Tags');
        \WP_CLI::line('Please select the Project Board you want to add Members to:');

        $boards = Board::orderBy('title', 'asc')->get();

        $boardHtml = '';

        foreach ($boards as $board) {
            $boardHtml .= $board->id . ' : ' . $board->title . "\n";
        }

        \WP_CLI::line($boardHtml);

        // asl for the board id
        \WP_CLI::line('Enter the ID of the board you want to add members to:');
        $boardId = trim(fgets(STDIN));  // Read input from the user

        if (!is_numeric($boardId) || !$selectedBoard = Board::find($boardId)) {
            \WP_CLI::error('Invalid Board ID');
        }

        $tags = Tag::orderBy('title', 'asc')->get();

        $tagHtml = '';
        foreach ($tags as $tag) {
            $tagHtml .= $tag->id . ' : ' . $tag->title . "\n";
        }

        \WP_CLI::line($tagHtml);


        \WP_CLI::line('Enter the Tag ID by which the contacts will be added as the member of the select board:');

        $tagId = trim(fgets(STDIN));  // Read input from the user

        if (!is_numeric($tagId) || !$selectedTag = Tag::find($tagId)) {
            \WP_CLI::error('Invalid Board ID');
        }


        $contacts = Subscriber::filterByTags([$selectedTag->id])
            ->has('user')
            ->get();

        $tableData = [];

        foreach ($contacts as $contact) {
            $tableData[] = [
                'UserID' => $contact->user_id,
                'Email'  => $contact->email,
                'Name'   => $contact->first_name . ' ' . $contact->last_name
            ];
        }

        \WP_CLI::line('Following contacts will be added to the board as members:');
        \WP_CLI\Utils\format_items('table', $tableData, ['UserID', 'Email', 'Name']);

        \WP_CLI::line('Do you want to proceed? (yes/no)');

        $confirmation = trim(fgets(STDIN));  // Read input from the user

        if ($confirmation != 'yes') {
            \WP_CLI::line('Operation Cancelled');
            return;
        }

        $userIds = $contacts->pluck('user_id')->toArray();


        $meta = [
            'object_id'   => $board->id,
            'object_type' => Constant::OBJECT_TYPE_BOARD_USER,
            'settings'    => Constant::BOARD_USER_SETTINGS,
            'preferences' => Constant::BOARD_NOTIFICATION_TYPES
        ];


        $added = 0;

        foreach ($contacts as $contact) {
            $relation = Relation::where('object_id', $selectedBoard->id)
                ->where('object_type', 'board_user')
                ->where('foreign_id', $contact->user_id)
                ->first();

            if ($relation) {
                continue;
            }
            $meta['foreign_id'] = $contact->user_id;
            Relation::create($meta);
            $added++;
        }

        \WP_CLI::success('Operation Completed. ' . $added . ' contacts added to the board');
    }
}
