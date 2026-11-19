<?php

return [
    'permissions' => ['dashboard.view', 'orders.view', 'orders.update', 'orders.pay', 'pos.use', 'kitchen.use', 'menu.manage', 'inventory.manage', 'kiosks.manage', 'staff.manage', 'reports.view'],
    'roles' => [
        'owner' => ['*'], 'admin' => ['*'],
        'manager' => ['dashboard.view', 'orders.view', 'orders.update', 'orders.pay', 'pos.use', 'kitchen.use', 'menu.manage', 'inventory.manage', 'reports.view'],
        'cashier' => ['orders.view', 'orders.pay', 'pos.use'],
        'kitchen' => ['orders.view', 'orders.update', 'kitchen.use'],
    ],
];
