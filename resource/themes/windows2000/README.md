# Windows 2000 icon set

`.ico` files, decoded by `IcoLoader`, which reads the directory and picks the
entry closest to the size the widget asked for.

Used by the `win9x` theme and borrowed by `win31`, which had no icons of its own
worth reproducing at 16px. It maps all 52 `IconName` cases.

**The set ships only what `icons.php` names.** `IconName` is a fixed enum, so a
file no entry points at cannot be reached by this library or by anything using
it. Ten such files were removed before publication — every case was already
mapped, so they had nowhere to go:

| file | why it had no home |
|---|---|
| `Windows 2000 Settings - Control Panel.ico` | `control_panel` maps to the plainer Control Panel icon |
| `Windows 2000 Settings - Printers.ico` | `printers` maps to the plainer Printers icon |
| `Windows 2000 Programs.ico` | duplicate of the mapped Program Group |
| `Windows 2000 Entire Network.ico` | `network` maps to the Network Neighborhood icon |
| `Windows 2000 Network Drive (offline).ico` | no enum case for a disconnected drive |
| `Windows 2000 Dial-Up Networking.ico` | no enum case |
| `Windows 2000 Write Document.ico` | no enum case; `rich_text` covers the WordPad case |
| `Windows 2000 Zip File.ico` | no enum case for archives |
| `Windows 2000 Sharing Overlay.ico` | an overlay, not a standalone icon |
| `Windows 2000 Shortcut Overlay.ico` | an overlay, not a standalone icon |

They are in the git history if a case is ever added for one.
