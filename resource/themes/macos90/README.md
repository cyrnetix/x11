# Mac OS 9 icon set

64×64 PNGs, originally delivered unlabelled as `1.png` … `92.png` (63 was absent).
`system/` holds the ones this theme actually maps.

**The set was trimmed to what `icons.php` names.** `IconName` is a fixed enum, so
a file no entry points at cannot be reached by this library or by anything using
it — 49 unidentified PNGs and 12 identified but unmapped ones were removed rather
than shipped in a published package. Nothing is lost: they are in the git history
(`git show e9af229 -- resource/themes/macos90`), and the tables below still say
which numbered original each one was, so re-adding one means restoring the file
and giving `IconName` a case for it.

Loaded by `PngLoader`, which box-downscales to whatever size the widget asks for
(16px in the demo) and thresholds the alpha channel, since the X11 renderer has
no alpha blending.

## Identified — `system/`

| file | was | artwork |
|---|---|---|
| `folder.png` | 24 | plain closed folder |
| `folder-favorites.png` | 17 | folder with a pink bookmark |
| `folder-documents.png` | 37 | folder holding documents |
| `folder-preferences.png` | 38 | folder with option buttons |
| `folder-control-panels.png` | 46 | folder with a control panel |
| `folder-help.png` | 48 | folder with a question mark |
| `folder-utilities.png` | 58 | folder with tools |
| `folder-scripts.png` | 52 | folder with the automation robot |
| `folder-apple-menu.png` | 32 | folder with the rainbow Apple |
| `find-sherlock.png` | 12 | globe with a magnifier (Sherlock) |
| `document-blank.png` | 10 | empty page |
| `document-text.png` | 79 | page of text |
| `document-write.png` | 28 | page with a pencil |
| `document-settings.png` | 88 | page with settings controls |
| `document-internet.png` | 69 | page with a globe |
| `document-sound.png` | 68 | page with a speaker |
| `document-draw.png` | 80 | drawing page with a pen |
| `suitcase-font.png` | 74 | font suitcase |
| `drive-hard.png` | 1 | hard disk |
| `drive-removable.png` | 5 | removable drive |
| `drive-network.png` | 83 | drive on a network cable |
| `disk-floppy.png` | 25 | 3.5" floppy |
| `disc-cd.png` | 4 | CD |
| `computer.png` | 22 | tower with the Finder face |
| `network.png` | 82 | two Macs wired together |
| `internet-globe.png` | 81 | globe on a stand |
| `printer.png` | 85 | printer |
| `display.png` | 3 | monitor / window |
| `trash-empty.png` | 7 | empty wastebasket |
| `trash-full.png` | 6 | wastebasket with paper |

## Identified but removed — no `IconName` case fits them

These were recognised, but the enum has no case they belong to, so nothing could
ever load them. Adding a case is all it takes to bring one back.

| file | was | artwork |
|---|---|---|
| `folder-extensions.png` | 39 | folder with a puzzle piece |
| `folder-fonts.png` | 42 | folder with an "A" |
| `folder-printers.png` | 45 | folder with a printer |
| `folder-appearance.png` | 62 | folder with colour swatches |
| `folder-system.png` | 31 | folder with the Finder face (System Folder) |
| `document-table.png` | 92 | page with a table |
| `archive-zip.png` | 90 | `.ZIP` archive |
| `archive-sit.png` | 91 | StuffIt `.SIT` archive |
| `drive-offline.png` | 84 | drive with a "no entry" badge |
| `disk-floppy-pc.png` | 66 | 3.5" floppy marked "PC" |
| `calculator.png` | 73 | calculator |
| `book.png` | 50 | folder with a blue book |

## Unidentified — removed

Mostly further folder variants (badged with speakers, plugs, faces, locks and so
on) plus a few suitcases and documents. Numbers, for anyone recovering them from
the history: 2, 8, 9, 11, 13–16, 18–21, 23, 26, 27, 29, 30, 33–36, 40, 41, 43,
44, 47, 49, 51, 53–57, 59–61, 64, 65, 67, 70–72, 75–78, 86, 87, 89.
