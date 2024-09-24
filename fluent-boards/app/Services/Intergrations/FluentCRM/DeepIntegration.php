<?php

namespace FluentBoards\App\Services\Intergrations\FluentCRM;


use FluentBoards\App\Models\Board;

class DeepIntegration
{
    public function init()
    {
        add_filter('fluentcrm_ajax_options_boards', [$this, 'getBoards'], 10, 3);
    }

    public function getBoards($records, $search, $includeIds)
    {
        $query = Board::select(['id', 'title']);

        if (!empty($search)) {
            $query->where('title', 'like', "%$search%");
        }

        $boards = $query->orderBy('title', 'ASC')->get();

        return $this->getFormattedBoards($boards);
    }

    public function getFormattedBoards($boards)
    {
        $formattedBoards = [];
        foreach ($boards as $board) {
            $formattedBoards[] = [
                'id'    => strval($board->id),
                'title' => $board->title
            ];
        }
        return $formattedBoards;
    }
}