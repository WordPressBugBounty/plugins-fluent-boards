<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\App;
use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\CommentImage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskActivity;
use FluentBoardsPro\App\Services\AttachmentService;
use FluentBoardsPro\App\Services\RemoteUrlParser;
use RuntimeException;

class CommentService
{
    /**
     * Resolve a comment only when its task belongs to the requested board.
     *
     * @param int $commentId
     * @param int $boardId
     * @return Comment
     * @throws \Exception
     */
    public function findCommentOnBoard($commentId, $boardId)
    {
        $comment = Comment::findOrFail($commentId);
        (new TaskService())->findTaskOnBoard($comment->task_id, $boardId);

        if ($comment->board_id && (int) $comment->board_id !== absint($boardId)) {
            throw new \Exception(esc_html__('Comment not found', 'fluent-boards'));
        }

        return $comment;
    }

    /**
     * Get paginated parent comments with users, images, and replies preloaded.
     */
    public function getComments($id, $per_page, $filter, $boardId = null)
    {
        $task = $boardId ? (new TaskService())->findTaskOnBoard($id, $boardId) : Task::findOrFail($id);

        $commentsQuery = $task->comments()->whereNull('parent_id')
            ->with(['user', 'images', 'replies.user', 'replies.images']);

        if ($filter == 'oldest') {
            $commentsQuery = $commentsQuery->oldest();
        } else { // latest or newest
            $commentsQuery = $commentsQuery->latest();
        }
        $comments = $commentsQuery->paginate($per_page);

        foreach ($comments as $comment) {
            $comment->replies_count = count($comment->replies);
        }

        return $comments;
    }

