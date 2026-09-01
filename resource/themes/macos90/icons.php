<?php
declare(strict_types=1);

use Cyrnetix\X11\Drawing\IconName;

/**
 * Mac OS 9 icon mapping. Same contract as the Windows 2000 set: each entry maps
 * an IconName case to a filename inside this theme's system/ directory, and any
 * name left out simply falls back to the caller's own drawer.
 *
 * These arrived as 64×64 PNGs numbered 1–92 with no labels; system/ holds the
 * ones that could be identified from the artwork (see README.md for the original
 * numbers) and extra/ keeps the rest untouched for whenever someone recognises
 * them. The loader box-downscales to the size the widget asks for.
 *
 * Two Mac-specific notes:
 *  - There is no open-folder icon, because Mac OS never drew one — folders look
 *    the same expanded, so FolderOpen deliberately points at the same file.
 *  - Find maps to the Sherlock globe-and-magnifier, which is what "find" meant
 *    on this system.
 */
return [
    // Files / folders
    IconName::Folder->value           => 'folder.png',
    IconName::FolderOpen->value       => 'folder.png',
    IconName::FolderFavorites->value  => 'folder-favorites.png',
    IconName::File->value             => 'document-blank.png',
    IconName::TextDocument->value     => 'document-text.png',
    IconName::RichText->value         => 'document-write.png',
    IconName::ConfigSettings->value   => 'document-settings.png',
    IconName::InternetDocument->value => 'document-internet.png',
    IconName::BitmapImage->value      => 'document-draw.png',
    IconName::ProgramGroup->value     => 'folder-apple-menu.png',

    // Devices / drives
    IconName::Computer->value         => 'computer.png',
    IconName::MyComputer->value       => 'computer.png',
    IconName::HardDrive->value        => 'drive-hard.png',
    IconName::Floppy35->value         => 'disk-floppy.png',
    IconName::CdRom->value            => 'disc-cd.png',
    IconName::RemovableDrive->value   => 'drive-removable.png',
    IconName::NetworkDrive->value     => 'drive-network.png',

    // Shell places
    IconName::Desktop->value          => 'display.png',
    IconName::Documents->value        => 'folder-documents.png',
    IconName::MyDocuments->value      => 'folder-documents.png',
    IconName::Network->value          => 'network.png',
    IconName::Workgroup->value        => 'network.png',
    IconName::Favorites->value        => 'folder-favorites.png',
    IconName::Internet->value         => 'internet-globe.png',
    IconName::Fonts->value            => 'suitcase-font.png',
    IconName::Printers->value         => 'printer.png',
    IconName::ControlPanel->value     => 'folder-control-panels.png',
    IconName::RecycleBinEmpty->value  => 'trash-empty.png',
    IconName::RecycleBinFull->value   => 'trash-full.png',

    // Media
    IconName::AudioCd->value          => 'disc-cd.png',
    IconName::WaveSound->value        => 'document-sound.png',

    // Actions / system
    IconName::Find->value             => 'find-sherlock.png',
    IconName::Help->value             => 'folder-help.png',
    IconName::Settings->value         => 'folder-preferences.png',
    IconName::ScheduledTasks->value   => 'folder-scripts.png',
    IconName::AdministrativeTools->value => 'folder-utilities.png',

    // Fallbacks
    IconName::DefaultDocument->value  => 'document-blank.png',
    IconName::DefaultIcon->value      => 'document-blank.png',
];
