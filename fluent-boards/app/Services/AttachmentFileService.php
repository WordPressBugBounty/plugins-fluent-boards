<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Attachment;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskImage;
use FluentBoards\App\Services\Libs\FileSystem;

class AttachmentFileService
{
    protected $createdFiles = [];
    protected $createdAttachments = [];
    protected $movedOriginalFiles = [];

    public function moveTaskFilesToBoard(Task $task, $sourceBoardId, $targetBoardId)
    {
        if ((int) $sourceBoardId === (int) $targetBoardId) {
            return $task;
        }

        $descriptionUrlMap = [];
        $movedImages = [];

        $images = TaskImage::where('object_id', $task->id)
            ->where('object_type', Constant::TASK_DESCRIPTION)
            ->get();
        $taskAttachments = $this->getTaskAttachmentsForMove($task);
        $sharedFullUrls = $this->getSharedFullUrlLookup($this->combineAttachmentCollections($images, $taskAttachments));

        foreach ($images as $image) {
            $result = $this->moveAttachmentToBoard($image, $sourceBoardId, $targetBoardId, $sharedFullUrls);
            $movedImages[$image->id] = $result;

            if (!empty($result['old_public_url']) && !empty($result['new_public_url'])) {
                $descriptionUrlMap[$result['old_public_url']] = $result['new_public_url'];
            }
        }

        $this->moveTaskAttachmentsToBoard($taskAttachments, $sourceBoardId, $targetBoardId, $sharedFullUrls);
        $this->rewriteTaskDescriptionUrls($task, $descriptionUrlMap);
        $this->updateTaskCoverFromFileResults($task, $movedImages, $targetBoardId);

        return $task;
    }

    public function cloneTaskFilesToBoard(Task $sourceTask, Task $targetTask, $targetBoardId, array $options = [])
    {
        $options = array_merge([
            'description_images' => true,
            'cover'              => true,
            'task_attachments'   => true,
        ], $options);

        $descriptionUrlMap = [];
        $clonedImages = [];
        $coverImageId = $this->getCoverImageId($sourceTask);

        if ($options['description_images'] || ($options['cover'] && $coverImageId)) {
            $imageQuery = TaskImage::where('object_id', $sourceTask->id)
                ->where('object_type', Constant::TASK_DESCRIPTION);

            if (!$options['description_images'] && $coverImageId) {
                $imageQuery->where('id', $coverImageId);
            }

            $images = $imageQuery->get();

            foreach ($images as $image) {
                $result = $this->cloneAttachmentToBoard($image, $targetTask->id, $targetBoardId, Constant::TASK_DESCRIPTION, $sourceTask->board_id);
                $clonedImages[$image->id] = $result;

                if (!empty($result['old_public_url']) && !empty($result['new_public_url'])) {
                    $descriptionUrlMap[$result['old_public_url']] = $result['new_public_url'];
                }
            }
        }

        if ($options['task_attachments']) {
            $this->cloneTaskAttachmentsToBoard($sourceTask, $targetTask, $targetBoardId);
        }

        $this->rewriteTaskDescriptionUrls($targetTask, $descriptionUrlMap);
        $this->updateTaskCoverFromFileResults($targetTask, $clonedImages, $targetBoardId, $coverImageId);
        $this->refreshTaskAttachmentCount($targetTask);
        $targetTask->save();

        return $targetTask;
    }

    public function cloneAttachmentToBoard(Attachment $source, $targetObjectId, $targetBoardId, $objectType = null, $sourceBoardId = null)
    {
        $clonedAttachment = $source->replicate();
        $clonedAttachment->object_id = (int) $targetObjectId;
        $clonedAttachment->object_type = $objectType ?: $source->object_type;
        $clonedAttachment->file_hash = $this->generateFileHash();

        $result = $this->copyAttachmentFileData($source, $clonedAttachment, $sourceBoardId, $targetBoardId);
        if ($this->isLocalFileAttachment($source) && empty($result['copied'])) {
            return $result;
        }

        $clonedAttachment->save();
        $this->createdAttachments[] = [
            'class' => get_class($clonedAttachment),
            'id'    => $clonedAttachment->id,
        ];

        $result['attachment'] = $clonedAttachment;
        $result['new_attachment_id'] = $clonedAttachment->id;
        $result['new_public_url'] = $this->createPublicUrl($clonedAttachment, $targetBoardId);

        return $result;
    }

