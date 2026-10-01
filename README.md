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

## Child templates

A template of the shop that declares `<parent>flexy</parent>` in its `template.xml` ships only
what it overrides, and Flexy answers for the rest:

| It ships | What happens |
|---|---|
| A root page (`product.html.twig`) | Replaces Flexy's. To extend one instead of copying it, `{% extends '@theme_flexy/base.html.twig' %}`: every template of the chain is registered under `@theme_<name>`. `base.html.twig` exposes the `favicons` and `fonts` blocks for the two things a shop always replaces |
| A component directory (`components/Molecules/Button/`) | Replaces Flexy's, anonymous components included; the other components of Flexy stay available |
| `assets/styles/app.css` | Becomes the Tailwind entry point. Import Flexy's (`@import "../../../flexy/assets/styles/app.css"`), which carries its `@source` list, then add your own sources and a `@theme` block: a token declared there replaces Flexy's |
| `assets/icons/*.svg` | Added to Flexy's icons; a file of the same name replaces it everywhere `ux_icon()` asks for that name |
| `translations/messages.<locale>.yaml` | Loaded after Flexy's: a key it repeats replaces Flexy's |
| An `importmap.php`, Stimulus controllers | Its own; without them, Flexy's serve |

`/toolkit` lists the stories of the whole chain, the child's first, and previews them at the
breakpoints of the nearest `variables.css`. Their statuses come from `components/Toolkit/story-statuses.php`
(`return ['Molecules/Button' => ComponentStatus::READY];`, values `ready`, `waiting` or `hidden`):
the nearest template that names a story answers for it, `ComponentStatus` for the rest.

The project has one setting to check: `twig_component.anonymous_template_directory` must be
`'@Flexy'` (`config/packages/twig_component.yaml`). A project configuration may set a filesystem
path there, which is the nearest `components/` directory alone: a child that ships one component
would then lose every anonymous component of Flexy.

`debug:twig-component` cannot read a namespace in that setting, so Flexy hands it the nearest
`components/` directory of the chain instead: the child's anonymous components are listed under
their own name, the ones it inherits under the `theme_<name>:` prefix only.

`ux:icons:import` writes into Flexy's `assets/icons/` (the `ux_icons.icon_dir` Flexy configures,
as `<prefix>/<name>.svg`), not the child's: move the imported file into the child's
`assets/icons/` afterwards.

## Extending it

The template declares `theme_hook()` extension points across its pages — `layout.head.top`, `product.bottom`, `cart.top` and others. A module answers one by implementing `Thelia\Core\Hook\Theme\ThemeHookInterface`; the tag priority drives the rendering order.

The SEOne module already answers `layout.head.top` and `layout.head.bottom`, which is where the title, description, canonical, hreflang and structured data come from.

The head also calls a hook named after the view, `layout.head.<view>`, for what a single page needs: a module that links a stylesheet on the product page alone answers `layout.head.product`, one that adds a script to the cart answers `layout.head.checkout-cart`. The view is the request's `_view` attribute: the core sets it for the pages it routes (`index`, `product`, `category`, `content`, `folder`, `brand`...), and `FlexyController` sets it for the pages it renders, from the template name (`checkout-cart`, `account`, `login`...).

Each section of the sitemap calls `sitemap.urls` inside its `<urlset>`, with `context` set to the section (`categories`, `products`, `content`) and `lang` left empty: a module answers with `<url>` entries, `xhtml:link` alternates included. The `content` section holds nothing else, so the pages a module serves have a place of their own.

The header, the footer and the terms links name no content: they read the `header_links`, `footer_links` and `consent.<code>` content slots of the core (`content_slot()` and `content_slot_first()` in a template). The shop fills them from its settings, and a module can answer them instead through `Thelia\Core\Content\Slot\ContentSlotResolverInterface`.

### Listing a module's component in the toolkit

The toolkit (`/toolkit`, served only while the kernel runs in debug) walks the `components/` directories of the template chain and nothing else. A module lists its own components by implementing `FlexyBundle\Toolkit\StoryProviderInterface`; autoconfiguration tags it, and the tag priority sets the order in the sidebar. Each story names the template the toolkit renders and the file "Show the code" reads:

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

A module's `templates/` directory is registered by the core as the `@{Code}Module` Twig namespace, so the story template needs nothing more. Its status follows the theme's rules (`READY`, `WAITING`, `HIDDEN` drops it), but `story-statuses.php` does not apply to it. Module stories come after those of the templates, and a story whose slug collides with a template story, the child's or one it inherits, stops the page rather than shadowing it.

Mind the stylesheet: `assets/styles/app.css` limits the Tailwind scan to the theme's own files, so a utility class used only in a module template is never compiled. Build a module component out of the theme's components and classes.

### Giving a checkout step of a module a screen

A step declared through `CheckoutStepProviderInterface` is served by this theme when its `componentName()` names a
Twig or Live component: `GET /checkout/step/{code}` (route `checkout_step`, `code` in lower case, digits and
underscores, the code the provider answers) renders `checkout-step.html.twig` with that component in the frame of the
tunnel, with the previous and next links of the configured order. The step is reachable only once the steps before it are
settled; otherwise the buyer is sent back to the first incomplete step that has a screen. A step that names no component
has no screen: the navigation walks past it, its check still applies at the placement. On a one-page checkout the route
redirects, every step being on the cart page.

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
