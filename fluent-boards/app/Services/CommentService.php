<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\CommentImage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskActivity;
use FluentBoardsPro\App\Services\AttachmentService;
use FluentBoardsPro\App\Services\RemoteUrlParser;

class CommentService
{
    public function getComments($id, $per_page, $filter)
    {
        $task = Task::findOrFail($id);

        $commentsQuery = $task->comments()->whereNull('parent_id')
            ->with(['user']);

        if ($filter == 'oldest') {
            $commentsQuery = $commentsQuery->oldest();
        } else { // latest or newest
            $commentsQuery = $commentsQuery->latest();
        }
        $comments = $commentsQuery->paginate($per_page);

        foreach ($comments as $comment) {
            $comment->replies = $this->getReplies($comment);
            $comment->replies_count = count($comment->replies);
            $comment->load('images');
        }

        return $comments;
    }

    public function getTotal($id)
    {
        $task = Task::findOrFail($id);
        $totalComment = Comment::where('task_id', $task->id)
            ->type('comment')
            ->count();
        $totalReply = Comment::where('task_id', $task->id)
            ->type('reply')
            ->count();

        return $totalComment + $totalReply;
    }

    public function getReplies($comment)
    {
        $replies = Comment::where('parent_id', $comment->id)->with(['user'])->get();
        return $replies;
    }

    public function create($commentData, $id)
    {
        $comment = Comment::create($commentData);
        do_action('fluent_boards/comment_created', $comment);
        return $comment;
    }

    private function startsWithAt($word) {
        return strpos($word, '@') === 0;
    }

    public function processMentionAndLink($commentDescription, $mentionData)
    {
        // Splitting a string by either a space (" ") or a new line ("\n")
        $lines = preg_split('/\R/', $commentDescription); // \R matches any kind of line break

        $mentionedUsernames = [];
        foreach ($mentionData as $mentionedId) {
            $user = get_userdata($mentionedId);
            $mentionedUsernames[$user->user_login] = ['user_id' => $user->ID, 'display_name' => $user->display_name];
        }

        foreach ($lines as &$line) {
            $words = preg_split('/[ ]+/', $line);
            foreach ($words as $index => $word) {
                if ($this->startsWithAt($word)) {
                    $username = substr($word, 1);
                    if (array_key_exists($username, $mentionedUsernames)) {
                        $words[$index] = '<a class="fbs_mention" href="' . fluent_boards_page_url() . 'member/' . $mentionedUsernames[$username]['user_id'] . '/tasks">' . $mentionedUsernames[$username]['display_name'] . '</a>';
                    }
                } elseif ($this->isValidUrl(wp_kses_post($word))) {
                    $words[$index] = '<a class="fbs_link" target="_blank" href="'. esc_url($word). '">'. esc_url($word). '</a>';
                }
            }
            // Rejoin the words in this line
            $line = implode(' ', $words);
        }

// Rejoin the lines, adding back the new line character
        return implode("\n", $lines);

    }

    private function isValidUrl($url) {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        // Additional validation with a regular expression
        $regex = "/\b(?:https?|ftp):\/\/[-A-Z0-9+&@#\/%?=~_|!:,.;]*[-A-Z0-9+&@#\/%=~_|]/i";

        return preg_match($regex, $url);
    }

    public function checkIfCommentHaveLinks($comment)
    {
        $lines = preg_split('/\R/', $comment); // Split by any kind of line break
        $commentHasLinks = false;

        foreach ($lines as &$line) {
            $words = preg_split('/[ ]+/', $line); // Split by spaces within each line
            foreach ($words as $index => $word) {
                $cleanWord = wp_kses_post($word);
                if ($this->isValidUrl($cleanWord)) {
                    $commentHasLinks = true;
                    $words[$index] = '<a class="fbs_link" target="_blank" href="' . esc_url($cleanWord) . '">' . esc_url($cleanWord) . '</a>';
                }
            }
            // Rejoin words in this line
            $line = implode(' ', $words);
        }

        if ($commentHasLinks) {
            return implode("\n", $lines); // Rejoin lines with new lines preserved
        } else {
            return $comment; // Return original comment if no links were found
        }
    }

    public function attachCommentImages($comment, $imageIds)
    {

        foreach ($imageIds as $imageId)
        {
            $attachmentObject = CommentImage::findOrFail($imageId);
            if($attachmentObject) {
                if ($attachmentObject->object_id == $comment->id && $attachmentObject->object_type == Constant::COMMENT_IMAGE) {
                    continue;
                }
                $attachmentObject->object_id = $comment->id;
                $attachmentObject->object_type = Constant::COMMENT_IMAGE;
                $attachmentObject->save();
            }
        }
        //if(in_array("banana", $imageIds))
        $commentImages = CommentImage::where('object_id', $comment->id)->where('object_type', Constant::COMMENT_IMAGE)->get();

        foreach ($commentImages as $commentImage) {
            if(!in_array($commentImage->id, $imageIds)) {
                $commentImage->delete();
            }
        }
    }

