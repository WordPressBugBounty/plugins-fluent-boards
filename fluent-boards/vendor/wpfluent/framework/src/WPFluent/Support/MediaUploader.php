<?php

namespace FluentBoards\Framework\Support;

use RuntimeException;
use InvalidArgumentException;

class MediaUploader
{
    /**
     * upload a local file array (e.g. from $_FILES) or a remote file URL.
     *
     * @param array|string $resource  File array from $_FILES or remote file URL
     * @param int|null $postId        Optional post ID to attach the media to
     * @param string|null $filename   Optional file name for remote URLs
     *
     * @return array
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @see https://developer.wordpress.org/reference/functions/media_handle_sideload/
     */
    public static function upload($resource, $postId = 0, $filename = null)
    {
        [$fileArray, $tmp] = static::prepareMedia($resource, $filename);

        $attachmentId = media_handle_sideload($fileArray, $postId);

        if ($tmp && file_exists($tmp)) {
            @unlink($tmp);
        }

        if (is_wp_error($attachmentId)) {
            throw new RuntimeException(
                'Failed to sideload media: ' . $attachmentId->get_error_message()
            );
        }

        return [
            'success' => true,
            'id'      => $attachmentId,
            'url'     => wp_get_attachment_url($attachmentId),
        ];
    }

    /**
     * Prepare the file array to upload.
     * 
     * @param  string|array $resource url or $_FILES
     * @param  string|null $filename
     * @return array
     */
    protected static function prepareMedia($resource, $filename = null)
    {
        static::includeMediaFunctions();

        if (is_array($resource)) {
            if (empty($resource['tmp_name'])) {
                throw new InvalidArgumentException('Invalid file array: tmp_name is required.');
            }

            return [[
                'name'     => $resource['name'] ?? 'file',
                'tmp_name' => $resource['tmp_name'],
                'type'     => $resource['type'] ?? null,
                'error'    => $resource['error'] ?? 0,
                'size'     => $resource['size_in_bytes'] ?? $resource['size'] ?? null,
            ], null];
        }

        if (is_string($resource) && filter_var($resource, FILTER_VALIDATE_URL)) {
            $tmp = download_url($resource);

            if (is_wp_error($tmp)) {
                throw new RuntimeException(
                    'Failed to download remote file: ' . $tmp->get_error_message()
                );
            }

            return [[
                'tmp_name' => $tmp,
                'name'     => $filename ?: basename(
                    parse_url($resource, PHP_URL_PATH)
                ),
            ], $tmp];
        }

        throw new InvalidArgumentException('Invalid file array or URL provided.');
    }

    /**
     * Ensure required WordPress media functions are loaded.
     */
    protected static function includeMediaFunctions()
    {
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
    }
}
