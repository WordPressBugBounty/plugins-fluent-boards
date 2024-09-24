<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\User;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Constant;

class FluentCrmIntegration
{
    public function registerCustomSection()
    {
        $key = 'fluent_boards_in_fluent_crm';
        $sectionTitle = __('FluentBoards', 'fluent-boards');
        FluentCrmApi('extender')->addProfileSection($key, $sectionTitle, function ($contentArr, $subscriber) {
            $contentArr['heading'] = __('Fluent Boards', 'fluent-boards');
            $contentArr['content_html'] = $this->prepareHtml($subscriber);

            return $contentArr;
        });
    }

    public function prepareHtml($subscriber)
    {
        $temp = Meta::query()->where('value', $subscriber->id)
                             ->where('key', Constant::BOARD_ASSOCIATED_CRM_CONTACT)
                             ->where('object_type', Constant::OBJECT_TYPE_BOARD)
                             ->pluck('object_id');

        $boards = Board::query()->whereIn('id', $temp)->get();

        $taskGroups = Task::where('crm_contact_id', $subscriber->id)->with('board')->get()->groupBy((function ($data) {
            return $data->board->title;
        }));



        $html = '';
        $html .= '<style>
                    .fbs_associated_boards_wrap {
                        background: #FFFFFF;
                        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
                        border-radius: 10px;
                        overflow: hidden;
                        margin-bottom: 30px;
                    }
                    .fbs_associated_boards_wrap .fbs_associated_board_header {
                        display: flex;
                        gap: 10px;
                        align-items: center;
                        justify-content: space-between;
                        padding: 14px 16px;
                    }
                    .fbs_associated_boards_wrap .fbs_associated_board_header h3 {
                        font-weight: 500;
                        font-size: 16px;
                        line-height: 140%;
                        color: #1F2022;
                        margin: 0;
                    }
                    .fbs_associated_boards_wrap .fbs_associated_board_header .fbs-go-to-board {
                        display: block;
                        background: #409eff;
                        color: #ffffff;
                        border-radius: 4px;
                        padding: 4px 10px;
                        font-size: 12px;
                    }
                    .fbs_associated_board_no_board{
                        background: #F6F7FA;
                        padding: 5px 15px;
                    }
                    .fbs_associated_board_all_boards{
                        padding: 15px 10px;
                        border-top: 2px solid #F6F7FA;
                    }
                    .fbs_associated_single-board{
                        height: auto;
                        padding: 5px;
                        line-height: 24px;
                        font-size: 11px;
                        white-space: normal;
                        word-break: break-all;
                        background: #e6ebf0;
                        margin-right: 10px;
                        color: #3c434a;
                    }
                    .fbs_associated_boards_wrap table {
                        width: 100%;
                        border-collapse: collapse; 
                        background: white;
                    }
                    .fbs_associated_boards_wrap table thead tr {
                        border: none;
                    }
                    .fbs_associated_boards_wrap table thead tr th {
                        border: none;
                        background: #F6F7FA;
                        font-weight: 600;
                        font-size: 12px;
                        line-height: 120%;
                        color: #60646B;
                        padding: 13px 16px;
                    }
                    .fbs_associated_boards_wrap table thead tr th:first-child {
                        text-align: left;
                    }
                    .fbs_associated_boards_wrap table tbody tr td {
                        text-align: center;
                        font-weight: 400;
                        font-size: 14px;
                        line-height: 120%;
                        color: #60646B;
                        padding: 13px 16px;
                        border-bottom: 1px solid #E5E9EF;
                    }
                    .fbs_associated_boards_wrap table tbody tr td a {
                        color: #60646B;
                        display: block;
                    }
                    .fbs_associated_boards_wrap table tbody tr td a:hover {
                        color: #3C90FF;
                    }
                    .fbs_associated_boards_wrap table tbody tr td:first-child {
                        text-align: left;
                    }
                    </style>';

        $html .= '<div class="fbs_associated_boards_wrap">';
        $html .= '<div class="fbs_associated_board_header">
                      <h3>All Boards</h3>
                  </div>';
        if (!$boards || $boards->isEmpty()) {
            $html .= '<div class="fbs_associated_board_no_board">
                          <p>No Board is associated with this contact</p>
                      </div>';
        }else{
            $html .= '<div class="fbs_associated_board_all_boards">';
            foreach ($boards as $board) {
                $html .= '<a href="'. fluent_boards_page_url() .'boards/'. $board->id .'" class="fbs_associated_single-board">'. $board->title .'</a>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';

        if (!$taskGroups || $taskGroups->isEmpty()) {
            $html .= '<div>
                    <p>No associated Tasks with this contact</p>
               </div>
               ';
        }

        foreach ($taskGroups as $board) {
            $html .= '<div class="fbs_associated_boards_wrap">';
            $html .= '<div class="fbs_associated_board_header">
                        <h3>' . $board[0]->board->title . '</h3>
                        <a href="' . fluent_boards_page_url() . 'boards/' . $board[0]->board->id . '" class="fbs-go-to-board">
                            Go To Board
                        </a>
                    </div>';
            $html .= '<table>';
            $html .= '<thead>
					<tr>
						<th style="width: 250px;">' . __('Task Title', 'fluent-boards') . '</th>
						<th style="width: 130px;">' . __('Stage', 'fluent-boards') . '</th>
						<th style="width: 120px;">' . __('Start Date', 'fluent-boards') . '</th>
						<th style="width: 130px;">' . __('Due Date', 'fluent-boards') . '</th>
						<th style="width: 100px;">' . __('Priority', 'fluent-boards') . '</th>
					</tr>
				</thead>';
            $html .= '<tbody>';
            foreach ($board as $task) {
                $start = $task->start_at ? gmdate('jS F Y h:i A', strtotime($task->start_at)) : 'n/a';
                $due = $task->due_date ? gmdate('jS F Y h:i A', strtotime($task->due_date)) : 'n/a';
                $task->url = fluent_boards_page_url() . 'boards/' . $task->board_id . '/tasks/' . $task->id . '-' . substr(sanitize_title($task->title), 0, 40);
                $html .= '<tr>';
                $html .= "<td><a href=\"{$task->url}\">{$this->substring($task->title, 40)}</a></td>";
                $html .= "<td>{$task->stage->title}</td>";
                $html .= '<td>' . $start . '</td>';
                $html .= '<td>' . $due . '</td>';
                $html .= "<td>{$task->priority}</td>";

                $html .= '</tr>';
            }
            $html .= '</tbody>';
            $html .= '</table>';
            $html .= '</div>';
        }

        return $html;
    }

    public function substring($string, $length)
    {
        if (strlen($string) > $length) {
            return substr($string, 0, $length) . '...';
        }

        return $string;
    }
}
