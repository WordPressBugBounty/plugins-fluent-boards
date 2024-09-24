<?php

// Register the classes to make available for the developers
// The key will be used to access the class, for example:
// FluentBoardsApi('boards') or FluentBoardsApi->boards

return [
    'boards'      => 'FluentBoards\App\Api\Classes\Boards',
    'tasks'      => 'FluentBoards\App\Api\Classes\Tasks',
    'stages'      => 'FluentBoards\App\Api\Classes\Stages',
];
