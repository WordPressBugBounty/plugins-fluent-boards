<?php if (!defined('ABSPATH')) exit; // if accessed directly exit ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?php echo esc_html($title . ' - ' . get_bloginfo('name')); ?></title>
    <?php wp_print_styles(); ?>
    <?php wp_print_head_scripts(); ?>
</head>
<body class="fbs_shared_task_page<?php echo is_rtl() ? ' rtl' : ''; ?>">
    <main class="fluent_boards_frontend fbs_shared_task_main">
        <?php if ($token) : ?>
            <div class="fbs_shared_task_mount" data-share-token="<?php echo esc_attr($token); ?>"></div>
        <?php else : ?>
            <div class="fbs_shared_task_unavailable">
                <h1><?php esc_html_e('Link not available', 'fluent-boards'); ?></h1>
                <p><?php esc_html_e('This share link is invalid or has been disabled.', 'fluent-boards'); ?></p>
            </div>
        <?php endif; ?>
    </main>
    <?php wp_print_footer_scripts(); ?>
</body>
</html>
