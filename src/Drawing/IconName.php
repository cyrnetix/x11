<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Drawing;

/**
 * Semantic icon names used by the app. The registry maps each one to a
 * specific .ico file via the active theme's mapping; switching themes is
 * just swapping the mapping file. New themes need a case-for-case
 * mapping but can leave unfamiliar names unmapped — IconRegistry::get()
 * returns null, callers fall back to a default drawer.
 *
 * Cases roughly mirror the system-icon set ships with classic Windows.
 */
enum IconName: string
{
    // Files / folders
    case Folder           = 'folder';
    case FolderOpen       = 'folder_open';
    case FolderFavorites  = 'folder_favorites';
    case File             = 'file';
    case TextDocument     = 'text_document';
    case BitmapImage      = 'bitmap_image';
    case GifImage         = 'gif_image';
    case JpegImage        = 'jpeg_image';
    case InternetDocument = 'internet_document';
    case ConfigSettings   = 'config_settings';
    case RichText         = 'rich_text';
    case ProgramGroup     = 'program_group';
    case MsDosApplication = 'msdos_application';
    case MsDosBatch       = 'msdos_batch';

    // Devices / drives
    case Computer         = 'computer';
    case MyComputer       = 'my_computer';
    case HardDrive        = 'hard_drive';
    case Floppy35         = 'floppy_35';
    case Floppy525        = 'floppy_525';
    case CdRom            = 'cdrom';
    case RemovableDrive   = 'removable_drive';
    case NetworkDrive     = 'network_drive';
    case RamDrive         = 'ram_drive';

    // Shell places
    case Desktop          = 'desktop';
    case Documents        = 'documents';
    case MyDocuments      = 'my_documents';
    case Network          = 'network';
    case Workgroup        = 'workgroup';
    case Favorites        = 'favorites';
    case Internet         = 'internet';
    case Fonts            = 'fonts';
    case Printers         = 'printers';
    case ControlPanel     = 'control_panel';
    case RecycleBinEmpty  = 'recycle_bin_empty';
    case RecycleBinFull   = 'recycle_bin_full';

    // Media / multimedia
    case AudioCd          = 'audio_cd';
    case WaveSound        = 'wave_sound';
    case MidiSequence     = 'midi_sequence';
    case MediaClip        = 'media_clip';
    case MovieClip        = 'movie_clip';
    case VideoClip        = 'video_clip';

    // Actions / system
    case Find             = 'find';
    case Help             = 'help';
    case Run              = 'run';
    case Settings         = 'settings';
    case LogOff           = 'log_off';
    case ShutDown         = 'shut_down';
    case Suspend          = 'suspend';
    case ScheduledTasks   = 'scheduled_tasks';
    case AdministrativeTools = 'administrative_tools';

    // Fallbacks
    case DefaultDocument  = 'default_document';
    case DefaultIcon      = 'default_icon';
}
