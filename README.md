# Cyrnetix X11

> **A hobby project, written with AI assistance.** It started as a "can this even
> work?" question — a real, usable GUI in PHP, drawn in the styles of the desktops
> I grew up with — and the answer turned out to be yes. It is shared because it
> might be interesting or useful to someone, not because anyone needs another
> widget toolkit. Expect the rough edges of a side project: no stability promises,
> and the [limits](#status-and-limits) below are real ones.

A from-scratch X11 client and themeable widget toolkit in PHP. There is no Xlib
and no xcb binding: this speaks the X11 wire protocol directly over a
Unix-domain socket, assembling every request with `pack()` and parsing the
32-byte event records the server pushes back. Everything runs on the ReactPHP
event loop, so the window stays responsive while it waits on socket data, reply
futures and timers.

It ships a widget toolkit and five complete themes, transcribed pixel by pixel
from real screenshots rather than approximated.

```php
$themes = new ThemeManager(new Win9xTheme(), new PlatinumTheme());
$frame  = new WindowFrame(360, 200, 'Hello', drawsChrome: true);

$frame->addChild(new Label('Built on cyrnetix/x11', 16, 16));
$frame->addChild(new Button('Say hello', 16, 48, 100, 22, $ui));

$client->connect();
Loop::run();
```

`example/app.php` is that, complete and runnable, in 125 lines.

## Requirements

- PHP 8.1 or newer
- A reachable X server. The default socket is `unix:///tmp/.X11-unix/X0`
  (`X11Client::connect()` takes another path). Under WSL2, WSLg provides one.

No PHP extensions beyond the defaults — the ICO and PNG icon decoders are
written in PHP, so `ext-gd` is not needed either.

## Install

Not on Packagist, so point Composer at the repository:

```jsonc
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/cyrnetix/x11.git" }
    ],
    "require": {
        "cyrnetix/x11": "^0.1"
    }
}
```

**`0.x` means the API can change under you.** `^0.1` pins you to the `0.1.*`
line, which is the containment Composer gives a pre-1.0 package — a `0.2.0` will
not be picked up silently. To follow the branch instead of a release, use
`dev-main`.

## Themes

Five eras, switchable at runtime. Nothing is re-wired on a switch: painters read
the live theme per call, so the whole window changes era in place.

| id | era |
|---|---|
| `win9x` | Windows 9x / 2000 |
| `win31` | Windows 3.1x |
| `platinum` | Mac OS 9 |
| `cde` | CDE / Motif, the Solaris desktop |
| `beos` | BeOS R5, including its partial-width yellow window tab |

A theme is a palette, a set of metrics and a `Chrome` — the drawing primitives,
expressed as era-neutral *intentions* (`button()`, `well()`, `edge(Edge::Sunken)`,
`caption()`) rather than as colours. Adding one touches no widget, painter or
handler.

The eras genuinely disagree about things it is tempting to assume: whether a
selection reverses or tints, whether a checkbox sinks or raises, which end of the
trough the scrollbar arrows live at, whether a dialog has a close box at all. So
when adjusting a theme, sample the original rather than recalling it.

## Widgets

Buttons, checkboxes, radio buttons, labels, group boxes, separators, text boxes
(with a masked variant), a read-only multi-line text view, scrollbars, trackbars,
progress bars (including a marquee), list boxes, drop-downs, combo boxes,
tab views, menu bars with submenus, toolbars, rebars, status bars, tooltips,
tree views with lazy children, list views in "details" mode with sortable
columns, up-down spinners, a date/time picker with a drop-down calendar, message
boxes, a file/folder picker, and a canvas.

The canvas is the escape hatch: the application writes pixels into a framebuffer
and `PutImage` blits them, so anything the toolkit has no widget for — a drawing
surface, a plot, a software renderer's output — is still a widget. Its frame is
the theme's; the pixels inside it are yours. Only the damaged rectangle is sent,
which is what keeps a freehand stroke to a few hundred bytes a motion event.

The window frame is the toolkit's too: it asks the window manager not to
decorate, then draws the caption, its buttons and the resize border itself, so
they match the theme. A window can be fixed-size — `WindowFrame(resizable: false)`
drops the maximise button and the border drag, and `X11Client::setResizable(false)`
tells the window manager the same through `WM_NORMAL_HINTS`.

**Closing is the application's decision.** The close button dispatches
`WindowCloseRequestedEvent` rather than tearing the window down, so you can prompt
about unsaved work first — answer it with `X11Client::closeWindow()`, or the button
does nothing. On a server with a 32-bit ARGB visual and the SHAPE
extension, BeOS's partial tab is a genuine hole you can see the desktop through.

## Examples

```bash
php example/app.php                       # the smallest complete app — read this first
php example/calculator.php                # a button grid, the keyboard, F2 to cycle themes
php example/todo.php                      # the open/save dialogs, a menu bar, a checkable list
php example/paint.php                     # a canvas: tools, undo, a palette that is a canvas
php example/child-window.php --open-form  # a form in its own top-level window
php example/widget-gallery.php            # the kitchen sink: every widget, all five themes
```

Every one takes `--theme=<id>`. `widget-gallery.php` also takes `--tab=N`,
`--file-dialog=open|save|folder` and `--decorations=wm` (hand the caption back to
the window manager).

## Architecture

The short version. The long one lives in the comments: almost every non-obvious
decision here has one saying *why*, usually naming the bug that produced it.

- **`Client\X11Client`** owns the socket and every opcode. Search it for
  `opcode` to find the catalogue. Replies arrive in request order, matched by a
  FIFO of handlers.
- **Two dispatchers, deliberately.** `Dispatcher\AsyncEventDispatcher` (PSR-14,
  backed by a `clue/mq-react` queue with concurrency 1) serialises every X11
  event, so handlers never overlap. `UI\SyncEventDispatcher` dispatches widget
  events inline, because they are raised *from inside* an already-serialised
  handler and queuing them would only reorder them.
- **One handler per widget kind.** `UI\WidgetManager` is a router: it walks an
  ordered list of handlers and stops at the first one that claims the event.
  Handler order is policy — popup owners first, scrollbars before the lists they
  belong to.
- **Themes own the look, painters own the layout.** A painter holds no state,
  never names a colour and never hand-rolls a bevel; it computes *where* things
  go and asks the chrome to draw them.
- **Damage-based repainting.** `redrawRegion()` clips to what changed and can
  narrow the walk to one subtree, so dragging a scrollbar does not repaint the
  window.

## Tests

```bash
composer test        # or: php tests/run.php
```

Plain scripts rather than PHPUnit: each prints what it checked and exits
non-zero on failure, so a run reads as a transcript. None of them need an X
server: the ones that check what goes over the wire — `caption_test.php`,
`canvas_test.php` — write into a fake connection and read the bytes back out.

## Status and limits

**Current release: `v0.1.0`.** It works, and it is used for two small
internal tools of mine — which is a different claim from "production ready". This is a hobby project built with AI
assistance, so treat the API as unstable and read the code before you depend on
it. The upside of that provenance is that almost every non-obvious decision has a
comment saying *why*, usually naming the bug that produced it.

Known gaps:

- Resize cursors over the window border are not implemented.
- Not every window manager honours everything. On Weston/WSLg the undecorate
  hint and maximise both work, but `WM_CHANGE_STATE` iconification is silently
  ignored, so minimise does nothing there.
- The core X11 fonts are `iso8859-1`, so text outside Latin-1 needs
  transliterating before it is drawn. There is no Xft or client-side font
  rendering.
- Text is drawn in a proportional face, so anything aligned with spaces will
  look ragged. There is no monospaced third face.
- A canvas has no scrolling viewport: an image larger than the widget is clipped
  rather than scrolled. Nor is the GC's raster `function` exposed, so there is no
  `GXxor` for a rubber band drawn straight onto the window — a canvas restores
  from a snapshot instead, which is better behaved but needs a framebuffer.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). The short version: match the surrounding
code, keep colours inside `src/Theme/`, and say *why* in a comment where the
reason is not obvious from the code.

## Licence

MIT — see [LICENSE](LICENSE).
