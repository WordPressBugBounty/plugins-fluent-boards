<?php

namespace FluentBoards\App\Services;

class PublicAccessService
{
    const TOKEN_DELIMITER = '|';

    public static function generateAccessToken($boardId)
    {
        $boardId = absint($boardId);
        if (!$boardId) {
            return '';
        }

        $signature = self::signature($boardId);
        $payload = $boardId . self::TOKEN_DELIMITER . $signature;

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    public static function validateAccessToken($boardId, $token)
    {
        $boardId = absint($boardId);
        $token = sanitize_text_field($token);

        if (!$boardId || !$token) {
            return false;
        }

        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if (!$decoded || strpos($decoded, self::TOKEN_DELIMITER) === false) {
            return false;
        }

        [$tokenBoardId, $tokenSignature] = explode(self::TOKEN_DELIMITER, $decoded, 2);
        $tokenBoardId = absint($tokenBoardId);
        if (!$tokenBoardId || $tokenBoardId !== $boardId) {
            return false;
        }

        return hash_equals(self::signature($boardId), $tokenSignature);
    }

    public static function sanitizeUsers($users)
    {
        $sanitizedUsers = [];

        foreach ((array)$users as $user) {
            if (!is_object($user) || empty($user->ID)) {
                continue;
            }

            $role = 'Member';
            if (isset($user->pivot) && isset($user->pivot->settings)) {
                $settings = maybe_unserialize($user->pivot->settings);
                if (is_array($settings)) {
                    if (!empty($settings['is_admin'])) {
                        $role = 'Admin';
                    } elseif (!empty($settings['is_viewer_only'])) {
                        $role = 'Viewer';
                    }
                }
            }

            $displayName = isset($user->display_name) ? $user->display_name : '';

            $sanitizedUsers[] = [
                'ID'           => (int)$user->ID,
                'display_name' => $displayName,
                'photo'        => fluent_boards_user_avatar($user->user_email ?? '', $displayName),
                'role'         => $role
            ];
        }

        return $sanitizedUsers;
    }

    private static function signature($boardId)
    {
        $secret = self::getSecretForBoard($boardId);

        return hash_hmac('sha256', 'fluent_boards_public_board_' . absint($boardId), $secret);
    }

    private static function getSecretForBoard($boardId)
    {
        $board = \FluentBoards\App\Models\Board::find($boardId);
        $perBoardSalt = $board ? $board->getMetaByKey('public_token_salt') : '';

        return wp_salt('auth') . $perBoardSalt;
    }

    public static function revokeAccessToken($boardId)
    {
        $board = \FluentBoards\App\Models\Board::find($boardId);
        if ($board) {
            $board->updateMeta('public_token_salt', wp_generate_password(32, true, true));
        }
    }
}

