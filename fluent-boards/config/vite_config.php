<?php return [
    '_prevent-context-menu.js' => [
        'file' => 'prevent-context-menu.js',
        'name' => 'prevent-context-menu',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_taskDateStatus.js'
        ],
        'css' => [
            'admin/prevent-context-menu.css'
        ]
    ],
    '_taskDateStatus.js' => [
        'file' => 'taskDateStatus.js',
        'name' => 'taskDateStatus'
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
    'resources/admin/app.js' => [
        'file' => 'admin/app.js',
        'name' => 'app',
        'src' => 'resources/admin/app.js',
        'isEntry' => true,
        'imports' => [
            '_vendor.js',
            '_prevent-context-menu.js',
            '_vendor-element-plus.js',
            '_taskDateStatus.js'
        ],
        'css' => [
            'admin/app.css'
        ]
    ],
    'resources/admin/crm-contact-app3/app.js' => [
        'file' => 'admin/crm-contact-app3/app.js',
        'name' => 'app',
        'src' => 'resources/admin/crm-contact-app3/app.js',
        'isEntry' => true,
        'imports' => [
            '_vendor.js',
            '_taskDateStatus.js',
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
            '_prevent-context-menu.js',
            '_taskDateStatus.js',
            '_vendor-element-plus.js'
        ]
    ],
    'resources/scss/admin.scss' => [
        'file' => 'admin/admin.css',
        'src' => 'resources/scss/admin.scss',
        'isEntry' => true
    ]
];