    public function getTotal($id, $boardId = null)
    {
        $task = $boardId ? (new TaskService())->findTaskOnBoard($id, $boardId) : Task::findOrFail($id);
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

    public function create($commentData, $id, $boardId = null)
    {
        if ($boardId) {
            (new TaskService())->findTaskOnBoard($id, $boardId);

            if (!empty($commentData['parent_id'])) {
                $parentComment = $this->findCommentOnBoard($commentData['parent_id'], $boardId);

                if ((int) $parentComment->task_id !== (int) $id) {
                    throw new \Exception(esc_html__('Comment not found', 'fluent-boards'));
                }
            }
        }

        $comment = Comment::create($commentData);
        do_action('fluent_boards/comment_created', $comment);
        return $comment;
    }

    /** Allow only paragraph content and the comment editor's five inline formats. */
    public function sanitizeContent($content)
    {
        if (!is_string($content) || trim(wp_strip_all_tags($content)) === '') {
            return '';
        }

        return trim(wp_kses($content, [
            'p' => [], 'br' => [], 'strong' => [], 'b' => [],
            'em' => [], 'i' => [], 'del' => [], 's' => [], 'code' => [],
            'a' => ['href' => true, 'title' => true],
        ]));
    }

    /** Resolve mentions and bare URLs in text without rewriting link attributes or code. */
    public function renderContent($content, $mentionData = [])
    {
        $parts = wp_html_split($this->sanitizeContent($content));
        $skipDepth = 0;
        foreach ($parts as &$part) {
            if (preg_match('~^</?(a|code)\b~i', $part)) {
                $skipDepth += strpos($part, '</') === 0 ? -1 : 1;
                $skipDepth = max(0, $skipDepth);
            } elseif ($skipDepth === 0 && $part !== '' && $part[0] !== '<') {
                $part = $this->processMentionAndLink($part, $mentionData);
            }
        }
        unset($part);

        return wp_kses_post(implode('', $parts));
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
        if ($commentDescription === '' || $commentDescription === null) {
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

    /**
     * Retain this comment's images or claim the current user's pending uploads on its board.
     * Validate the complete list before changing attachments or removing omitted images.
     */
    public function attachCommentImages($comment, $imageIds)
    {
        $imageIds = $this->normalizeCommentImageIds($imageIds);
        $commentId = absint($comment->id);
        $boardId = absint($comment->board_id);
        $taskId = absint($comment->task_id);
        $userId = get_current_user_id();

        if (!$commentId || !$boardId || !$userId) {
            throw new RuntimeException(__('Invalid comment image selection.', 'fluent-boards'));
        }

        App::getInstance('db')->transaction(function () use ($imageIds, $commentId, $boardId, $taskId, $userId) {
            // Serialize edits to this comment and prevent concurrent claims of the same upload.
            Comment::withoutGlobalScopes()->where('id', $commentId)->where('board_id', $boardId)->lockForUpdate()->firstOrFail();
            $images = CommentImage::whereIn('id', $imageIds)
                ->where('object_type', Constant::COMMENT_IMAGE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($images->count() !== count($imageIds)) {
                throw new RuntimeException(__('Invalid comment image selection.', 'fluent-boards'));
            }

            foreach ($images as $image) {
                if ((int) $image->object_id === $commentId) {
                    continue;
                }

                if ((int) $image->object_id !== 0
                    || !$this->commentImageScopeMatches($image, $boardId, $taskId, $userId)) {
                    throw new RuntimeException(__('Invalid comment image selection.', 'fluent-boards'));
                }
            }

            foreach ($images as $image) {
                if ((int) $image->object_id === 0) {
                    $image->object_id = $commentId;
                    if (!$image->save()) {
                        throw new RuntimeException(__('Could not attach comment image.', 'fluent-boards'));
                    }
                }
            }

            $removedImages = CommentImage::where('object_id', $commentId)
                ->where('object_type', Constant::COMMENT_IMAGE)
                ->whereNotIn('id', $imageIds)
                ->get();

            foreach ($removedImages as $image) {
                if (!$image->delete()) {
                    throw new RuntimeException(__('Could not remove comment image.', 'fluent-boards'));
                }
            }
        });
    }

    /**
     * Allow retained images on this comment and validate all newly supplied uploads.
     */
    public function assertCommentImagesAttachableForComment($comment, $imageIds)
    {
        $imageIds = $this->normalizeCommentImageIds($imageIds);

        if (empty($imageIds)) {
            return [];
        }

        $commentImages = CommentImage::where('object_id', $comment->id)
            ->where('object_type', Constant::COMMENT_IMAGE)
            ->get();

        foreach ($commentImages as $commentImage) {
            $key = array_search((int) $commentImage->id, $imageIds, true);
            if ($key !== false) {
                unset($imageIds[$key]);
            }
        }

        return $this->assertCommentImagesAttachable(
            array_values($imageIds),
            $comment->board_id,
            $comment->task_id
        );
    }

    /**
     * Reject the entire image list unless every upload is unbound and owned by this actor, board, and task.
     */
    public function assertCommentImagesAttachable($imageIds, $boardId, $taskId)
    {
        $imageIds = $this->normalizeCommentImageIds($imageIds);

        if (empty($imageIds)) {
            return [];
        }

        $currentUserId = get_current_user_id();
        if (!$currentUserId) {
            throw new \Exception(esc_html__('Invalid comment image attachment', 'fluent-boards'));
        }

        $attachments = CommentImage::whereIn('id', $imageIds)
            ->where('object_id', 0)
            ->where('object_type', Constant::COMMENT_IMAGE)
            ->get();

        if (count($attachments) !== count($imageIds)) {
            throw new \Exception(esc_html__('Invalid comment image attachment', 'fluent-boards'));
        }

        foreach ($attachments as $attachment) {
            if (!$this->commentImageScopeMatches($attachment, $boardId, $taskId, $currentUserId)) {
                throw new \Exception(esc_html__('Invalid comment image attachment', 'fluent-boards'));
            }
        }

        return $attachments;
    }

    /**
     * Fail closed for legacy uploads without recorded board, task, and uploader ownership.
     */
    private function commentImageScopeMatches($attachment, $boardId, $taskId, $userId)
    {
        $settings = is_array($attachment->settings) ? $attachment->settings : [];
        $scope = isset($settings['comment_image_scope']) && is_array($settings['comment_image_scope'])
            ? $settings['comment_image_scope']
            : [];

        return intval($scope['board_id'] ?? 0) === intval($boardId)
            && intval($scope['task_id'] ?? 0) === intval($taskId)
            && intval($scope['created_by'] ?? 0) === intval($userId);
    }

    /**
     * Record trusted upload ownership in attachment metadata before saving.
     */
    public function applyCommentImageScope($attachment, $boardId, $taskId, $createdBy = null)
    {
        $settings = is_array($attachment->settings) ? $attachment->settings : [];
        $settings['comment_image_scope'] = [
            'board_id' => intval($boardId),
            'task_id' => intval($taskId),
            'created_by' => intval($createdBy === null ? get_current_user_id() : $createdBy),
        ];
        $attachment->settings = $settings;

        return $attachment;
    }

    /**
     * Normalize submitted image IDs and remove duplicates before validating the full list.
     */
    private function normalizeCommentImageIds($imageIds)
    {
        if (!is_array($imageIds)) {
            return [];
        }

        return array_values(array_filter(array_unique(array_map('intval', $imageIds))));
    }

    public function update($commentData, $comment_id, $mentionData, $boardId = null)
    {
        $comment = $boardId ? $this->findCommentOnBoard($comment_id, $boardId) : Comment::findOrFail($comment_id);

        if ($comment->created_by != get_current_user_id()) {
            return false;
        }

        $effectiveBoardId = absint($comment->board_id ?: $boardId);
        $notificationService = new NotificationService();
        $existingMentionedIds = array_values(array_unique(array_filter(array_map('absint', (array) ($comment->settings['mentioned_id'] ?? [])))));
        $newMentionedIds = array_values(array_unique(array_filter(array_map('absint', (array) $mentionData))));
        $requestedMentionedIds = array_values(array_unique(array_merge($existingMentionedIds, $newMentionedIds)));
        $allMentionedIds = $notificationService->resolveBoardMentionUserIds($effectiveBoardId, $requestedMentionedIds);

        if (array_diff($newMentionedIds, $allMentionedIds)) {
            throw new \Exception(esc_html__('One or more mentioned users are not members of this board', 'fluent-boards'), 403);
        }

        $commentData['description'] = $this->sanitizeContent($commentData['description']);
        $processedDescription = $this->renderContent($commentData['description'], $allMentionedIds);

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

    public function delete($comment_id, $boardId = null)
    {
        $comment = $boardId ? $this->findCommentOnBoard($comment_id, $boardId) : Comment::findOrFail($comment_id);

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

    public function updateReply($replyData, $id, $boardId = null)
    {
        $reply = $boardId ? $this->findCommentOnBoard($id, $boardId) : Comment::findOrFail($id);

        if ($reply->created_by != get_current_user_id()) {
            return false;
        }

        $oldReply = $reply->description;
        $reply->description = $replyData['description'];
        $reply->save();
//        do_action('fluent_boards/task_comment_updated', $comment->task_id, $oldComment, $comment->description);

        return $reply;
    }

    public function deleteReply($id, $boardId = null)
    {
        $reply = $boardId ? $this->findCommentOnBoard($id, $boardId) : Comment::findOrFail($id);
//        $taskId = $reply->task_id;

        if ($reply->created_by != get_current_user_id()) {
            return false;
        }

        // Delete reply (model deleting event will handle images)
        $reply->delete();

//        do_action('fluent_boards/comment_deleted', $taskId);
    }

    /**
     * Persist an unbound comment upload with trusted board, task, and uploader metadata.
     * Legacy uploads without this scope cannot be newly attached to a comment.
     *
     * @return CommentImage
     */
    public function createCommentImage($data, $boardId, $taskId = null)
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
        $settings = $attachData['type'] == 'url' ? [
            'meta' => $UrlMeta
        ] : [];
        $settings['board_id'] = absint($boardId);
        $attachment->settings = $settings + [
            Constant::ATTACHMENT_UPLOAD_BOARD_ID => absint($boardId),
            Constant::ATTACHMENT_UPLOAD_USER_ID => get_current_user_id(),
        ];
        $this->applyCommentImageScope($attachment, $boardId, $taskId);
        $attachment->driver = 'local';
        $attachment->save();

        return $attachment;
    }

    public function createPublicUrl($attachment, $boardId)
    {
        $boardId = absint($boardId);

        return add_query_arg([
            'fbs'               => 1,
            'fbs_type'          => 'public_url',
            'fbs_comment_image' => $attachment->file_hash,
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