    public function update($commentData, $comment_id, $mentionData)
    {
        $comment = Comment::findOrFail($comment_id);

        if ($comment->created_by != get_current_user_id()) {
            return false;
        }

        $allMentionedIds = array_merge($comment->settings['mentioned_id'] ?? [], $mentionData ?? []);

        if ($allMentionedIds) {
            $processedDescription = $this->processMentionAndLink($commentData['description'], $allMentionedIds);
        } else {
            $processedDescription = $this->checkIfCommentHaveLinks($commentData['description']);
        }

        $oldComment = $comment->settings['raw_description'] ?? $comment->description;
        $comment->description = $processedDescription;

        if($comment->settings != null)
        {
            $tempSettings = $comment->settings;
            $tempSettings['raw_description'] = $commentData['description'];
            $tempSettings['mentioned_id'] = $allMentionedIds;
            $comment->settings = $tempSettings;
        } else {
            $comment->settings = [
                'raw_description' => $commentData['raw_description'],
                'mentioned_id' => $allMentionedIds
            ];
        }
        $comment->save();

        if(!$comment->parent_id) {
            do_action('fluent_boards/comment_updated', $comment, $oldComment);
        }

        return $comment;
    }

    public function delete($comment_id)
    {
        $comment = Comment::findOrFail($comment_id);
        $taskId = $comment->task_id;

        if ($comment->created_by != get_current_user_id()) {
            return false;
        }

        $deleted = $comment->delete();

        if ($deleted) {
            $this->relatedReplyDelete($comment_id);
            $comment->images()->delete();
            $task = Task::findOrFail($taskId);
            $task->comments_count = $task->comments_count - 1;
            $task->save();
        }

        do_action('fluent_boards/comment_deleted', $comment);
    }

    public function relatedReplyDelete($comment_id)
    {
        $replies = Comment::where('parent_id', $comment_id)
            ->type('reply')
            ->get();
        foreach ($replies as $reply) {
            $reply->delete();
        }
    }

    public function updateReply($replyData, $id)
    {
        $reply = Comment::findOrFail($id);

        if ($reply->created_by != get_current_user_id()) {
            return false;
        }

        $oldReply = $reply->description;
        $reply->description = $replyData['description'];
        $reply->save();
//        do_action('fluent_boards/task_comment_updated', $comment->task_id, $oldComment, $comment->description);

        return $reply;
    }

    public function deleteReply($id)
    {
        $reply = Comment::findOrFail($id);
//        $taskId = $reply->task_id;

        if ($reply->created_by != get_current_user_id()) {
            return false;
        }

        $reply->delete();

//        do_action('fluent_boards/comment_deleted', $taskId);
    }

    /**
     * Adds a task attachment to the specified task.
     *
     * @param int $taskId The ID of the task to which the attachment is added.
     * @param string $title The title of the attachment.
     * @param string $url The URL of the attachment.
     *
     * @return Attachment The updated list of task attachments.
     * @throws \Exception
     */
    public function createCommentImage($data, $boardId)
    {
        /*
         * I will refactor this function later- within March 2024 Last Week
         */
        $initialDataData = [
            'type' => 'url',
            'url' => '',
            'name' => '',
            'size' => 0,
        ];

        $attachData = array_merge($initialDataData, $data);
        $UrlMeta = [];
        if($attachData['type'] == 'url') {
            $UrlMeta = RemoteUrlParser::parse($attachData['url']);
        }
        $attachment = new CommentImage();
        $attachment->object_id = 0;
        $attachment->object_type = Constant::COMMENT_IMAGE;
        $attachment->attachment_type = $attachData['type'];
        $attachment->title = $this->setTitle($attachData['type'], $attachData['name'], $UrlMeta);
        $attachment->file_path = $attachData['type'] != 'url' ?  $attachData['file'] : null;
        $attachment->full_url = esc_url($attachData['url']);
        $attachment->file_size = $attachData['size'];
        $attachment->settings = $attachData['type'] == 'url' ? [
            'meta' => $UrlMeta
        ] : '';
        $attachment->driver = 'local';
        $attachment->save();

        return $attachment;
    }

    public function createPublicUrl($attachment, $boardId)
    {
        return add_query_arg([
            'fbs'               => 1,
            'fbs_type'          => 'public_url',
            'fbs_bid'           => $boardId,
            'fbs_comment_image'    => $attachment->file_hash
        ], site_url('/index.php'));
    }

    private function setTitle($type, $title, $UrlMeta)
    {
        if($type != 'url') {
            return sanitize_file_name($title);
        }
        return $title ?? $UrlMeta['title'] ?? '';
    }
}
