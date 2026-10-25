<?php

// Merged into the panel's activity.php, which the activity log translates its entries with.
return [
    'server' => [
        'client-modpack' => [
            'build' => 'Started building the client modpack',
            'cancel' => 'Cancelled building the client modpack',
            'settings' => 'Changed the settings of the client modpack <b>:name</b>',
            'rule' => 'Set <b>:name</b> to <b>:rule</b> in the client modpack',
            'mod-add' => 'Added the client mod <b>:name</b> (:version) to the client modpack',
            'mod-remove' => 'Removed the client mod <b>:name</b> from the client modpack',
            'link-reset' => 'Created a new download link for the client modpack',
            'delete' => 'Deleted the client modpack',
        ],
    ],
    'user' => [
        'client-modpack' => [
            'settings' => 'Set the client modpack download address to <b>:url</b>, the largest mod to <b>:mod_limit MB</b> and the most a modpack may hold to <b>:embedded_limit MB</b>',
        ],
    ],
];
