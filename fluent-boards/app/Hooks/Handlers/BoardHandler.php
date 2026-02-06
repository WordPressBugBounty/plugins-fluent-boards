<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Models\Attachment;
use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\NotificationUser;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Helper;

class BoardHandler
{
    public function createLogActivity($boardId, $action, $column = null, $oldValue = null, $newValue = null, $description = null, $settings = null, $userId = null )
    {
        $data = [
            'object_type' => Constant::ACTIVITY_BOARD,
            'object_id' => $boardId,
            'action' => $action, // action type: changed, updated, added, removed, created
            'column' => $column,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'description' => $description,
            'settings' => $settings
        ];
        if($userId) {
            $data['created_by'] = $userId;
        }

        Helper::createActivity($data);
    }
    public function getAllBoards($sortBy = 'ASC')
    {
        $boards = Board::orderBy('created_at', $sortBy)->get();

        return [
            'boards' => $boards,
        ];
    }

    public function boardCreated($board)
    {
        $boardId = $board->id;
        $this->createLogActivity($boardId, 'created', 'board', null, null, $board->title);
        $this->updateOnboarding();
    }

    private function updateOnboarding()
    {
        $onboarding = Meta::where('key', Constant::FBS_ONBOARDING)->first();
        if($onboarding && $onboarding->value == 'no'){
            $onboarding->value = 'yes' ;
            $onboarding->save();
        }
    }

    public function taskCreatedOnBoard($task)
    {
        $boardId = $task->board_id;
        $this->updateBoardTaskCount($boardId, 1);
        $taskStage = $task->stage;
        $settings = ['task_id' => $task->id];
        $this->createLogActivity($boardId, 'created', 'task', null, $task->title, 'on stage '.$taskStage->title, $settings, $task->created_by);
    }

    public function boardStagesReOrdered($boardId, $oldStageOrders)
    {
        $stages = [];
        foreach ($oldStageOrders as $oldStageId){
            $stage = Stage::findOrFail($oldStageId);
            $stages[] = $stage->title;
        }
        $this->createLogActivity($boardId,'moved', 'stages', implode(',', $stages));
    }

    public function beforeBoardDeleted($board, $options = [])
    {
        // do something
    }

    public function boardDeleted($board)
    {
        // do something // commented for better code
        $taskIdsByBoard = (array) Task::where('board_id', '=', $board->id)->pluck('id')->toArray();
        foreach ($taskIdsByBoard as $taskId) {
            $task = Task::find($taskId);
            $task->delete();
            do_action('fluent_boards/task_deleted', $task);
        }
//        TaskActivity::whereIn('task_id', $taskIdsByBoard)->delete();
//        TaskAssignee::whereIn('task_id', $taskIdsByBoard)->delete();
//        Task::destroy($taskIdsByBoard);
    }

    public function boardUpdated($board, $oldBoard)
    {
        $boardId = $board->id;
        $titleChanged = false;
        $descriptionChanged = ($board->description != $oldBoard->description) ? true : false;
        if ($board->title != $oldBoard->title) {
            $titleChanged = true;
        }
        if ($descriptionChanged) {
            $this->createLogActivity($boardId, 'changed', 'description',null , $oldBoard->description, $board->description);
        }
        if ($titleChanged) {
            $this->createLogActivity($boardId, 'changed', 'title', $oldBoard->title, $board->title);
        }
    }

    public function boardStageUpdated($boardId, $newStage, $oldStage)
    {
        $newStageLabel = $newStage['title'];
        $oldStageLabel = $oldStage->title;
        $this->createLogActivity($boardId, 'updated', 'title of stage', $oldStageLabel, $newStageLabel);
    }

	public function boardStageAdded($board, $stage)
	{
		$this->createLogActivity($board->id,'created', 'stage', null, $stage->title);
	}
    public function boardStageMoved($stage, $direction)
    {
        $this->createLogActivity($stage->board_id,'moved', $stage->title.' stage', $direction);
    }

    public function boardStageDeleted($board, $stageTobeDeleted)
    {
        $this->createLogActivity($board->id,'deleted', 'stage', $stageTobeDeleted);
    }