    public function moveAttachmentToBoard(Attachment $attachment, $sourceBoardId, $targetBoardId, array $sharedFullUrls = [])
    {
        $oldFullUrl = $attachment->full_url;
        $oldPublicUrl = $this->createPublicUrl($attachment, $sourceBoardId);

        $result = $this->copyAttachmentFileData($attachment, $attachment, $sourceBoardId, $targetBoardId);
        if ($this->isLocalFileAttachment($attachment) && empty($result['copied'])) {
            $result['attachment'] = $attachment;
            $result['old_public_url'] = $oldPublicUrl;
            $result['new_public_url'] = $oldPublicUrl;

            return $result;
        }

        $attachment->file_hash = $this->generateFileHash();
        $attachment->save();

        $result['attachment'] = $attachment;
        $result['old_public_url'] = $oldPublicUrl;
        $result['new_public_url'] = $this->createPublicUrl($attachment, $targetBoardId);

        if ($this->isLocalFileAttachment($attachment) && !empty($result['old_path']) && file_exists($result['old_path'])) {
            if (empty($sharedFullUrls[$oldFullUrl]) && $result['old_path'] !== $result['new_path']) {
                $this->movedOriginalFiles[] = $result['old_path'];
            }
        }

        return $result;
    }

    public function commitMovedOriginalFiles()
    {
        foreach (array_unique($this->movedOriginalFiles) as $file) {
            if (file_exists($file)) {
                wp_delete_file($file);
            }
        }

        $this->createdFiles = [];
        $this->createdAttachments = [];
        $this->movedOriginalFiles = [];
    }

    public function rollbackCreatedFiles()
    {
        foreach (array_reverse($this->createdAttachments) as $createdAttachment) {
            if (!class_exists($createdAttachment['class'])) {
                continue;
            }

            $attachment = $createdAttachment['class']::find($createdAttachment['id']);
            if ($attachment) {
                $attachment->delete();
            }
        }

        foreach (array_reverse($this->createdFiles) as $file) {
            if (file_exists($file)) {
                wp_delete_file($file);
            }
        }

        $this->createdFiles = [];
        $this->createdAttachments = [];
        $this->movedOriginalFiles = [];
    }

    protected function cloneTaskAttachmentsToBoard(Task $sourceTask, Task $targetTask, $targetBoardId)
    {
        if (!class_exists('\FluentBoardsPro\App\Models\TaskAttachment')) {
            return;
        }

        $attachments = \FluentBoardsPro\App\Models\TaskAttachment::where('object_id', $sourceTask->id)
            ->where('object_type', Constant::TASK_ATTACHMENT)
            ->get();

        foreach ($attachments as $attachment) {
            $this->cloneAttachmentToBoard($attachment, $targetTask->id, $targetBoardId, Constant::TASK_ATTACHMENT, $sourceTask->board_id);
        }
    }

    protected function moveTaskAttachmentsToBoard($attachments, $sourceBoardId, $targetBoardId, array $sharedFullUrls)
    {
        foreach ($attachments as $attachment) {
            $this->moveAttachmentToBoard($attachment, $sourceBoardId, $targetBoardId, $sharedFullUrls);
        }
    }

    protected function getTaskAttachmentsForMove(Task $task)
    {
        if (!class_exists('\FluentBoardsPro\App\Models\TaskAttachment')) {
            return [];
        }

        return \FluentBoardsPro\App\Models\TaskAttachment::where('object_id', $task->id)
            ->where('object_type', Constant::TASK_ATTACHMENT)
            ->get();
    }

