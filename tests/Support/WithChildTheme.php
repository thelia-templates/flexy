<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlexyBundle\Tests\Support;

use FlexyBundle\Template\FrontTemplateChain;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A child template of the theme, installed for the length of a callback: its descriptor, the files
 * the test gives it (path => content), and the link that makes the shop find it.
 */
trait WithChildTheme
{
    /**
     * @param array<string, string> $files path under the child directory => content
     * @param callable(string $child, string $directory): void $test
     */
    private function withChildTheme(array $files, callable $test): void
    {
        $child = uniqid('zz-showcase-', false);
        $link = THELIA_TEMPLATE_DIR.'frontOffice'.DS.$child;
        $directory = \dirname(FrontTemplateChain::ownDirectory()).DS.$child;
        $filesystem = new Filesystem();

        try {
            $filesystem->dumpFile($directory.DS.'template.xml', '<?xml version="1.0" encoding="UTF-8"?><template xmlns="http://thelia.net/schema/dic/template"><descriptive locale="en"><title>Child</title></descriptive><parent>flexy</parent><languages><language>en_US</language></languages><version>1.0.0</version><stability>prod</stability></template>');

            foreach ($files as $path => $content) {
                $filesystem->dumpFile($directory.DS.$path, $content);
            }

            if (!is_dir($link)) {
                $filesystem->symlink($directory, $link);
            }

            $test($child, $directory);
        } finally {
            $filesystem->remove([$link, $directory]);
        }
    }
}