    public function boardStageArchived($boardId, $stage)
    {
        $stageTitle = $stage->title;
        $this->createLogActivity($boardId,'archived', 'stage', $stageTitle);
    }

    public function boardArchivedStageRestore($boardId, $stageTitle)
    {
        $this->createLogActivity($boardId,'restored', 'stage', $stageTitle);
    }

    public function boardMemberAdded($boardId, $boardMember)
    {
        $this->createLogActivity($boardId,'added', 'member', $boardMember->dispaly_name, null, '');
    }

    public function boardViewerAdded($boardId, $boardMember)
    {
        $this->createLogActivity($boardId,'added', 'viewer', $boardMember->dispaly_name, null, '');

    }

    public function boardMemberRemoved($boardId, $memberName)
    {
        $this->createLogActivity($boardId,'removed', 'member', $memberName, 'from board');
    }

    public function boardAdminRemoved($boardId, $memberId)
    {
        $boardMember = $this->getMember($memberId);
        $this->createLogActivity($boardId,'demoted', $boardMember->display_name, 'Manager', 'Member');
    }

    public function boardAdminAdded($boardId, $memberId)
    {
        $boardMember = $this->getMember($memberId);
        $this->createLogActivity($boardId,'promoted', $boardMember->display_name, 'Member','Manager');
    }

    private function getMember($memberId)
    {
        return User::find($memberId);
    }

    public function beforeTaskDeleted($task, $options = [])
    {
        //do something
    }

    public function taskdeleted($task)
    {
        if (!$task->parent_id) {
            $this->createLogActivity($task->board_id, 'deleted', 'task', $task->title);
        }
    }

    public function taskArchivedOnBoard($task)
    {
        if(!$task->archived_at){
            $this->createLogActivity($task->board_id, 'restored', 'task', $task->title);
            $this->updateBoardTaskCount($task->board_id, 1);
        }else{
            $this->createLogActivity($task->board_id, 'archived', 'task', $task->title);
            $this->updateBoardTaskCount($task->board_id, -1);
        }
    }

    public function boardLabelCreatedActivity($label)
    {
        $column = 'label';
        $settings = [
            'bg_color' => $label->bg_color,
            'color' => $label->color,
            'title' => $label->title ?? ''
        ];
        $this->createLogActivity(
            $label->board_id,
            'created',
            $column,
            null,
            null,
            null,
            $settings
        );
    }

    public function boardLabelUpdatedActivity($label)
    {
        $column = 'label';
        $settings = [
            'bg_color' => $label->bg_color,
            'color' => $label->color,
            'title' => $label->title ?? ''
        ];
        $this->createLogActivity(
            $label->board_id,
            'updated',
            $column,
            null,
            null,
            null,
            $settings
        );
    }

    public function boardLabelDeletedActivity($label)
    {
        $column = 'label';
        $settings = [
            'bg_color' => $label->bg_color,
            'color' => $label->color,
            'title' => $label->title ?? ''
        ];
        $this->createLogActivity(
            $label->board_id,
            'deleted',
            $column,
            null,
            null,
            null,
            $settings
        );
    }

    public function defaultAssigneesUpdated($stage, $assignees)
    {
        $column = 'default assignees of stage';

        $this->createLogActivity(
            $stage->board_id,
            'updated',
            $column,
            null,
            null,
            $stage->title
        );
    }

