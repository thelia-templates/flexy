# Flexy

Front-office template for [Thelia 3](https://thelia.net), built on Twig, Symfony UX and Tailwind CSS v4.

## Requirements

- Thelia **3.0.0** or later
- PHP **8.3+**

Assets are served through Symfony **AssetMapper** — there is no Node build step, no bundler and no `dist/` directory. Tailwind is compiled by `symfonycasts/tailwind-bundle`, which downloads a standalone binary on first use.

## Installation

```bash
composer require thelia/flexy
```

`thelia/installer` places the template under `templates/frontOffice/flexy`. Activate it from the back office, or set it in `.env`:

```dotenv
ACTIVE_FRONT_TEMPLATE=flexy
```

## Layout

| Path | Contents |
|---|---|
| `*.html.twig` | Pages, at the root. `config/views.yaml` lists the ones a controller renders, which are not reachable by name |
| `components/` | Twig components, grouped by responsibility: `Atoms`, `Molecules`, `Organisms`, `Layouts`, `Forms`, `Fields` |
| `src/` | PHP: controllers, services, DTOs, Twig extensions, form types (`FlexyBundle\` namespace) |
| `assets/` | Styles, icons, images, Stimulus controllers |
| `form/` | Form theme — applied explicitly per template, never registered globally |
| `translations/` | `messages.en_US.yaml`, `messages.fr_FR.yaml` |
| `docs/` | Notes on the parts whose behaviour the code alone does not explain |

A component owns its template, its styles and its behaviour in a single directory. `Base.php` holds the data, `Base.html.twig` the markup, `Base.css` the styles, `base_controller.js` the interactions.

## Extending it

The template declares `theme_hook()` extension points across its pages — `layout.head.top`, `product.bottom`, `cart.top` and others. A module answers one by implementing `Thelia\Core\Hook\Theme\ThemeHookInterface`; the tag priority drives the rendering order.

The SEOne module already answers `layout.head.top` and `layout.head.bottom`, which is where the title, description, canonical, hreflang and structured data come from.

### Listing a module's component in the toolkit

The toolkit (`/toolkit`, served only while the kernel runs in debug) walks the theme's `components/` directory and nothing else. A module lists its own components by implementing `FlexyBundle\Toolkit\StoryProviderInterface`; autoconfiguration tags it, and the tag priority sets the order in the sidebar. Each story names the template the toolkit renders and the file "Show the code" reads:

```php
namespace FlexyExtensionDemo\Toolkit;

use FlexyBundle\Toolkit\ComponentStatus;
use FlexyBundle\Toolkit\Story;
use FlexyBundle\Toolkit\StoryProviderInterface;

final readonly class CalloutStoryProvider implements StoryProviderInterface
{
    public function stories(): array
    {
        return [
            new Story(
                category: 'Modules',
                name: 'Flexy extension demo / Callout',
                twigPath: '@FlexyExtensionDemoModule/toolkit/Callout.html.twig',
                sourcePath: __DIR__.'/../templates/toolkit/Callout.html.twig',
                status: ComponentStatus::READY,
            ),
        ];
    }
}
```

A module's `templates/` directory is registered by the core as the `@{Code}Module` Twig namespace, so the story template needs nothing more. Its status follows the theme's rules (`READY`, `WAITING`, `HIDDEN` drops it), and a story whose slug collides with a theme story stops the page rather than shadowing it.

Mind the stylesheet: `assets/styles/app.css` limits the Tailwind scan to the theme's own files, so a utility class used only in a module template is never compiled. Build a module component out of the theme's components and classes.

## Deploying

Check that your web server serves `.webmanifest` as `application/manifest+json`. Once the
assets are compiled the manifest is a static file, so its media type comes from the server
and nothing in the theme can set it. Whether a given server maps that extension depends on
its own table, so verify rather than assume:

```bash
curl -sI https://example.com/assets/.../site.webmanifest | grep -i content-type
```

If it answers anything else, map the extension in the server configuration. With nginx, for
instance:

```nginx
types { application/manifest+json  webmanifest; }
```

## Development

```bash
ddev composer cs-diff          # coding standards, dry run
ddev composer cs               # and fix them
ddev composer phpstan-flexy    # static analysis
ddev composer test:http-flexy  # HTTP smoke tests
```

Run test suites through the `composer test:*` scripts only. Calling `vendor/bin/phpunit` directly resolves to the development database instead of the test one.

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).
