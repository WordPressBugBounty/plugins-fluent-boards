<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Services\PermissionManager;

class BoardMenuHandler
{
   public static function getMenuItems()
    {
        // Get default menu items with positions
        $defaultMenuItems = self::getDefaultMenuItems();
        
        /**
         * Menu Item Structure:
         * 
         * Required: key, label, type, icon, html (not for default menu items)
         * Optional: position, width, render_in, requires_*
         * 
         * Example:
         * [
         *     'key' => 'my_item',
         *     'label' => 'My Item',
         *     'type' => 'default',
         *     'position' => 1,
         *     'width' => '500px',
         *     'html' => '<div>Content</div>'
         * ]
         */
        
        // Apply filter to modify all menu items (default + custom)
        $allMenuItems = apply_filters('fluent_boards/board_menu_items', $defaultMenuItems);
        
        // Sort by position
        /**
         * This ensures that when you add custom menu items with
         * decimal positions (like 10.5 to insert after duplicate_board at position 10),
         * they'll be sorted correctly in the final menu order.
         */
        usort($allMenuItems, function($a, $b) {
            $posA = isset($a['position']) ? (float)$a['position'] : 999;
            $posB = isset($b['position']) ? (float)$b['position'] : 999;

            if ($posA == $posB) {
                return 0;
            }
            return ($posA < $posB) ? -1 : 1;
        });
        
        // Apply server-side permission validation to prevent bypass
        $allMenuItems = self::validateMenuPermissions($allMenuItems);
        
        return $allMenuItems;
    }
    
    private static function getDefaultMenuItems()
    {
        return [
            [
                'key' => 'about_this_board',
                'label' => __('About this Board', 'fluent-boards'),
                'type' => 'default',
                'position' => 1,
                'role' => ''
            ],
            [
                'key' => 'board_activity',
                'label' => __('Board Activity', 'fluent-boards'),
                'type' => 'default',
                'position' => 2,
                'role' => ''
            ],
            [
                'key' => 'change_background',
                'label' => __('Change Background', 'fluent-boards'),
                'type' => 'default',
                'position' => 3,
                'role' => 'manager'
            ],
            [
                'key' => 'notification_settings',
                'label' => __('Notification Settings', 'fluent-boards'),
                'type' => 'default',
                'position' => 4,
                'role' => ''
            ],
            [
                'key' => 'board_labels',
                'label' => __('Board Labels', 'fluent-boards'),
                'type' => 'default',
                'position' => 5,
                'role' => ''
            ],
            [
                'key' => 'custom_fields',
                'label' => __('Custom Fields', 'fluent-boards'),
                'type' => 'default',
                'position' => 6,
                'role' => ''
            ],
            [
                'key' => 'board_members',
                'label' => __('Board Members', 'fluent-boards'),
                'type' => 'default',
                'position' => 7,
                'role' => ''
            ],
            [
                'key' => 'archived_items',
                'label' => __('Archived Items', 'fluent-boards'),
                'type' => 'default',
                'position' => 8,
                'role' => ''
            ],
            [
                'key' => 'webhooks',
                'label' => __('Webhooks', 'fluent-boards'),
                'type' => 'default',
                'position' => 9,
                'role' => ''
            ],
            [
                'key' => 'associated_crm_contacts',
                'label' => __('Associated CRM Contacts', 'fluent-boards'),
                'type' => 'default',
                'position' => 9,
                'role' => ''
            ],
            [
                'key' => 'duplicate_board',
                'label' => __('Duplicate Board', 'fluent-boards'),
                'type' => 'default',
                'position' => 10,
                'role' => 'manager'
            ],
            [
                'key' => 'restore_board',
                'label' => __('Restore Board', 'fluent-boards'),
                'type' => 'default',
                'position' => 10,
                'role' => 'admin'
            ],
            [
                'key' => 'export',
                'label' => __('Export', 'fluent-boards'),
                'type' => 'default',
                'position' => 10.5,
                'role' => 'manager',
                'pro' => true
            ],
            [
                'key' => 'archive_board',
                'label' => __('Archive Board', 'fluent-boards'),
                'type' => 'default',
                'position' => 11,
                'role' => 'admin'
            ],
            [
                'key' => 'delete_board',
                'label' => __('Delete Board', 'fluent-boards'),
                'type' => 'default',
                'position' => 11,
                'role' => 'admin'
            ]
        ];
    }
    
    /**
     * Validate menu permissions server-side to prevent bypass
     * Uses key-based validation with optimized permission checking
     */
    private static function validateMenuPermissions($menuItems)
    {
        $validatedItems = [];

        $isAdmin = PermissionManager::isAdmin();

        foreach ($menuItems as $item) {
            if (empty($item['key']) || empty($item['label'])) {
                continue;
            }

            $key = $item['key'];

            if($key === 'associated_crm_contacts' && !defined('FLUENTCRM')) {
                continue;
            }

            // Enforce default item policy if in whitelist
            if (isset($item['type']) && $item['type'] === 'default') {
                $requiredRole = $item['role'];

                // Enforce role - only check admin role, remove manager role checks
                if ($requiredRole === 'admin' && !$isAdmin) {
                    continue;
                }

                // Enforce pro restriction
                if (isset($item['pro']) && $item['pro'] === true) {
                    $item['requires_pro']  =  true;
                }
            } else {
                // Custom item
                $item['type'] = 'custom';
                $requiredRole = $item['role'] ?? '';

                if ($requiredRole === 'admin' && !$isAdmin) {
                    continue;
                }

                // Optional: validate width
                if (isset($item['width']) && (!is_string($item['width']) || empty($item['width']))) {
                    continue;
                }
            }

            $validatedItems[] = $item;
        }

        return $validatedItems;
    }
} 