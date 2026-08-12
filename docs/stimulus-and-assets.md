# Stimulus and asset wiring

How this theme configures Stimulus and AssetMapper, why the configuration points at the *active
front template* rather than at the bundle's own directory, and what to do the day a Thelia module
needs a Symfony UX package.

Verified against `symfony/stimulus-bundle` **v2.36.0**.

---

## 1. The rule this theme follows

`FlexyBundle::prependExtension()` configures the front-end stack on behalf of the application.
Every key that must designate the **active front template** is expressed as a project-relative
parameter path:

| Config key | Value |
|---|---|
| `framework.asset_mapper.vendor_dir` | `%kernel.project_dir%/templates/frontOffice/%thelia_front_template%/assets/vendor` |
| `framework.asset_mapper.importmap_path` | `…/%thelia_front_template%/importmap.php` |
| `framework.asset_mapper.public_prefix` | `/assets/frontOffice/%thelia_front_template%/` |
| `ux_icons.icon_dir` | `…/%thelia_front_template%/assets/icons` |
| `symfonycasts_tailwind.input_css` | `…/%thelia_front_template%/assets/styles/app.css` |
| `stimulus.controller_paths` | `…/%thelia_front_template%/assets/controllers`, `…/%thelia_front_template%/components` |
| `stimulus.controllers_json` | `…/%thelia_front_template%/assets/controllers.json` |

**One deliberate exception:** `framework.asset_mapper.paths` keeps `dirname(__DIR__)`. There the
intent is different — those entries must resolve to *this bundle's own* directories, and they are
listed first so AssetMapper searches them before the host project's. Do not "harmonise" them.

The distinction is the whole point:

- `dirname(__DIR__)` means **this bundle's directory, always**.
- `%thelia_front_template%` means **the directory of whichever front theme is active**.

They diverge exactly when `FlexyBundle` is loaded while another front theme is active — which
`config/bundles.php` makes possible, since it loads the bundle unconditionally rather than
per active theme. Stimulus feeds a single application-wide controller registry, so it must use
the second form.

---

## 2. The two Stimulus keys do different jobs

`StimulusExtension` exposes exactly two knobs, and they are not interchangeable.

### `controller_paths` — an array

Directories scanned for **plain Stimulus controllers** (`*_controller.js`). It is a list, so any
bundle may contribute to it with its own `prependExtensionConfig('stimulus', …)`, and the entries
merge. A module shipping its own controllers needs nothing more than this.

Default when unset: `%kernel.project_dir%/assets/controllers`.

### `controllers_json` — a single scalar

Path to the manifest that enables **Symfony UX packages** (`@symfony/ux-*`): which of a package's
controllers are registered, whether they load eagerly or lazily, and which stylesheets are
auto-imported alongside them.

Default when unset: `%kernel.project_dir%/assets/controllers.json`.

This one is **a single value, not a list** — see §5.

---

## 3. Why the manifest had to move with the rest

Before this change, `controllers_json` was left unset, so the stimulus bundle fell back to its
default at the **project root**. Concretely, every project using this theme was required to keep a
populated `assets/controllers.json` at its root for the theme's live components to work at all —
even though the rest of the theme's asset configuration was already self-contained.

That is the only root-level asset file the front actually depended on. The other root files
(`assets/app.js`, `assets/styles/app.css`, `assets/controllers/`, and even `importmap.php`) are
inert for the front office: `importmap_path` already redirects to the theme's own `importmap.php`,
and the page serves the theme's compiled entrypoint.

Pointing `controllers_json` at the theme makes the theme self-contained.

---

## 4. The precondition — and the failure mode to know about

**Moving the pointer is only safe once the target manifest declares what the old one declared.**

This theme's live components (cart, checkout, PSE selector, search bar) depend on the `live`
controller from `@symfony/ux-live-component`, registered through the manifest — not through the
importmap alone. The importmap entry resolves the *module*; the manifest is what puts the
controller in the generated registry and pulls in `live.min.css`.

The failure is silent. In `ControllersMapGenerator::loadUxControllers()`:

```php
if (!is_file($this->controllersJsonPath)) {
    return [];
}
```

A missing manifest yields an empty map. An empty-but-present manifest (`{"controllers": {}}`)
yields the same. In both cases every live component degrades to inert HTML, with **no exception and
nothing in the browser console**.

So the order of operations matters, and the commits in this branch follow it:

1. Declare `@symfony/ux-live-component` in `assets/controllers.json` (this theme).
2. Only then, repoint `controllers_json` at it.

### Verifying a change here

`bin/console debug:config stimulus` prints the resolved paths — the fastest way to confirm the
configuration is what you think it is:

```
stimulus:
    controller_paths:
        - /var/www/html/templates/frontOffice/flexy/assets/controllers
        - /var/www/html/templates/frontOffice/flexy/components
    controllers_json: /var/www/html/templates/frontOffice/flexy/assets/controllers.json
```

Then confirm the front actually serves the controller and its stylesheet — `live_controller-*.js`
and `live-component/live.min-*.css` must both appear in the rendered page. Clear the cache first
(`composer cache-clear` removes `var/cache/*`), since this is container-level configuration.

Static analysis and template linting will **not** catch a regression here. Neither will the HTTP
test suite: the page still returns 200 with the controller missing. Only the two checks above do.

---

## 5. The constraint to keep in mind

`controllers_json` is **application-wide and single-valued**. Placing it in the theme makes the
front office the owner of the UX-package manifest for the entire application.

This is inconsequential today:

- The manifest holds a single entry, `@symfony/ux-live-component`, and only the front consumes it.
- The **back office is on a separate toolchain entirely** — Webpack Encore with
  `stimulus-bridge`, wired through `enableStimulusBridge('./assets/controllers.json')` against its
  own file. It never reads the `stimulus` container configuration, and it ships no live components.
- A module shipping ordinary Stimulus controllers uses `controller_paths` (a list), not the
  manifest, so it is unaffected.

The single case that would strain this arrangement is a **Thelia module requiring a genuine UX
package** — a Composer package exposing `assets/package.json`, such as `@symfony/ux-dropzone`.
It would have nowhere to declare itself but the front theme's manifest.

---

## 6. If that day comes: decorate the map generator

There is no need to move the manifest back to the root, and no need for the theme to know about
modules. `UxPackageReader` resolves packages through Composer's `InstalledVersions`, i.e.
**project-wide** — so a module's UX package is discoverable no matter which file declares it. The
manifest's location determines *who declares*, never *what is reachable*.

That makes one approach clearly the cleanest: **decorate
`stimulus.asset_mapper.controllers_map_generator`**.

It is a single service whose manifest path is just a constructor argument. A decorator that holds
one generator per contributor — the theme, plus each active module shipping a manifest fragment —
and merges their `getControllersMap()` results, is roughly thirty lines. It leaves the bundle's
configuration shape untouched, needs no build artefact, and keeps each contributor's fragment
next to the code that owns it.

Two recommendations for whoever implements it:

1. **Make an unreadable or missing fragment an error.** The bundle's own `return []` on a missing
   manifest is what makes this area hazardous; do not reproduce it across N contributors. A module
   that declares a fragment and gets silently ignored is a bug that takes hours to find.
2. **Put it in the Thelia layer, not in a theme.** Module contribution is an application concern.
   Implementing it in a bundle or the kernel keeps themes interchangeable — which is the property
   the `%thelia_front_template%` pattern exists to preserve in the first place.

Both points are additive: they can be implemented later without touching this theme.
