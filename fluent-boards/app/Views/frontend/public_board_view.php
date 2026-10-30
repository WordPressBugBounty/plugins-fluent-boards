<?php if (!defined('ABSPATH')) exit; // if accessed directly exit ?>
<div class="fluent_boards_frontend fluent_boards_public_frontend <?php echo esc_attr($align_class); ?>">
    <div
        class="fbs_public_board_mount"
        data-board-id="<?php echo esc_attr((int)$board_id); ?>"
        data-public-token="<?php echo esc_attr($public_token); ?>"
    ></div>
</div>
