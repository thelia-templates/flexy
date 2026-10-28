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

The shop setting `address_legal_identifiers_required` decides whether the SIRET and the VAT number, which appear once a company name is typed, are mandatory. Only the value `0` makes them optional (address and customer information forms; on the guest checkout, only the `required` marking follows it); by default they are mandatory.

`ux:icons:import` writes into Flexy's `assets/icons/` (the `ux_icons.icon_dir` Flexy configures,
as `<prefix>/<name>.svg`), not the child's: move the imported file into the child's
`assets/icons/` afterwards.

## Extending it

The template declares `theme_hook()` extension points across its pages — `layout.head.top`, `product.bottom`, `cart.top` and others. A module answers one by implementing `Thelia\Core\Hook\Theme\ThemeHookInterface`; the tag priority drives the rendering order.

The points are part of the theme's public surface: their names and parameters are not renamed.

| Hook point | Where | Parameters |
|---|---|---|
| `layout.head.top`, `layout.head.bottom`, `layout.head.<view>` | `<head>` of every page | `breadcrumb` (and `title`, `description`, `og_type` on `top`) |
| `layout.body.top`, `layout.body.bottom` | First and last thing of `<body>` | — |
| `layout.header.actions` | Header action row, between the account button and the cart | — |
| `layout.header.bottom` | Under the header | — |
| `layout.footer.top` | Above the footer | — |
| `home.top`, `home.bottom` | Home page | — |
| `category.top`, `category.bottom` | Category page | `category` |
| `brand.top`, `brand.bottom` | Brand page | `brand` |
| `product.top`, `product.details.bottom`, `product.bottom` | Product page | `product` |
| `product.pse.alerts` | Under the variant selector, outside the cart form | `pseId`, `outOfStock`, `taxedPrice` |
| `product-card.bottom` | Product card of every listing, under the price, outside the card links | `productId` |
| `cart.top`, `cart.bottom` | Cart page (and one-page checkout) | — |
| `cart.item.bottom` | Each cart line | `cartItem` |
| `checkout.top`, `checkout.bottom` | Every checkout step | — |
| `checkout.payment.extra` | Under the payment method the buyer chose, after its options | `module` (id), `code`, `optionChoices` |
| `checkout.summary.bottom` | Order summary of the cart and checkout steps, under the total, in a live component: for a module paying part of the order another way (gift card form, amount paid, amount left to pay) | `summary` (the figures the summary shows) |
| `checkout.payment.parts` | In the line of each instant payment means (gift card, credit note, wallet) of the payment step | `module` (id), `amount` (what it applies to the cart, a decimal string) |
| `checkout-identify.form.bottom` | Identification step | `next_step_url` |
| `order-placed.top`, `order-placed.bottom` | Order confirmation | — |
| `login.form.top`, `login.form.bottom`, `register.form.top`, `register.form.bottom` | Login and registration forms | — |
| `account.top`, `account.bottom` | Account home | `customer` |
| `account-order.top`, `account-order.bottom` | Order detail | `order` |
| `account-order.item.top`, `account-order.item.bottom` | Each line of an order | `order`, `orderProduct` |
| `account-order-return.top`, `account-order-return.bottom` | Return request of an order | `order` |
| `account-return.top`, `account-return.bottom` | Return detail | `orderReturn` |
| `sitemap.urls` | Inside each `<urlset>` of the sitemap | `context`, `lang` |

`product-card.bottom` is rendered once per card, thirty times on a listing page: an answer reads what it needs for the whole page at once rather than one query per product. A control a module adds there sits outside the card's links, so a button does not end up inside an anchor. `layout.header.actions` takes a header button; the `Molecules:HeaderButton` component keeps it aligned with the others.

The SEOne module already answers `layout.head.top` and `layout.head.bottom`, which is where the title, description, canonical, hreflang and structured data come from.

The head also calls a hook named after the view, `layout.head.<view>`, for what a single page needs: a module that links a stylesheet on the product page alone answers `layout.head.product`, one that adds a script to the cart answers `layout.head.checkout-cart`. The view is the request's `_view` attribute: the core sets it for the pages it routes (`index`, `product`, `category`, `content`, `folder`, `brand`...), and `FlexyController` sets it for the pages it renders, from the template name (`checkout-cart`, `account`, `login`...).

