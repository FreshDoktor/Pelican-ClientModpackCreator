<?php

// Wird in die activity.php des Panels eingefügt, mit der das Aktivitätsprotokoll seine Einträge übersetzt.
return [
    'server' => [
        'client-modpack' => [
            'build' => 'Hat das Erstellen des Client-Modpacks gestartet',
            'cancel' => 'Hat das Erstellen des Client-Modpacks abgebrochen',
            'settings' => 'Hat die Einstellungen des Client-Modpacks <b>:name</b> geändert',
            'rule' => 'Hat <b>:name</b> im Client-Modpack auf <b>:rule</b> gesetzt',
            'mod-add' => 'Hat die Client-Mod <b>:name</b> (:version) zum Client-Modpack hinzugefügt',
            'mod-remove' => 'Hat die Client-Mod <b>:name</b> aus dem Client-Modpack entfernt',
            'link-reset' => 'Hat einen neuen Download-Link für das Client-Modpack erstellt',
            'delete' => 'Hat das Client-Modpack gelöscht',
        ],
    ],
    'user' => [
        'client-modpack' => [
            'settings' => 'Hat die Download-Adresse der Client-Modpacks auf <b>:url</b>, die größte Mod auf <b>:mod_limit MB</b> und den Inhalt eines Modpacks auf höchstens <b>:embedded_limit MB</b> gesetzt',
        ],
    ],
];
