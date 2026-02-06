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
        return mb_strpos($word, '@') === 0;
    }

    private function isValidUrl($url) 
    {
        try {
            if (empty($url)) {
                return false;
            }

            $url = trim($url);

            // Early return for obviously invalid formats
            if ($url === 'http://' || $url === 'https://') {
                return false;
            }

            // Handle www. URLs
            if (strpos($url, 'www.') === 0) {
                $url = 'http://' . $url;
            }
            // If it's not already a URL, make it one
            elseif (!preg_match('~^(?:f|ht)tps?://~i', $url)) {
                $url = 'https://' . $url;
            }

            $components = wp_parse_url($url);
            
            if (empty($components) || !isset($components['host'])) {
                return false;
            }

            // Additional validation with filter_var
            $isValid = filter_var($url, FILTER_VALIDATE_URL) !== false;
            return $isValid;

        } catch (\Exception $e) {
            return false;
        }
    }

    private function extractUrls($text) {
        try {
            // More permissive URL pattern that handles international domains and various formats
            $urlPattern = '%\b(?:(?:https?|ftp):\/\/|www\.)[^\s<>\[\]{}"\']+'
                       . '(?:\([^\s<>\[\]{}"\')]*\)|[^\s<>\[\]{}"\'\)])*%iu';
            
            if (preg_match_all($urlPattern, $text, $matches)) {
                $urls = array_filter($matches[0], function($url) {
                    $trimmed = trim($url);
                    return !empty($trimmed);
                });
                return array_values($urls); // Re-index array
            }
            return [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function extractMentions($text) {
        try {
            // Pattern that combines zero-width delimiters with international username support
            $pattern = '/@\x{200B}([\p{L}\p{N}_. -@]+)\x{200C}/u';
            
            if (preg_match_all($pattern, $text, $matches)) {
                $mentions = array_filter($matches[1], function($mention) {
                    $trimmed = trim($mention);
                    return !empty($trimmed);
                });
                return array_values($mentions); // Re-index array
            }
            return [];
        } catch (\Exception $e) {
            return [];
        }
    }

    public function processMentionAndLink($commentDescription, $mentionData = [])
    {
        if (empty($commentDescription)) {
            return '';
        }

        try {
            // Ensure UTF-8 encoding with error handling
            $commentDescription = mb_convert_encoding($commentDescription, 'UTF-8', 'auto');

            // Extract all URLs from the text
            $urls = $this->extractUrls($commentDescription);
            $urlReplacements = [];
            
            if (!empty($urls)) {
                foreach ($urls as $url) {
                    if ($this->isValidUrl($url)) {
                        $urlReplacements[$url] = sprintf(
                            '<a class="fbs_link" target="_blank" rel="noopener noreferrer" href="%1$s">%1$s</a>',
                            esc_url($url)
                        );
                    }
                }
            }

            // Process mentions
            $mentionedUsernames = [];
            if (!empty($mentionData) && is_array($mentionData)) {
                foreach ($mentionData as $mentionedId) {
                    $user = get_userdata($mentionedId);
                    if ($user) {
                        $mentionedUsernames[$user->user_login] = [
                            'user_id' => $user->ID,
                            'display_name' => htmlspecialchars($user->display_name, ENT_QUOTES, 'UTF-8')
                        ];
                    }
                }
            }

            // Extract all mentions from the text
            $mentions = $this->extractMentions($commentDescription);
            $mentionReplacements = [];
            
            if (!empty($mentions)) {
                foreach ($mentions as $mention) {
                    if (array_key_exists($mention, $mentionedUsernames)) {
                        $mentionReplacements['@' . "\u{200B}" . $mention . "\u{200C}"] = sprintf(
                            '<a class="fbs_mention" href="%smember/%d/tasks">%s</a>',
                            esc_url(fluent_boards_page_url()),
                            $mentionedUsernames[$mention]['user_id'],
                            $mentionedUsernames[$mention]['display_name']
                        );
                    }
                }
            }

            // Apply replacements
            $originalText = $commentDescription;

            // First replace URLs (longer strings first to avoid partial replacements)
            if (!empty($urls)) {
                usort($urls, function($a, $b) {
                    return strlen($b) - strlen($a);
                });
                foreach ($urls as $url) {
                    if (isset($urlReplacements[$url])) {
                        $commentDescription = str_replace($url, $urlReplacements[$url], $commentDescription);
                    }
                }
            }

            // Then replace mentions
            if (!empty($mentionReplacements)) {
                foreach ($mentionReplacements as $mention => $replacement) {
                    $commentDescription = str_replace($mention, $replacement, $commentDescription);
                }
            }

            return $commentDescription;
            
        } catch (\Exception $e) {
            return $commentDescription; // Return original text if processing fails
        }
    }

    public function checkIfCommentHaveLinks($comment)
    {
        if (empty($comment)) {
            return '';
        }

        try {
            // Ensure UTF-8 encoding
            $comment = mb_convert_encoding($comment, 'UTF-8', 'auto');

            // Extract all URLs from the text
            $urls = $this->extractUrls($comment);
            $hasLinks = false;

            // Replace URLs with links
            if (!empty($urls)) {
                foreach ($urls as $url) {
                    if ($this->isValidUrl($url)) {
                        $hasLinks = true;
                        $replacement = sprintf(
                            '<a class="fbs_link" target="_blank" rel="noopener noreferrer" href="%1$s">%1$s</a>',
                            esc_url($url)
                        );
                        $comment = str_replace($url, $replacement, $comment);
                    }
                }
            }

            return $hasLinks ? $comment : $comment;
            
        } catch (\Exception $e) {
            return $comment; // Return original text if processing fails
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
                $deletedImage = clone $commentImage;
                $commentImage->delete();
                //do_action('fluent_boards/comment_image_deleted', $deletedImage);
            }
        }
    }

    public function update($commentData, $comment_id, $mentionData)
    {
        $comment = Comment::findOrFail($comment_id);

        if ($comment->created_by != get_current_user_id()) {
            return false;
        }

        $allMentionedIds = array_unique(array_merge($comment->settings['mentioned_id'] ?? [], is_array($mentionData) ? $mentionData : []));

        if ($allMentionedIds) {
            $processedDescription = $this->processMentionAndLink($commentData['description'], $allMentionedIds);
        } elseif(!$allMentionedIds) {
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
                'raw_description' => $commentData['description'],
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

        if ($comment->created_by != get_current_user_id()) {
            return false;
        }

        // Delete related replies first (model event will handle their images)
        $this->relatedReplyDelete($comment_id);
        
        // Delete the comment (model deleting event will handle images and comments_count)
        $comment->delete();

        do_action('fluent_boards/comment_deleted', $comment);
    }

    public function relatedReplyDelete($comment_id)
    {
        $replies = Comment::where('parent_id', $comment_id)
            ->type('reply')
            ->get();
        foreach ($replies as $reply) {
            // Delete reply (model deleting event will handle images)
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

        // Delete reply (model deleting event will handle images)
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
