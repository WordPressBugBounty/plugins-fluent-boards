<?php
//if accessed directly exit
if (!defined('ABSPATH')) exit;

(new \FluentBoards\App\Hooks\Handlers\ShortcodeHandler())->register();

