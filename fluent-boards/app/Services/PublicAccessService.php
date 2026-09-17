<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\User;

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

    /**
     * Fields a logged-out visitor may see for a board member or task assignee.
     * Everything else on the WordPress user row (user_login, user_email, ...) is dropped.
     */
    const PUBLIC_USER_FIELDS = ['ID', 'display_name', 'photo', 'role'];

    /**
     * Reduce user records to the public-safe shape defined by PUBLIC_USER_FIELDS.
     *
     * Accepts an ORM collection, a plain array, or any iterable of user models/objects/arrays
     * and always returns a list of plain arrays, so no User model can reach a public response.
     */
    public static function sanitizeUsers($users)
    {
        $sanitizedUsers = [];

        foreach (self::toIterable($users) as $user) {
            if (is_array($user)) {
                $user = (object)$user;
            }

            if (!is_object($user) || empty($user->ID)) {
                continue;
            }

            $role = 'Member';
            $pivot = isset($user->pivot) ? $user->pivot : null;
            if ($pivot && isset($pivot->settings)) {
                $settings = maybe_unserialize($pivot->settings);
                if (is_array($settings)) {
                    if (!empty($settings['is_admin'])) {
                        $role = 'Admin';
                    } elseif (!empty($settings['is_viewer_only'])) {
                        $role = 'Viewer';
                    }
                }
            }

            $displayName = isset($user->display_name) ? (string)$user->display_name : '';
            $email = isset($user->user_email) ? (string)$user->user_email : '';

            $record = [
                'ID'           => (int)$user->ID,
                'display_name' => $displayName,
                'photo'        => fluent_boards_user_avatar($email, $displayName),
                'role'         => $role
            ];

            // Explicit allow-list: anything not in PUBLIC_USER_FIELDS can never reach the response.
            $sanitizedUsers[] = array_intersect_key($record, array_flip(self::PUBLIC_USER_FIELDS));
        }

        return $sanitizedUsers;
    }

    /**
     * Swap a user relation on a model for its public-safe list before the model is serialized.
     *
     * Loaded relations override attributes of the same name during toArray()/JSON encoding,
     * so assigning the sanitized list as an attribute alone is not enough: the relation must be
     * unloaded first. Only the sanitized plain array is left on the model.
     */
    public static function replaceUserRelation($model, $relation)
    {
        if ($model->relationLoaded($relation)) {
            $users = $model->getRelation($relation);
        } else {
            $users = $model->$relation()->get();
        }

        $model->unsetRelation($relation);
        $model->setAttribute($relation, self::sanitizeUsers($users));

        return $model;
    }

    /**
     * Defense in depth for public responses: drop any still-loaded relation that would
     * serialize a WordPress user model, whatever name it was loaded under.
     */
    public static function stripUserRelations($model)
    {
        foreach ($model->getRelations() as $name => $value) {
            if (self::containsUserModel($value)) {
                $model->unsetRelation($name);
            }
        }

        return $model;
    }

    private static function containsUserModel($value)
    {
        if ($value instanceof User) {
            return true;
        }

        foreach (self::toIterable($value) as $item) {
            if ($item instanceof User) {
                return true;
            }
        }

        return false;
    }

    private static function toIterable($value)
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, 'all')) {
            return (array)$value->all();
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value, false);
        }

        return [];
    }

    private static function signature($boardId)
    {
        $secret = self::getSecretForBoard($boardId);

        return hash_hmac('sha256', 'fluent_boards_public_board_' . absint($boardId), $secret);
    }

    private static function getSecretForBoard($boardId)
    {
        $board = Board::find($boardId);
        $perBoardSalt = $board ? $board->getMetaByKey('public_token_salt') : '';

        return wp_salt('auth') . $perBoardSalt;
    }

    public static function revokeAccessToken($boardId)
    {
        $board = Board::find($boardId);
        if ($board) {
            $board->updateMeta('public_token_salt', wp_generate_password(32, true, true));
        }
    }
}

