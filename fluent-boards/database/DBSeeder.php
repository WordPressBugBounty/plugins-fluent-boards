<?php

namespace FluentBoards\Database;

use FluentBoards\App\Models\Relation;

class DBSeeder
{
    public static function run()
    {
//        $permissions = ['board_admin', 'create_tasks', 'edit_tasks', 'delete_tasks', 'manage_permissions'];
//        $boardUserExists = BoardUser::where('board_id', null)->where('status', 'ACTIVE')->exists();
//
//        if(!$boardUserExists){
//            $boardUser = BoardUser::create([
//                'board_id' => null,
//                'user_id' => get_current_user_id(),
//                'status' => 'ACTIVE',
//                'permissions' => serialize($permissions),
//                'is_admin' => 1
//            ]);
//        }
    }
}
