<?php if (!defined('ABSPATH')) exit; // if accessed directly exit
/**
 * Board invitation email (sent by Fluent Boards Pro).
 *
 * @var array  $userData  Inviter ['display_name', 'photo']
 * @var string $body      "has invited you to join board: <title>"
 * @var string $btn_title Button label
 * @var string $boardLink Invitation URL
 */
$badge = [
    'label' => __('Board invitation', 'fluent-boards'),
    'tone'  => 'accent',
];
$cta = [
    'label' => $btn_title,
    'url'   => $boardLink,
];
$fallback_url = $boardLink;

if (empty($email_reason)) {
    $email_reason = __('If you weren’t expecting this invitation, you can ignore this email.', 'fluent-boards');
}

include __DIR__ . '/notification.php';
