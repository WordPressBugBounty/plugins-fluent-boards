<?php
namespace FluentBoards\App\Services;

use FluentBoards\App\App;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Models\Task;
use InvalidArgumentException;
use RuntimeException;

class CommentReactionService
{
    /** Allow new reactions only from our hand-maintained emoji list. */
    public function validateEmoji($emoji)
    {
        static $allowed;
        if ($allowed === null) {
            $catalog = json_decode(file_get_contents(__DIR__ . '/comment-reaction-emojis.json'), true);
            $allowed = array_fill_keys(array_column($catalog, 0), true);
        }
        if (!is_string($emoji) || !isset($allowed[$emoji])) {
            throw new InvalidArgumentException(__('Choose a valid emoji.', 'fluent-boards'), 422);
        }
        return $emoji;
    }

    /** Set explicit state for this actor and emoji so repeated requests cannot toggle twice. */
    public function setReaction($boardId, $taskId, $commentId, $emoji, $active)
    {
        $boardId = absint($boardId);
        $taskId = absint($taskId);
        $commentId = absint($commentId);
        if (!in_array($active, [true, false, 1, 0, '1', '0'], true)) {
            throw new InvalidArgumentException(__('Invalid reaction state.', 'fluent-boards'), 422);
        }
        $active = (bool) $active;
        if ($active) {
            $emoji = $this->validateEmoji($emoji);
        } elseif (!is_string($emoji) || $emoji === '' || strlen($emoji) > 64) {
            throw new InvalidArgumentException(__('Choose a valid emoji.', 'fluent-boards'), 422);
        }
        // Previously saved emoji remain removable even when they are not in the current picker.
        $userId = get_current_user_id();
        if (!$userId || !PermissionManager::userHasBoardPermission($boardId, 'POST', $userId)) {
            throw new RuntimeException(__('You cannot react to this comment.', 'fluent-boards'), 403);
        }
        $changed = App::getInstance('db')->transaction(function () use ($boardId, $taskId, $commentId, $emoji, $active, $userId) {
            $board = Board::findOrFail($boardId);
            // Task deletion and moves also lock this row before touching comments.
            $task = Task::where('id', $taskId)->where('board_id', $boardId)->lockForUpdate()->firstOrFail();
            if ($board->archived_at || $task->archived_at) {
                throw new RuntimeException(__('Archived items cannot receive reactions.', 'fluent-boards'), 403);
            }
            Comment::withoutGlobalScopes()->where('id', $commentId)->where('task_id', $taskId)
                ->where('board_id', $boardId)->whereIn('type', ['comment', 'reply'])->lockForUpdate()->firstOrFail();
            // Use a current read after waiting on the task/comment locks; the earlier board read
            // may have established an older REPEATABLE READ snapshot.
            $record = TaskMeta::where('task_id', $taskId)
                ->where('key', Constant::COMMENT_REACTIONS_META_PREFIX . $commentId)->lockForUpdate()->first();
            $reactions = $record ? (array) $record->value : [];
            $emojis = $reactions[$userId] ?? [];
            $emojiKey = bin2hex($emoji);
            $hasReaction = in_array($emojiKey, $emojis, true);
            if ($active === $hasReaction) {
                return false;
            }
            if ($active) {
                $usedKeys = [];
                foreach ($reactions as $actorEmojis) {
                    foreach ((array) $actorEmojis as $key) $usedKeys[$key] = true;
                }
                if (!isset($usedKeys[$emojiKey]) && count($usedKeys) >= Constant::COMMENT_REACTION_LIMIT) {
                    throw new InvalidArgumentException(__('Only 5 different emoji reactions are allowed on a comment.', 'fluent-boards'), 422);
                }
            }
            $emojis = $active ? array_merge($emojis, [$emojiKey]) : array_values(array_diff($emojis, [$emojiKey]));
            if ($emojis) {
                $reactions[$userId] = $emojis;
            } else {
                unset($reactions[$userId]);
            }
            if (!$reactions) {
                $record->delete();
                return true;
            }
            if (!$record) {
                $record = new TaskMeta([
                    'task_id' => $taskId,
                    'key' => Constant::COMMENT_REACTIONS_META_PREFIX . $commentId
                ]);
            }
            // Hex keeps flags, skin tones and joined emoji safe on older database charsets.
            $record->value = $reactions;
            if (!$record->save()) {
                throw new RuntimeException(__('Could not save your reaction.', 'fluent-boards'), 500);
            }
            return true;
        });
        if ($changed) {
            do_action('fluent_boards/comment_reaction_updated', $commentId, $userId, $emoji, $active);
        }
        return $this->summaries([$commentId])[$commentId] ?? [];
    }

    /** Batch task metadata for the requested comments, exposing only counts and the actor's selections. */
    public function summaries(array $commentIds)
    {
        $commentIds = array_values(array_filter(array_unique(array_map('absint', $commentIds))));
        if (!$commentIds) return [];
        $commentTasks = Comment::withoutGlobalScopes()->whereIn('id', $commentIds)->pluck('task_id', 'id')->all();
        $keys = array_map(function ($id) {
            return Constant::COMMENT_REACTIONS_META_PREFIX . $id;
        }, array_keys($commentTasks));
        $rows = TaskMeta::whereIn('task_id', array_values(array_unique($commentTasks)))
            ->whereIn('key', $keys)->get(['task_id', 'key', 'value']);
        $grouped = [];
        $userId = get_current_user_id();
        foreach ($rows as $row) {
            $commentId = (int) substr($row->key, strlen(Constant::COMMENT_REACTIONS_META_PREFIX));
            if ((int) ($commentTasks[$commentId] ?? 0) !== (int) $row->task_id) {
                continue;
            }
            foreach ((array) $row->value as $actorId => $emojis) {
                foreach (array_unique((array) $emojis) as $emojiKey) {
                    if (!is_string($emojiKey) || !ctype_xdigit($emojiKey) || strlen($emojiKey) % 2) {
                        continue;
                    }
                    $grouped[$commentId][$emojiKey][(int) $actorId] = true;
                }
            }
        }
        $summaries = [];
        foreach ($grouped as $commentId => $emojis) {
            ksort($emojis);
            foreach ($emojis as $emojiKey => $users) {
                $summaries[$commentId][] = [
                    'emoji' => hex2bin((string) $emojiKey),
                    'count' => count($users),
                    'reacted' => isset($users[$userId])
                ];
            }
        }
        return $summaries;
    }

    /** Batch summaries for the visible comments and their already-loaded replies. */
    public function hydrate($comments)
    {
        $items = [];
        foreach ($comments as $comment) {
            $items[$comment->id] = $comment;
            foreach ($comment->replies as $reply) $items[$reply->id] = $reply;
        }
        $summaries = $this->summaries(array_keys($items));
        foreach ($items as $id => $comment) $comment->reactions = $summaries[$id] ?? [];
    }
}
