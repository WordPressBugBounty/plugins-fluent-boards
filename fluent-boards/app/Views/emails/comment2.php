<?php if (!defined('ABSPATH')) exit; // if accessed directly exit
/**
 * Comment, reply and mention emails. Renders through the shared notification card.
 *
 * Senders that predate the structured payload pass only $body, $comment and $comment_link;
 * map the link to the primary action so those emails keep their button.
 */
if (empty($cta) && !empty($comment_link)) {
    $cta = [
        'label' => __('Go to comment', 'fluent-boards'),
        'url'   => $comment_link,
    ];
}

include __DIR__ . '/notification.php';