Each section of the sitemap calls `sitemap.urls` inside its `<urlset>`, with `context` set to the section (`categories`, `products`, `content`) and `lang` left empty: a module answers with `<url>` entries, `xhtml:link` alternates included. The `content` section holds nothing else, so the pages a module serves have a place of their own. With the Sitemap module active and more than one language shown, the categories and products are listed once per language, each entry with the `xhtml:link` alternates of all of them and an `x-default` on the default language, the codes SEOne prints in the page head; otherwise one entry per element, in the default language. The sitemap is cached (`sitemap_ttl`, two hours by default): `?flush=1` regenerates a section.

The header, the footer and the terms links name no content: they read the `header_links`, `footer_links` and `consent.<code>` content slots of the core (`content_slot()` and `content_slot_first()` in a template). The shop fills them from its settings, and a module can answer them instead through `Thelia\Core\Content\Slot\ContentSlotResolverInterface`.

### Payment options

A payment module offers choices of its own — paying in three instalments, keeping the card for next time — by answering the core's `TheliaEvents::MODULE_PAYMENT_GET_OPTIONS` event with `PaymentModuleOptionGroup`s. The payment step shows the groups of the method the buyer chose under its card: a group that takes exactly one option (`minimumSelectedOptions` and `maximumSelectedOptions` at 1) as radios, any other as boxes.

The order button is a link to `/checkout/pay`, not a form, so each answer is kept as soon as it is given: in the session, under `payment_module_option_choices`, as a list of `{group, values}` entries — the shape of the core's `PaymentModuleOptionChoice`. Only the options the module offers for the cart are kept, and the list is emptied when the buyer changes method. The module reads it in its `pay()`:

```php
$choices = $request->getSession()->get('payment_module_option_choices', []);
// [['group' => 'my_module_instalments', 'values' => ['three']]]
```

No entry for a group means the buyer picked nothing in it: the module applies its default. What does not fit an option — a saved card to name, a notice — goes in the `checkout.payment.extra` hook, rendered under the chosen method only. That hook sits in a live component, where a `<script>` does not run on a re-render: a module that needs a script on the payment step loads it from `layout.head.checkout-payment` and `layout.head.checkout-onepage`.

### Stored payment methods and payment in several parts

A payment module that implements `Thelia\Module\PaymentModuleWithStoredMethodsInterface` gets the buyer's saved methods in its card, from the `stored_methods` option group the core adds to `GET /api/front/payment/modules`: one radio per usable method (the default one first, with its expiry) and one to pay with a new method. The method is written on the cart through the core, with `customerPaymentMethodId` on `CartFacade::setPaymentModule()`, never in the session store of the options above; the core checks that it is the buyer's, stored through the chosen module and not expired. The default method is preselected when the module is chosen, and a click on the card already chosen keeps the method picked in it. A buyer over the hourly allowance of payments with a stored method (`StoredMethodPaymentRateLimitedException`) is sent back to the payment step with a message, before any order is written.

A module that implements `Thelia\Module\InstantPaymentModuleInterface` pays a part of the order inside the shop. The core keeps the buyer's choice with the module and only asks it how much it applied, so applying a part is the module's job, and the theme reads the result:

- Each instant means has a line in the "Pay part of your order with" block of the payment step, with the amount it applies. It draws what it needs there (a code field, a button) from the `checkout.payment.parts` hook, which gets `module` (its id) and `amount`. The hook is called once per instant module, always under the same name: the handler compares `module` with its own module id and returns an empty string for the others, or its fragment shows up in every line.
- `amount` is a decimal string, never formatted: `"0"` when the module applies nothing; the amount the core plans for that module (capped at what is still due, six decimals, such as `"50.500000"`) when the composition holds; the module's own answer when the core refuses the composition, read close to the way `OrderPaymentComposer::decimalAmount` reads it: trimmed, a plain decimal (`"12.5"`) is kept as it is, any other number is written with six decimals (`"1.0E-5"` gives `"0.000010"`), and what is no number, zero or negative gives `"0"` (the core keeps a negative amount and its splitter counts it as nothing, so the outcome is the same). Format it for display, and do not feed it back into a calculation.
- Once the module has changed its part, the payment step reads the composition again when the `PAYMENT_PARTS_CHANGED` live event (`FlexyBundle\Event\CheckoutEvents::PAYMENT_PARTS_CHANGED`) is emitted. The event only makes the step read again: the change is made first, by the module, in one of two ways.
  - A live component of the module whose `LiveAction` writes the change and then calls `$this->emit(CheckoutEvents::PAYMENT_PARTS_CHANGED)`. A live action carries its own CSRF protection.
  - A plain form posted to a controller of the module, with a CSRF token; the controller writes the change and redirects to the payment step, which reads everything on load. No event is needed.