    public static function getCurrencies()
    {
        return [
            'AED' => 'United Arab Emirates Dirham',
            'AFN' => 'Afghan Afghani',
            'ALL' => 'Albanian Lek',
            'AMD' => 'Armenian Dram',
            'ANG' => 'Netherlands Antillean Gulden',
            'AOA' => 'Angolan Kwanza',
            'ARS' => 'Argentine Peso', // non amex
            'AUD' => 'Australian Dollar',
            'AWG' => 'Aruban Florin',
            'AZN' => 'Azerbaijani Manat',
            'BAM' => 'Bosnia & Herzegovina Convertible Mark',
            'BBD' => 'Barbadian Dollar',
            'BDT' => 'Bangladeshi Taka',
            'BIF' => 'Burundian Franc',
            'BGN' => 'Bulgarian Lev',
            'BMD' => 'Bermudian Dollar',
            'BND' => 'Brunei Dollar',
            'BOB' => 'Bolivian Boliviano',
            'BRL' => 'Brazilian Real',
            'BSD' => 'Bahamian Dollar',
            'BWP' => 'Botswana Pula',
            'BZD' => 'Belize Dollar',
            'CAD' => 'Canadian Dollar',
            'CDF' => 'Congolese Franc',
            'CHF' => 'Swiss Franc',
            'CLP' => 'Chilean Peso',
            'CNY' => 'Chinese Renminbi Yuan',
            'COP' => 'Colombian Peso',
            'CRC' => 'Costa Rican Colón',
            'CVE' => 'Cape Verdean Escudo',
            'CZK' => 'Czech Koruna',
            'DJF' => 'Djiboutian Franc',
            'DKK' => 'Danish Krone',
            'DOP' => 'Dominican Peso',
            'DZD' => 'Algerian Dinar',
            'EGP' => 'Egyptian Pound',
            'ETB' => 'Ethiopian Birr',
            'EUR' => 'Euro',
            'FJD' => 'Fijian Dollar',
            'FKP' => 'Falkland Islands Pound',
            'GBP' => 'British Pound',
            'GEL' => 'Georgian Lari',
            'GIP' => 'Gibraltar Pound',
            'GMD' => 'Gambian Dalasi',
            'GNF' => 'Guinean Franc',
            'GTQ' => 'Guatemalan Quetzal',
            'GYD' => 'Guyanese Dollar',
            'HKD' => 'Hong Kong Dollar',
            'HNL' => 'Honduran Lempira',
            'HRK' => 'Croatian Kuna',
            'HTG' => 'Haitian Gourde',
            'HUF' => 'Hungarian Forint',
            'IDR' => 'Indonesian Rupiah',
            'ILS' => 'Israeli New Sheqel',
            'INR' => 'Indian Rupee',
            'ISK' => 'Icelandic Króna',
            'JMD' => 'Jamaican Dollar',
            'JPY' => 'Japanese Yen',
            'KES' => 'Kenyan Shilling',
            'KGS' => 'Kyrgyzstani Som',
            'KHR' => 'Cambodian Riel',
            'KMF' => 'Comorian Franc',
            'KRW' => 'South Korean Won',
            'KYD' => 'Cayman Islands Dollar',
            'KZT' => 'Kazakhstani Tenge',
            'LAK' => 'Lao Kip',
            'LBP' => 'Lebanese Pound',
            'LKR' => 'Sri Lankan Rupee',
            'LRD' => 'Liberian Dollar',
            'LSL' => 'Lesotho Loti',
            'MAD' => 'Moroccan Dirham',
            'MDL' => 'Moldovan Leu',
            'MGA' => 'Malagasy Ariary',
            'MKD' => 'Macedonian Denar',
            'MNT' => 'Mongolian Tögrög',
            'MOP' => 'Macanese Pataca',
            'MRO' => 'Mauritanian Ouguiya',
            'MUR' => 'Mauritian Rupee',
            'MVR' => 'Maldivian Rufiyaa',
            'MWK' => 'Malawian Kwacha',
            'MXN' => 'Mexican Peso',
            'MYR' => 'Malaysian Ringgit',
            'MZN' => 'Mozambican Metical',
            'NAD' => 'Namibian Dollar',
            'NGN' => 'Nigerian Naira',
            'NIO' => 'Nicaraguan Córdoba',
            'NOK' => 'Norwegian Krone',
            'NPR' => 'Nepalese Rupee',
            'NZD' => 'New Zealand Dollar',
            'PAB' => 'Panamanian Balboa',
            'PEN' => 'Peruvian Nuevo Sol',
            'PGK' => 'Papua New Guinean Kina',
            'PHP' => 'Philippine Peso',
            'PKR' => 'Pakistani Rupee',
            'PLN' => 'Polish Złoty',
            'PYG' => 'Paraguayan Guaraní',
            'QAR' => 'Qatari Riyal',
            'RON' => 'Romanian Leu',
            'RSD' => 'Serbian Dinar',
            'RUB' => 'Russian Ruble',
            'RWF' => 'Rwandan Franc',
            'SAR' => 'Saudi Riyal',
            'SBD' => 'Solomon Islands Dollar',
            'SCR' => 'Seychellois Rupee',
            'SEK' => 'Swedish Krona',
            'SGD' => 'Singapore Dollar',
            'SHP' => 'Saint Helenian Pound',
            'SLL' => 'Sierra Leonean Leone',
            'SOS' => 'Somali Shilling',
            'SRD' => 'Surinamese Dollar',
            'STD' => 'São Tomé and Príncipe Dobra',
            'SVC' => 'Salvadoran Colón',
            'SZL' => 'Swazi Lilangeni',
            'THB' => 'Thai Baht',
            'TJS' => 'Tajikistani Somoni',
            'TOP' => 'Tongan Paʻanga',
            'TRY' => 'Turkish Lira',
            'TTD' => 'Trinidad and Tobago Dollar',
            'TWD' => 'New Taiwan Dollar',
            'TZS' => 'Tanzanian Shilling',
            'UAH' => 'Ukrainian Hryvnia',
            'UGX' => 'Ugandan Shilling',
            'USD' => 'United States Dollar',
            'UYU' => 'Uruguayan Peso',
            'UZS' => 'Uzbekistani Som',
            'VND' => 'Vietnamese Đồng',
            'VUV' => 'Vanuatu Vatu',
            'WST' => 'Samoan Tala',
            'XAF' => 'Central African Cfa Franc',
            'XCD' => 'East Caribbean Dollar',
            'XOF' => 'West African Cfa Franc',
            'XPF' => 'Cfp Franc',
            'YER' => 'Yemeni Rial',
            'ZAR' => 'South African Rand',
            'ZMW' => 'Zambian Kwacha',
        ];
    }
    public function taskMovedFromBoard($task, $oldBoard, $newBoard)
    {
        // add tasks_count in the board
        $this->updateBoardTaskCount($newBoard->id, 1);
        // subtract tasks_count in the board
        $this->updateBoardTaskCount($oldBoard->id, -1);

        $this->createLogActivity($oldBoard->id, 'moved', 'task', $task->title . ' to ' . $newBoard->title);
        $this->createLogActivity($newBoard->id, 'added', 'task', $task->title . ' from ' . $oldBoard->title);
    }

