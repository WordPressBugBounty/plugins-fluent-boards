<?php

namespace FluentBoards\App\Hooks\Handlers;

use DateTimeImmutable;
use Exception;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Libs\FileSystem;
use FluentBoards\App\Models\Attachment;
use function Sodium\add;

class FileHandler
{
//    private function validateFile($file)
//    {
//        if (!$file) {
//            throw new Exception('File is empty.');
//        }
//        if (!$this->isFileTypeSupported($file)) {
//            throw new Exception('File type not supported');
//        }
//        if ($file['size'] > $this->getFileUploadLimit()) {
//            throw new Exception('File size is too large');
//        }
//    }

    private function getFileUploadLimit() {
        // Logic for calculating file upload limit as in your original code
        return min(
            wp_convert_hr_to_bytes(ini_get('upload_max_filesize')),
            wp_convert_hr_to_bytes(ini_get('post_max_size')),
            wp_max_upload_size()
        );
    }

    /**
     * Summary of deleteFileByUrl
     * @param mixed $file_url
     * @return bool
     */
    public function deleteFileByUrl($file_url)
    {
        $exists = Attachment::where('full_url', $file_url)->exists();
        if($exists) {
            return;
        }
        // Convert the URL to the local file path
        $upload_dir = wp_upload_dir();
        $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_url);

        // Check if the file exists
        if (file_exists($file_path)) {
            // Delete the file
            $deleted = unlink($file_path);

            // Optionally, you can also remove the file from the media library
            // Note: This won't delete the file physically, but it will remove it from the media library
            $attachment_id = attachment_url_to_postid($file_url);
            if ($attachment_id) {
                wp_delete_attachment($attachment_id, true);
            }

            // Return true if the file was successfully deleted
            return $deleted;
        }
        // Return false if the file does not exist
        return false;
    }


    /**
     * Summary of isFileTypeSupported checking file type it will allow only file which is readable by browser
     * @param mixed $file
     * TODO: Refactorable: This can be in a Helper class. and we may pass it to frontend via wp_localize_script appvars
     * so that we can check similarly for better experience.
     * @return bool
     */
    public function isFileTypeSupported($file)
    {
        // Define supported file types that are generally allowed by user
        $allowedMimeTypes = get_allowed_mime_types();

        // Check if the file type is supported
        return in_array(strtolower($file['type']), $allowedMimeTypes);
    }
    
    /**
     * @throws Exception
     */
    public function handleMediaFileUpload($data)
    {
        // Check if the uploaded file is an image
        $wp_filetype = wp_check_filetype_and_ext( $_FILES['file']['tmp_name'], $_FILES['file']['name'] );

        if ( ! wp_match_mime_types( 'image', $wp_filetype['type'] ) ) {
            throw new Exception('The uploaded file is not a valid image. Please try again.');
        }
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        $attachment_id = media_handle_upload( 'file', 0, [] );

        $attachment = wp_prepare_attachment_for_js( $attachment_id);
        if(!$attachment) {
            throw new Exception('The uploaded file is not a valid image. Please try again.');
        } else {
            return $attachment;
        }
    }

    /**
     * This function will delete meta where default board default image is used.
     * @param $id
     * @return void
     */
    public function mediaFileDeleted($id)
    {
        $boardImage = Meta::where('object_id', $id)->where('object_type', Constant::BOARD_DEFAULT_IMAGE)->first();
        if($boardImage) {
            $boardImage->delete();
        }
    }
}
