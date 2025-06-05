const Encore = require('@symfony/webpack-encore');
const path = require('path');

// Manually configure the runtime environment if not already configured yet by the "encore" command.
// It's useful when you use tools that rely on webpack.config.js file.
if (!Encore.isRuntimeEnvironmentConfigured()) {
  Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore
  // directory where compiled assets will be stored
  //.setOutputPath('public/build/')
  .setOutputPath('dist/')
  // public path used by the web server to access the output path
  //.setPublicPath('/templates/frontOffice/flexy/public/build') ///build
  .setPublicPath(
    Encore.isProduction()
      ? '/templates-assets/frontOffice/' + path.basename(__dirname) + '/dist'
      : '/templates-assets/frontOffice/' + path.basename(__dirname) + '/dist'
  )
  // only needed for CDN's or subdirectory deploy
  .setManifestKeyPrefix('dist/')
  .addAliases({
    '@components': path.resolve(__dirname, './components'),
    '@react': path.resolve(__dirname, './react-components'),
    '@js': path.resolve(__dirname, './assets/js'),
    '@utils': path.resolve(__dirname, './assets/js/utils'),
    '@assets': path.resolve(__dirname, './assets')
  })

  /*
   * ENTRY CONFIG
   *
   * Each entry will result in one JavaScript file (e.g. app.js)
   * and one CSS file (e.g. app.css) if your JavaScript imports CSS.
   */
  .addEntry('app', './assets/app.js')
  .addEntry('checkout', './assets/js/routes/checkout.js')
  .addEntry('category-css', './assets/css/pages/category.css')
  .addEntry('product-css', './assets/css/pages/product.css')

  // When enabled, Webpack "splits" your files into smaller pieces for greater optimization.
  .splitEntryChunks()

  // will require an extra script tag for runtime.js
  // but, you probably want this, unless you're building a single-page app
  .enableSingleRuntimeChunk()

  /*
   * FEATURE CONFIG
   *
   * Enable & configure other features below. For a full
   * list of features, see:
   * https://symfony.com/doc/current/frontend.html#adding-more-features
   */
  .cleanupOutputBeforeBuild()
  .enableBuildNotifications()
  .enableSourceMaps(!Encore.isProduction())
  // enables hashed filenames (e.g. app.abc123.css)
  .enableVersioning(Encore.isProduction())

  // configure Babel
  // .configureBabel((config) => {
  //     config.plugins.push('@babel/a-babel-plugin');
  // })

  // enables and configure @babel/preset-env polyfills
  .configureBabelPresetEnv((config) => {
    config.useBuiltIns = 'usage';
    config.corejs = '3.23';
  })
  .configureWatchOptions((config) => {
    config.ignored = /node_modules|dist/;
  })
  .configureDevServerOptions((options) => {
    options.allowedHosts = 'all';
  });

// uncomment to get integrity="..." attributes on your script & link tags
// requires WebpackEncoreBundle 1.4 or higher
//.enableIntegrityHashes(Encore.isProduction())

// uncomment if you're having problems with a jQuery plugin
//.autoProvidejQuery()

// IMAGES CONFIG
Encore.copyFiles({
  from: './assets/images',
  to: 'images/[path][name].[ext]',
  pattern: /\.(png|jpg|jpeg|gif|svg|webp)$/
});

Encore.copyFiles({
  from: './assets/favicons',
  to: 'favicons/[path][name].[ext]',
  pattern: /\.(png|webmanifest|svg|ico)$/
});

Encore.copyFiles({
  from: './node_modules/leaflet/dist/images',
  to: 'images/[path][name].[ext]'
});

Encore.configureImageRule({ type: 'javascript/auto' }, (loaderRule) => {
  loaderRule.test = /\.(png|jpg|jpeg|gif|ico|webp)$/;
  loaderRule.oneOf = [
    { resourceQuery: /copy-files-loader/, type: 'javascript/auto' },
    {
      type: 'asset/resource',
      generator: { filename: 'images/[name].[hash:8][ext]' },
      parser: {}
    }
  ];
});

// CSS CONFIG
Encore.enablePostCssLoader();

// TYPESCRIPT
Encore.enableTypeScriptLoader();

// REACT
Encore.enableReactPreset();

// STIMULUS
Encore.enableStimulusBridge('./assets/controllers.json');

Encore.configureDevServerOptions((options) => {
  options.headers = {
    'Access-Control-Allow-Origin': '*'
  };
  options.allowedHosts = 'all';
});
module.exports = Encore.getWebpackConfig();