    public function deleteUserRelatedData($id, $reassign, $user)
    {
        try{
            if(!$id) {
                throw new \Exception(esc_html__('Member Not deleted', 'fluent-boards'));
            }
            Meta::where('object_type', Constant::OBJECT_TYPE_USER)
                ->where('object_id', $id)
                ->delete();
            NotificationUser::where('user_id', $id)->delete();
            Relation::where('foreign_id', $id)
                ->whereIn('object_type', [
                    Constant::OBJECT_TYPE_BOARD_USER,
                    Constant::OBJECT_TYPE_USER_TASK_WATCH,
                    Constant::TASK_ASSIGNEE
                ])->delete();
        }catch (\Exception $e){}
    }
    private function updateBoardTaskCount($boardId, $count)
    {
        $board = Board::find($boardId);
        $settings = $board->settings ?? [];
        $settings['tasks_count'] = $board->tasks->where('parent_id', null)->whereNull('archived_at')->count();
        $board->settings = $settings;
        $board->save();
    }

    public function backgroundUpdated($boardId, $oldBackground)
    {
        if (
            is_array($oldBackground) &&
            !empty($oldBackground['is_image']) &&
            !empty($oldBackground['image_url'])
        ) {
            // Delete attachment if ID exists
            if (!empty($oldBackground['id'])) {
                $attachment = Attachment::find($oldBackground['id']);
                if ($attachment) {
                    $attachment->delete();
                }
            }

            // Delete file if full_url exists
            if (!empty($oldBackground['full_url'])) {
                (new FileHandler())->deleteFileByUrl($oldBackground['full_url']);
            }
        }

        $this->createLogActivity($boardId, 'changed', 'board background', null, null);
    }


}
