# Contributing

This is a hobby project maintained in spare time, so replies may be slow — but
bug reports, themes and widgets are all welcome. A few conventions keep this
codebase coherent — they are not style preferences, each one exists because
breaking it produced a bug that was hard to see.

## Getting set up

```bash
composer install
php example/app.php     # needs a reachable X server
composer test
```

There is no build step and no code generator.

## The conventions that matter

- **No colour literals outside `src/Theme/`.** A painter, widget or handler asks
  the palette for a *role* (`face`, `content`, `selection`, `captionActive`). A
  hardcoded `[212, 208, 200]` anywhere else is a bug — it is invisible in one
  theme and wrong in the other four.
- **No hand-rolled bevels.** Call `Chrome::edge()`, `button()` or `well()`. If a
  shape does not exist yet, add a case to `Edge` or a method to `Chrome`, so
  every theme can answer for it.
- **One number, two readers.** A size used by both hit-testing and painting lives
  in `Metrics` and is read through `$widget->metrics()` on both sides. Never
  re-derive it in the painter: a theme that widens the scrollbar has to widen the
  *clickable* scrollbar too.
- **Painters hold no state and never mutate widgets.** They read the widget and
  draw. State changes belong on the widget or in its handler.
- **One handler per widget kind.** Keep `paint()` and the `try*` methods in
  `FooHandler`. Do not add a central `match (true)` dispatch to `WidgetManager`.
- **A widget's callback slots belong to the widget.** `setOnClick()` and friends
  hold exactly *one* closure, so setting one a widget already uses replaces that
  behaviour and fails silently. A composite wires its own children in its own
  constructor and offers a hook for anything an owner needs to know.
- **Every request that produces a reply registers a handler**, even a no-op one.
  The reply FIFO is shared and mis-pairs everything after it otherwise.
- **Say why, not what.** The code says what it does. A comment earns its place by
  recording the reason — especially where the obvious approach is wrong. Several
  comments here name the bug they prevent; keep that up.

## Adding a widget

1. `src/UI/Widget/Foo.php` — subclass `Widget`, read sizes from `$this->metrics()`,
   override `relayout()` if it owns internal geometry.
2. `src/UI/Painter/FooPainter.php` — stateless, takes the `ThemeManager`, draws
   through `chrome()`, `palette()` and `metrics()`. Needs a size or colour that
   does not exist? Add it to `Metrics` or `Palette` with the Win9x value as its
   default.
3. `src/UI/Event/FooChangedEvent.php` if it has something to announce.
4. `src/UI/Handler/FooHandler.php` — owns the painter, the capture state and the
   `try*` methods.
5. Wire it into `example/widget-gallery.php`'s handler list, in the right order.

## Adding a theme

1. `src/Theme/MyEra/MyEraChrome.php` — extend `BaseChrome`, implement the
   era-defining pieces, override whatever else does not fit.
2. `src/Theme/MyEra/MyEraTheme.php` — extend `BaseTheme`: id, name, a `Palette`
   and `Metrics` overriding only what differs, the chrome, and the fonts it wants.
3. Register it in `example/widget-gallery.php`'s `ThemeManager`.

No painter, widget or handler should need touching. **Sample real screenshots
rather than recalling the era** — the details that are easy to get wrong are the
ones nobody notices are wrong.

## Tests

`tests/*_test.php` are plain scripts: print what you checked, exit non-zero if
anything failed. `composer test` runs them all.

For anything about drawing, assert on the bytes. `tests/caption_test.php` shows
the pattern: point a `Renderer` at a fake connection, paint, `flush()`, and
compare what was written — including a negative control, so the assertion cannot
pass vacuously.

## Pull requests

Say what broke and how you know it is fixed. If a fix is subtle, a test that
fails without it is worth more than a paragraph.
