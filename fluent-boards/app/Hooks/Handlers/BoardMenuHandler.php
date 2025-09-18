<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Services\PermissionManager;

class BoardMenuHandler
{
   public static function getMenuItems($board_id = null)
    {
        // Get default menu items with positions
        $defaultMenuItems = self::getDefaultMenuItems($board_id);
        
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
        $allMenuItems = apply_filters('fluent_boards/board_menu_items', $defaultMenuItems, $board_id);
        
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
        $allMenuItems = self::validateMenuPermissions($allMenuItems, $board_id);
        
        return $allMenuItems;
    }
    
    private static function getDefaultMenuItems($board_id = null)
    {
        // $board = Board::find($board_id);
        // if (!$board) {
        //     return [];
        // }

        // // Cache permission and state checks
        // $isAdmin = PermissionManager::isAdmin();
        // $isManager = $isAdmin || PermissionManager::isBoardManager($board_id);
        // $isArchived = (bool) $board->archived_at;
        // $isViewerOnly = (bool) $board->isUserOnlyViewer;

        // Non-conditional menu items (always shown)
        $defaultItems = [
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
        ];
    
        // Conditional items
        
        // Notification Settings (only if not viewer only)
        // if (!$isViewerOnly) {
            $notificationItems = [
                [
                    'key' => 'notification_settings',
                    'label' => __('Notification Settings', 'fluent-boards'),
                    'type' => 'default',
                    'position' => 4,
                    'role' => ''
                ]
            ];
            $defaultItems = array_merge($defaultItems, $notificationItems);
        // }
        
        // Change Background (only for admins/managers and non-archived boards)
        // if ($isManager && !$isArchived) {
            $backgroundItems = [
                [
                    'key' => 'change_background',
                    'label' => __('Change Background', 'fluent-boards'),
                    'type' => 'default',
                    'position' => 3,
                    'role' => 'manager'
                ]
            ];
            $defaultItems = array_merge($defaultItems, $backgroundItems);
        // }
        
        // Associated CRM Contacts (only if FluentCRM exists)
        // if (!!defined('FLUENTCRM')) {
            $crmItems = [
                [
                    'key' => 'associated_crm_contacts',
                    'label' => __('Associated CRM Contacts', 'fluent-boards'),
                    'type' => 'default',
                    'position' => 9,
                    'role' => ''
                ]
            ];
            $defaultItems = array_merge($defaultItems, $crmItems);
        // }
        
        // Admin/Manager only items for non-archived boards
        // if ($isManager && !$isArchived) {
            $adminItems = [
                [
                    'key' => 'duplicate_board',
                    'label' => __('Duplicate Board', 'fluent-boards'),
                    'type' => 'default',
                    'position' => 10,
                    'role' => 'manager'
                ],
                [
                    'key' => 'archive_board',
                    'label' => __('Archive Board', 'fluent-boards'),
                    'type' => 'default',
                    'position' => 11,
                    'role' => 'manager'
                ]
            ];
            $defaultItems = array_merge($defaultItems, $adminItems);
        // } else if ($isManager && $isArchived) {
            $archivedAdminItems = [
                [
                    'key' => 'restore_board',
                    'label' => __('Restore Board', 'fluent-boards'),
                    'type' => 'default',
                    'position' => 10,
                    'role' => 'manager'
                ],
                [
                    'key' => 'delete_board',
                    'label' => __('Delete Board', 'fluent-boards'),
                    'type' => 'default',
                    'position' => 11,
                    'role' => 'admin'
                ]
            ];
            $defaultItems = array_merge($defaultItems, $archivedAdminItems);
        // }
        
        return $defaultItems;
    }
    
    /**
     * Validate menu permissions server-side to prevent bypass
     * Uses key-based validation with optimized permission checking
     */
    private static function validateMenuPermissions($menuItems, $board_id)
    {
        $validatedItems = [];

        $isAdmin = PermissionManager::isAdmin();
        $isManager = $isAdmin || PermissionManager::isBoardManager($board_id);

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

                // Enforce role
                if ($requiredRole === 'admin' && !$isAdmin) {
                    continue;
                }
                if ($requiredRole === 'manager' && !$isManager) {
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
                if ($requiredRole === 'manager' && !$isManager) {
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