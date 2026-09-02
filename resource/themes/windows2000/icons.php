<?php
declare(strict_types=1);

use Cyrnetix\X11\Drawing\IconName;

/**
 * Windows 2000 theme icon mapping. Each entry maps an IconName enum case
 * to a filename inside this theme's system/ directory. New themes can
 * leave names unmapped — IconRegistry::get() returns null and callers
 * fall back to their default drawer.
 */
return [
    // Files / folders
    IconName::Folder->value           => 'Windows 2000 Closed Folder.ico',
    IconName::FolderOpen->value       => 'Windows 2000 Open Folder.ico',
    IconName::FolderFavorites->value  => 'Windows 2000 Favorites Folder.ico',
    IconName::File->value             => 'Windows 2000 Default Document.ico',
    IconName::TextDocument->value     => 'Windows 2000 Text Document.ico',
    IconName::BitmapImage->value      => 'Windows 2000 Bitmap Image.ico',
    IconName::GifImage->value         => 'Windows 2000 GIF Image.ico',
    IconName::JpegImage->value        => 'Windows 2000 JPEG Image.ico',
    IconName::InternetDocument->value => 'Windows 2000 Internet Document.ico',
    IconName::ConfigSettings->value   => 'Windows 2000 Configuration Settings.ico',
    IconName::RichText->value         => 'Windows 2000 Rich Text Format.ico',
    IconName::ProgramGroup->value     => 'Windows 2000 Program Group.ico',
    IconName::MsDosApplication->value => 'Windows 2000 MS-DOS Application.ico',
    IconName::MsDosBatch->value       => 'Windows 2000 MS-DOS Batch File.ico',

    // Devices / drives
    IconName::Computer->value         => 'Windows 2000 Computer.ico',
    IconName::MyComputer->value       => 'Windows 2000 My Computer.ico',
    IconName::HardDrive->value        => 'Windows 2000 Hard Drive.ico',
    IconName::Floppy35->value         => 'Windows 2000 Floppy Drive 3½.ico',
    IconName::Floppy525->value        => 'Windows 2000 Floppy Drive 5¼.ico',
    IconName::CdRom->value            => 'Windows 2000 CD-ROM Drive.ico',
    IconName::RemovableDrive->value   => 'Windows 2000 Removable Drive.ico',
    IconName::NetworkDrive->value     => 'Windows 2000 Network Drive (connected).ico',
    IconName::RamDrive->value         => 'Windows 2000 RAM Drive.ico',

    // Shell places
    IconName::Desktop->value          => 'Windows 2000 Desktop.ico',
    IconName::Documents->value        => 'Windows 2000 Documents.ico',
    IconName::MyDocuments->value      => 'Windows 2000 My Documents.ico',
    IconName::Network->value          => 'Windows 2000 Network Neighborhood.ico',
    IconName::Workgroup->value        => 'Windows 2000 Workgroup.ico',
    IconName::Favorites->value        => 'Windows 2000 Favorites.ico',
    IconName::Internet->value         => 'Windows 2000 The Internet.ico',
    IconName::Fonts->value            => 'Windows 2000 Fonts.ico',
    IconName::Printers->value         => 'Windows 2000 Printers.ico',
    IconName::ControlPanel->value     => 'Windows 2000 Control Panel.ico',
    IconName::RecycleBinEmpty->value  => 'Windows 2000 Recycle Bin (empty).ico',
    IconName::RecycleBinFull->value   => 'Windows 2000 Recycle Bin (full).ico',

    // Media / multimedia
    IconName::AudioCd->value          => 'Windows 2000 Audio CD.ico',
    IconName::WaveSound->value        => 'Windows 2000 Wave Sound.ico',
    IconName::MidiSequence->value     => 'Windows 2000 MIDI Sequence.ico',
    IconName::MediaClip->value        => 'Windows 2000 Media Clip.ico',
    IconName::MovieClip->value        => 'Windows 2000 Movie Clip.ico',
    IconName::VideoClip->value        => 'Windows 2000 Video Clip.ico',

    // Actions / system
    IconName::Find->value             => 'Windows 2000 Find.ico',
    IconName::Help->value             => 'Windows 2000 Help.ico',
    IconName::Run->value              => 'Windows 2000 Run.ico',
    IconName::Settings->value         => 'Windows 2000 Settings.ico',
    IconName::LogOff->value           => 'Windows 2000 Log Off.ico',
    IconName::ShutDown->value         => 'Windows 2000 Shut Down.ico',
    IconName::Suspend->value          => 'Windows 2000 Suspend.ico',
    IconName::ScheduledTasks->value   => 'Windows 2000 Scheduled Tasks.ico',
    IconName::AdministrativeTools->value => 'Windows 2000 Administrative Tools.ico',

    // Fallbacks
    IconName::DefaultDocument->value  => 'Windows 2000 Default Document.ico',
    IconName::DefaultIcon->value      => 'Windows 2000 Default Icon.ico',
];
