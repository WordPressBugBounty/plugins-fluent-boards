<?php return [
    '_UpgradeUrl.js' => [
        'file' => 'UpgradeUrl.js',
        'name' => 'UpgradeUrl',
        'imports' => [
            '_vendor.js',
            '_vendor-element-plus.js',
            '_descriptionToPlainText.js'
        ],
        'dynamicImports' => [
            'resources/admin/Components/Report/Reports.vue',
            'resources/admin/Components/Report/Overview/Overview.vue',
            'resources/admin/Components/Report/Tasks/Tasks.vue',
            'resources/admin/Components/Report/Timesheet/Timesheet.vue',
            'resources/admin/Components/Report/Activity/Activity.vue',
            'resources/admin/Components/Report/Roadmap/Roadmap.vue'
        ],
        'css' => [
            'admin/UpgradeUrl.css'
        ]
    ],
    '__AssigneeProgressList.js' => [
        'file' => '_AssigneeProgressList.js',
        'name' => '_AssigneeProgressList',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_reportHelpers.js',
            '_descriptionToPlainText.js'
        ]
    ],
    '__ChartTypeToggle.js' => [
        'file' => '_ChartTypeToggle.js',
        'name' => '_ChartTypeToggle',
        'imports' => [
            '_descriptionToPlainText.js',
            '_vendor.js'
        ]
    ],
    '__DistributionPanel.js' => [
        'file' => '_DistributionPanel.js',
        'name' => '_DistributionPanel',
        'imports' => [
            '_reportHelpers.js',
            '__ChartTypeToggle.js',
            '_chartPalette.js',
            '_descriptionToPlainText.js',
            '_vendor.js'
        ]
    ],
    '__HorizontalBarPanel.js' => [
        'file' => '_HorizontalBarPanel.js',
        'name' => '_HorizontalBarPanel',
        'imports' => [
            '_reportHelpers.js',
            '_chartPalette.js',
            '_descriptionToPlainText.js',
            '_vendor.js'
        ]
    ],
    '__PriorityDistribution.js' => [
        'file' => '_PriorityDistribution.js',
        'name' => '_PriorityDistribution',
        'imports' => [
            '_reportHelpers.js',
            '_chartPalette.js',
            '_descriptionToPlainText.js',
            '_vendor.js'
        ]
    ],
    '__StatCard.js' => [
        'file' => '_StatCard.js',
        'name' => '_StatCard',
        'imports' => [
            '_descriptionToPlainText.js',
            '_vendor.js'
        ]
    ],
    '_chartPalette.js' => [
        'file' => 'chartPalette.js',
        'name' => 'chartPalette',
        'imports' => [
            '_vendor-echarts.js',
            '_descriptionToPlainText.js',
            '_vendor.js',
            '_UpgradeUrl.js'
        ]
    ],
    '_descriptionToPlainText.js' => [
        'file' => 'descriptionToPlainText.js',
        'name' => 'descriptionToPlainText',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js'
        ]
    ],
    '_reportHelpers.js' => [
        'file' => 'reportHelpers.js',
        'name' => 'reportHelpers',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_descriptionToPlainText.js'
        ]
    ],
    '_vendor-echarts.js' => [
        'file' => 'vendor-echarts.js',
        'name' => 'vendor-echarts',
        'imports' => [
            '_vendor.js'
        ]
    ],
    '_vendor-element-plus.js' => [
        'file' => 'vendor-element-plus.js',
        'name' => 'vendor-element-plus',
        'imports' => [
            '_vendor.js'
        ],
        'css' => [
            'admin/vendor-element-plus.css'
        ]
    ],
    '_vendor.js' => [
        'file' => 'vendor.js',
        'name' => 'vendor',
        'css' => [
            'admin/vendor.css'
        ]
    ],
    'resources/admin/Components/Report/Activity/Activity.vue' => [
        'file' => 'admin/Components/Report/Activity/Activity.js',
        'name' => 'Activity',
        'src' => 'resources/admin/Components/Report/Activity/Activity.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_reportHelpers.js',
            '__StatCard.js',
            '_UpgradeUrl.js',
            '_descriptionToPlainText.js',
            '_vendor.js',
            '__AssigneeProgressList.js',
            '_vendor-element-plus.js'
        ]
    ],
    'resources/admin/Components/Report/Overview/Overview.vue' => [
        'file' => 'admin/Components/Report/Overview/Overview.js',
        'name' => 'Overview',
        'src' => 'resources/admin/Components/Report/Overview/Overview.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_reportHelpers.js',
            '__StatCard.js',
            '__HorizontalBarPanel.js',
            '__PriorityDistribution.js',
            '__DistributionPanel.js',
            '_vendor-element-plus.js',
            '_vendor.js',
            '_descriptionToPlainText.js',
            '_chartPalette.js',
            '_vendor-echarts.js',
            '_UpgradeUrl.js',
            '__ChartTypeToggle.js'
        ]
    ],
    'resources/admin/Components/Report/Reports.vue' => [
        'file' => 'admin/Components/Report/Reports.js',
        'name' => 'Reports',
        'src' => 'resources/admin/Components/Report/Reports.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_descriptionToPlainText.js',
            '_UpgradeUrl.js'
        ]
    ],
    'resources/admin/Components/Report/Roadmap/Roadmap.vue' => [
        'file' => 'admin/Components/Report/Roadmap/Roadmap.js',
        'name' => 'Roadmap',
        'src' => 'resources/admin/Components/Report/Roadmap/Roadmap.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_reportHelpers.js',
            '__StatCard.js',
            '__HorizontalBarPanel.js',
            '__DistributionPanel.js',
            '_chartPalette.js',
            '_descriptionToPlainText.js',
            '_vendor.js',
            '_vendor-element-plus.js',
            '_UpgradeUrl.js',
            '__ChartTypeToggle.js',
            '_vendor-echarts.js'
        ]
    ],
    'resources/admin/Components/Report/Tasks/Tasks.vue' => [
        'file' => 'admin/Components/Report/Tasks/Tasks.js',
        'name' => 'Tasks',
        'src' => 'resources/admin/Components/Report/Tasks/Tasks.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_reportHelpers.js',
            '__HorizontalBarPanel.js',
            '__AssigneeProgressList.js',
            '__PriorityDistribution.js',
            '_vendor-element-plus.js',
            '_vendor.js',
            '_descriptionToPlainText.js',
            '_chartPalette.js',
            '_vendor-echarts.js',
            '_UpgradeUrl.js'
        ]
    ],
    'resources/admin/Components/Report/Timesheet/Timesheet.vue' => [
        'file' => 'admin/Components/Report/Timesheet/Timesheet.js',
        'name' => 'Timesheet',
        'src' => 'resources/admin/Components/Report/Timesheet/Timesheet.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_UpgradeUrl.js',
            '_descriptionToPlainText.js',
            '_reportHelpers.js',
            '__ChartTypeToggle.js',
            '_chartPalette.js',
            '_vendor-echarts.js'
        ]
    ],
    'resources/admin/app.js' => [
        'file' => 'admin/app.js',
        'name' => 'app',
        'src' => 'resources/admin/app.js',
        'isEntry' => true,
        'imports' => [
            '_vendor.js',
            '_UpgradeUrl.js',
            '_vendor-element-plus.js',
            '_descriptionToPlainText.js'
        ]
    ],
    'resources/admin/crm-contact-app3/app.js' => [
        'file' => 'admin/crm-contact-app3/app.js',
        'name' => 'app',
        'src' => 'resources/admin/crm-contact-app3/app.js',
        'isEntry' => true,
        'imports' => [
            '_vendor.js',
            '_descriptionToPlainText.js',
            '_vendor-element-plus.js'
        ],
        'css' => [
            'admin/crm-contact-app3/app.css'
        ]
    ],
    'resources/admin/global_admin.js' => [
        'file' => 'admin/global_admin.js',
        'name' => 'global_admin',
        'src' => 'resources/admin/global_admin.js',
        'isEntry' => true
    ],
    'resources/admin/single_board.js' => [
        'file' => 'admin/single_board.js',
        'name' => 'single_board',
        'src' => 'resources/admin/single_board.js',
        'isEntry' => true,
        'imports' => [
            '_vendor.js',
            '_UpgradeUrl.js',
            '_descriptionToPlainText.js',
            '_vendor-element-plus.js'
        ]
    ],
    'resources/scss/admin.scss' => [
        'file' => 'admin/admin.css',
        'src' => 'resources/scss/admin.scss',
        'isEntry' => true
    ],
    'resources/scss/admin_rtl.scss' => [
        'file' => 'admin/admin_rtl.css',
        'src' => 'resources/scss/admin_rtl.scss',
        'isEntry' => true
    ]
];