- `data-action="live#emit" data-live-event-param="PAYMENT_PARTS_CHANGED"` on its own changes nothing on the server: it only serves after a change made elsewhere.
- When the parts stop covering the order, a cart whose payment module is an instant means is left without payment module, and the buyer picks a provider again.
- A part that covers the order whole replaces a provider the buyer already chose, and the stored payment method it carried: the instant means that carries the order is written as the payment module of the cart. A provider that cannot charge a balance, chosen while a part pays only a share, is not removed: the step tells the buyer to choose another one, and the placement refuses it.
- The block then shows what is left to pay, from the core's composition (`OrderPaymentComposer`), and the payment methods offered are the ones that can charge that balance (`BalanceChargingPaymentModuleInterface`).
- A part that covers the order whole leaves no payment method to choose: the instant means that carries the order is written as the payment module of the cart, and the order is placed without a provider.

### Replacing the product search

The search page, the listing and the suggestions ask `FlexyBundle\Service\ProductSearch`, which by default matches the API's `title` filter (word starts, titles only). A module replaces who finds the products by implementing `FlexyBundle\Search\ProductSearchEngineInterface`: autoconfiguration tags it `flexy.product_search_engine`, and the engine with the highest tag priority answers.

```php
public function productIds(string $term, string $locale, int $limit): array; // [12, 4, 27], most relevant first
```

The engine only says which products match. The theme keeps the rest: it drops the products a visitor may not see, paginates, and reads the page through the product API. With no sort chosen the results follow the engine's order; a sort chosen in the selector applies to the matches. An engine returning `[]` means no result: the theme does not fall back on its own search.

### Adding a field to the sign-up and account forms

The sign-up form (`flexybundle_form_customer_register_form`) and the account form (`flexybundle_form_customer_update_form`) render every field they hold, a module's included, inside the form and above the submit button. A module adds its field when the form is built, by listening to `TheliaEvents::FORM_AFTER_BUILD.'.'.<form name>` and calling `$event->getForm()->getFormBuilder()->add(...)`. The theme's controllers only read their own fields: the module reads its value on the event the account is saved through, `TheliaEvents::CREATE_CUSTOMER_MINIMAL` for the sign-up and `TheliaEvents::CUSTOMER_UPDATEPROFILE` for the account form.

### Front events

The cart dispatches the `addPseToCart` and `removePseFromCart` DOM events, `{pse, quantity}`, that a module's script can listen to on `window`; they are part of the theme's public surface like the hook points. Which action sends which event, and what `quantity` counts, is described in [docs/stimulus-and-assets.md](docs/stimulus-and-assets.md#7-the-dom-events-the-cart-components-dispatch).

```js
window.addEventListener('addPseToCart', (event) => {
    console.log(event.detail.pse, event.detail.quantity);
});
```

### Telling the customer they are talking with an AI

A module whose interface lets a visitor or a customer talk with an AI (a chat, an assistant) shows the notice that article 50(1) of the EU AI Act asks for, above the first message:

```twig
<twig:Molecules:AiNotice:Base usage="mymodule.chat" />
```

`usage` is the identifier the module declared with `#[AsAiUsage]` (`transparency: AiTransparency::Interactive`, audience `public` or `customer`). The theme holds no compliance logic and no setting of its own: the Thelia core decides whether a notice is due and supplies its text in the current language (`locale="en_US"` forces another). Nothing is rendered when the usage is not interactive, is inactive, or when the merchant turned the notice off for it. An identifier the core does not know throws, so a typo is not a silent missing notice. The notice is a polite live region (`role="status"`) in the design system's informative message style; a `class` or `id` passed on the component lands on it.

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

Pull requests to `main` go through the merge queue: use "Merge when ready", or `gh pr merge <number>` without a merge method.

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).