    protected function combineAttachmentCollections(...$collections)
    {
        $attachments = [];

        foreach ($collections as $collection) {
            foreach ($collection as $attachment) {
                $attachments[] = $attachment;
            }
        }

        return $attachments;
    }

    protected function getSharedFullUrlLookup(array $attachments)
    {
        $fullUrls = [];
        $attachmentIds = [];

        foreach ($attachments as $attachment) {
            if (!empty($attachment->full_url)) {
                $fullUrls[] = $attachment->full_url;
                $attachmentIds[] = (int) $attachment->id;
            }
        }

        $fullUrls = array_values(array_unique($fullUrls));
        $attachmentIds = array_filter(array_unique($attachmentIds));

        if (!$fullUrls) {
            return [];
        }

        $sharedUrls = Attachment::whereIn('full_url', $fullUrls)
            ->whereNotIn('id', $attachmentIds)
            ->pluck('full_url')
            ->toArray();

        return array_fill_keys($sharedUrls, true);
    }

    protected function copyAttachmentFileData(Attachment $source, Attachment $target, $sourceBoardId, $targetBoardId)
    {
        $result = [
            'old_path'       => null,
            'new_path'       => null,
            'old_full_url'   => $source->full_url,
            'new_full_url'   => $source->full_url,
            'old_public_url' => $sourceBoardId ? $this->createPublicUrl($source, $sourceBoardId) : null,
            'new_public_url' => null,
            'copied'         => false,
        ];

        if (!$this->isLocalFileAttachment($source)) {
            return $result;
        }

        $sourcePath = $this->resolveLocalPath($source, $sourceBoardId);
        if (!$sourcePath || !file_exists($sourcePath)) {
            throw new \Exception(esc_html__('Attachment file could not be found.', 'fluent-boards'));
        }

        $targetDir = $this->getBoardDir($targetBoardId);
        if (!is_dir($targetDir)) {
            wp_mkdir_p($targetDir);
        }

        $filename = $this->uniqueFilename($targetDir, $this->getAttachmentFilename($source));
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;

        if (!copy($sourcePath, $targetPath)) {
            throw new \Exception(esc_html__('Could not copy attachment file.', 'fluent-boards'));
        }

        $this->createdFiles[] = $targetPath;

        $target->file_path = $this->shouldStoreAbsolutePath($source) ? $targetPath : $filename;
        $target->full_url = $this->getBoardUrl($targetBoardId) . '/' . rawurlencode($filename);
        $target->driver = 'local';

        $result['old_path'] = $sourcePath;
        $result['new_path'] = $targetPath;
        $result['new_full_url'] = $target->full_url;
        $result['copied'] = true;

        return $result;
    }

    protected function rewriteTaskDescriptionUrls(Task $task, array $urlMap)
    {
        if (!$urlMap || empty($task->description)) {
            return;
        }

        $description = $task->description;

        foreach ($urlMap as $oldUrl => $newUrl) {
            $description = str_replace($oldUrl, $newUrl, $description);
            $description = str_replace(esc_url($oldUrl), esc_url($newUrl), $description);
            $description = str_replace(esc_attr($oldUrl), esc_attr($newUrl), $description);
        }

        if ($description !== $task->description) {
            $task->description = $description;
        }
    }

    protected function updateTaskCoverFromFileResults(Task $task, array $fileResults, $targetBoardId, $sourceCoverImageId = null)
    {
        $settings = $task->settings;
        if (empty($settings['cover']) || !is_array($settings['cover'])) {
            return;
        }

        $coverImageId = $sourceCoverImageId ?: $this->getCoverImageId($task);
        if (!$coverImageId || empty($fileResults[$coverImageId]['attachment'])) {
            return;
        }

        $newCoverAttachment = $fileResults[$coverImageId]['attachment'];
        $settings['cover']['imageId'] = $newCoverAttachment->id;
        $settings['cover']['backgroundImage'] = $this->createPublicUrl($newCoverAttachment, $targetBoardId);
        $task->settings = $settings;
    }

