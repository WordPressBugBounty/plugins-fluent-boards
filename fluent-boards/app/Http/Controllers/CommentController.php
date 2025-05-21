<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\NotificationService;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\UploadService;
use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\App\Services\CommentService;
use FluentBoardsPro\App\Services\AttachmentService;

class CommentController extends Controller
{
    private $commentService;
    private $notificationService;

    public function __construct(CommentService $commentService, NotificationService $notificationService)
    {
        parent::__construct();
        $this->commentService = $commentService;
        $this->notificationService = $notificationService;
    }

    public function getComments(Request $request, $board_id, $task_id)
    {
        try {
            $filter = $request->getSafe('filter');
            $per_page =  10;

            $comments = $this->commentService->getComments($task_id, $per_page, $filter);
            $totalComments = $this->commentService->getTotal($task_id);

            return $this->sendSuccess([
                'comments' => $comments,
                'total' => $totalComments
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    /*
     * handles comment or reply creation
     * @param $board_id int
     * @param $task_id int
     * @return json
     */
    public function create(Request $request, $board_id, $task_id)
    {
        // TODO: Refactor the whole request and sanitize process here.. minimize the code in this functions.
        $requestData = [
            'parent_id'     => $request->getSafe('parent_id', function ($value) {
                return (empty($value)) ? null : intval( $value);
            }, null),
            'description'   => $request->getSafe('comment', 'sanitize_textarea_field'),
            'created_by'    => $request->getSafe('comment_by', 'intval', get_current_user_id()),
            'task_id'       => (int) $task_id,
            'type'          => $request->getSafe('comment_type', 'sanitize_text_field', 'comment'),
            'board_id'      => (int) $board_id,
        ];
        $validationRules = [
            'description'   => 'required|string',
            'created_by'    => 'required|integer',
            'board_id'      => 'required|integer',
            'task_id'       => 'required|integer',
            'type'          => 'required|string'
        ];

        if ($request->images) {
            $validationRules['description'] = 'nullable|string';
        }

        $commentData = $this->commentSanitizeAndValidate($requestData, $validationRules);


        try {

            $rawDescription = $commentData['description'];
            $commentData['settings'] = [ 'raw_description' => $rawDescription, 'mentioned_id' => $request->mentionData ];

            // Ensure UTF-8 encoding for comment description
            $description = mb_convert_encoding($commentData['description'], 'UTF-8', 'auto');

            if($request->mentionData) {
                // Process mentions and links with UTF-8 support
                $commentData['description'] = $this->commentService->processMentionAndLink($description, $request->mentionData);
            } else {
                // Process links with UTF-8 support
                $commentData['description'] = $this->commentService->checkIfCommentHaveLinks($description);
            }

            $comment = $this->commentService->create($commentData, $task_id);
            $comment['user'] = $comment->user;

            $usersToSendEmail = [];
            if ($comment->type == 'reply') {
                $parentComment = Comment::findOrFail($comment->parent_id);
                $commenterId = $parentComment->created_by;
                if ($commenterId != get_current_user_id())
                {
                    $commenter = User::select('user_email')->findOrFail($commenterId);
                    $commenterEmail = $commenter->user_email;
                    $usersToSendEmail[] = $commenterEmail;
                }
                $this->sendMailAfterComment($comment->id, $usersToSendEmail);
            } else {
                //sending emails to assignees who enabled their email
                $usersToSendEmail = $this->notificationService->filterAssigneeToSendEmail($task_id, Constant::BOARD_EMAIL_COMMENT);
                $this->sendMailAfterComment($comment->id, $usersToSendEmail);
            }

            if($request->mentionData)
            {
                $this->notificationService->mentionInComment($comment, $request->mentionData);
            }

            if($request->images)
            {
                $this->commentService->attachCommentImages($comment, $request->images);
                $comment->load(['images']);
            }

            if ($comment->type == 'comment')
            {
                $comment->load('replies');
            }

            return $this->sendSuccess([
                'message' => __('Comment has been added', 'fluent-boards'),
                'comment' => $comment
            ], 201);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function update(Request $request, $board_id, $comment_id)
    {
        $requestData = [
            'description'   => $request->comment
        ];

        $validationRules = [
            'description'   => 'required|string'
        ];

        if ($request->images) {
            $validationRules['description'] = 'nullable|string';
        }

        $commentData = $this->commentSanitizeAndValidate($requestData, $validationRules);

        try {
            $comment = $this->commentService->update($commentData, $comment_id, $request->getSafe('mentionData'));

            if (!$comment) {
                $errorMessage = __('Unauthorized Action', 'fluent-boards');
                return $this->sendError($errorMessage, 401);
            }

            if($request->getSafe('mentionData'))
            {
                $this->notificationService->mentionInComment($comment, $request->getSafe('mentionData'));
            }

            if($request->getSafe('images'))
            {
                $this->commentService->attachCommentImages($comment, $request->getSafe('images'));
                $comment->load(['images']);
            }

            $comment->load('user');

            return $this->sendSuccess([
                'comment' => $comment,
                'message'     => __('Comment has been updated', 'fluent-boards'),
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function deleteComment($board_id, $comment_id)
    {
        try {
            $this->commentService->delete($comment_id);

            return $this->sendSuccess([
                'message' => __('Comment has been deleted', 'fluent-boards'),
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function updateReply(Request $request, $board_id, $reply_id)
    {
        $requestData = [
            'description'   => $request->comment
        ];

        $validationRules = [
            'description'   => 'required|string'
        ];

        $replyData = $this->commentSanitizeAndValidate($requestData, $validationRules);

        try {
            $reply = $this->commentService->update($replyData, $reply_id, $request->mentionData);

            if (!$reply) {
                $errorMessage = __('Unauthorized Action', 'fluent-boards');
                return $this->sendError($errorMessage, 401);
            }

            return $this->sendSuccess([
                'description' => $reply->description,
                'message'     => __('Reply has been updated', 'fluent-boards'),
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function deleteReply($board_id, $reply_id)
    {
        try {
            $this->commentService->deleteReply($reply_id);

            return $this->sendSuccess([
                'message' => __('Reply has been deleted', 'fluent-boards'),
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function sendMailAfterComment($commentId, $usersToSendEmail)
    {
        $current_user_id = get_current_user_id();

        /* this will run in background as soon as possible */
        /* sending Model or Model Instance won't work here */
        as_enqueue_async_action('fluent_boards/one_time_schedule_send_email_for_comment', [$commentId, $usersToSendEmail, $current_user_id], 'fluent-boards');
    }

    private function commentSanitizeAndValidate($data, array $rules = [])
    {
        $data = Helper::sanitizeComment($data);

        return $this->validate($data, $rules);
    }

    public function handleImageUpload(Request $request, $board_id, $task_id)
    {
        $allowedTypes = implode(',', [
            "image/jpeg",
            "image/gif",
            "image/png",
            "image/bmp",
            "image/tiff",
            "image/webp",
            "image/avif",
            "image/x-icon",
            "image/heic",
        ]);

        $files = $this->validate($request->files(), [
            'file' => 'mimetypes:' . $allowedTypes,
        ], [
            'file.mimetypes' => __('The file must be a image type.', 'fluent-boards')
        ]);

        $uploadInfo = UploadService::handleFileUpload( $files, $board_id);

        $imageData = $uploadInfo[0];
        $attachment = $this->commentService->createCommentImage($imageData, $board_id);
        if(!!defined('FLUENT_BOARDS_PRO_VERSION')) {
            $mediaData = (new AttachmentService())->processMediaData($imageData, $files['file']);
            $attachment['driver'] = $mediaData['driver'];
            $attachment['file_path'] = $mediaData['file_path'];
            $attachment['full_url'] = $mediaData['full_url'];
            $attachment->save();
        }
        $attachment->public_url = $this->commentService->createPublicUrl($attachment, $board_id);

        return $this->sendSuccess([
            'message'    => __('attachment has been added', 'fluent-boards'),
            'imageAttachment' => $attachment
        ], 200);

    }
}