    protected function refreshTaskAttachmentCount(Task $task)
    {
        if (!class_exists('\FluentBoardsPro\App\Models\TaskAttachment')) {
            return;
        }

        $settings = $task->settings ?: [];
        $settings['attachment_count'] = \FluentBoardsPro\App\Models\TaskAttachment::where('object_id', $task->id)
            ->where('object_type', Constant::TASK_ATTACHMENT)
            ->count();
        $task->settings = $settings;
    }

    protected function resolveLocalPath(Attachment $attachment, $boardId)
    {
        if (!empty($attachment->file_path) && file_exists($attachment->file_path)) {
            return $attachment->file_path;
        }

        if (!empty($attachment->full_url)) {
            $uploadDir = wp_upload_dir();
            $pathFromUrl = str_replace($uploadDir['baseurl'], $uploadDir['basedir'], $attachment->full_url);
            if (file_exists($pathFromUrl)) {
                return $pathFromUrl;
            }
        }

        if ($boardId && !empty($attachment->file_path)) {
            $pathFromBoard = $this->getBoardDir($boardId) . DIRECTORY_SEPARATOR . basename($attachment->file_path);
            if (file_exists($pathFromBoard)) {
                return $pathFromBoard;
            }
        }

        return null;
    }

    protected function getAttachmentFilename(Attachment $attachment)
    {
        if (!empty($attachment->file_path)) {
            return basename($attachment->file_path);
        }

        $urlPath = wp_parse_url($attachment->full_url, PHP_URL_PATH);
        if ($urlPath) {
            return basename($urlPath);
        }

        return sanitize_file_name($attachment->title ?: wp_generate_uuid4());
    }

    protected function uniqueFilename($dir, $filename)
    {
        $filename = sanitize_file_name($filename);

        if (function_exists('wp_unique_filename')) {
            return wp_unique_filename($dir, $filename);
        }

        $candidate = $filename;
        $index = 1;
        $info = pathinfo($filename);
        $name = $info['filename'] ?? $filename;
        $extension = !empty($info['extension']) ? '.' . $info['extension'] : '';

        while (file_exists($dir . DIRECTORY_SEPARATOR . $candidate)) {
            $candidate = $name . '-' . $index . $extension;
            $index++;
        }

        return $candidate;
    }

    protected function isLocalFileAttachment(Attachment $attachment)
    {
        return $attachment->attachment_type !== 'url'
            && ($attachment->driver === 'local' || empty($attachment->driver));
    }

    protected function shouldStoreAbsolutePath(Attachment $attachment)
    {
        return !empty($attachment->file_path) && file_exists($attachment->file_path);
    }

    protected function getCoverImageId(Task $task)
    {
        $settings = $task->settings;
        return !empty($settings['cover']['imageId']) ? (int) $settings['cover']['imageId'] : null;
    }

    protected function createPublicUrl(Attachment $attachment, $boardId)
    {
        return (new CommentService())->createPublicUrl($attachment, $boardId);
    }

    protected function getBoardDir($boardId)
    {
        return FileSystem::setSubDir('board_' . absint($boardId))->getDir();
    }

    protected function getBoardUrl($boardId)
    {
        $uploadDir = wp_upload_dir();
        $fbsUploadDir = apply_filters('fluent_boards/upload_folder_name', FLUENT_BOARDS_UPLOAD_DIR);

        return $uploadDir['baseurl'] . '/' . trim($fbsUploadDir, '/') . '/board_' . absint($boardId);
    }

    protected function generateFileHash()
    {
        $uid = wp_generate_uuid4();
        return md5($uid . wp_rand(0, 1000));
    }
}
