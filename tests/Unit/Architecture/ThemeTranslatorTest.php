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

namespace FlexyBundle\Tests\Unit\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Thelia\Core\Translation\Translator;

/**
 * Autowired by its type, a TranslatorInterface is the core's translator, which reads the
 * catalogues of the core and of the modules but not the theme's: the sentences of the theme came
 * out in English whatever the language of the shop. Every translator the theme receives is the
 * framework's (`#[Autowire(service: 'translator')]`, the one of its templates), and every sentence
 * it translates through one is in its complete catalogues. `Translator::getInstance()` still
 * reads the core's catalogue on purpose, for the core's own sentences.
 */
final class ThemeTranslatorTest extends TestCase
{
    private const array COMPLETE_LOCALES = ['en_US', 'fr_FR', 'de_DE'];

    public function testEveryTranslatorTheThemeReceivesIsTheFrameworkOne(): void
    {
        $coreTranslator = [];
        foreach ($this->parsedFiles() as $file => $nodes) {
            $constructors = (new NodeFinder())->find($nodes, static fn (Node $node): bool => $node instanceof ClassMethod && '__construct' === $node->name->toLowerString());
            foreach ($constructors as $constructor) {
                \assert($constructor instanceof ClassMethod);
                foreach ($constructor->params as $param) {
                    if ($this->isATranslator($param) && !$this->isWiredToTheFrameworkTranslator($param)) {
                        $coreTranslator[] = $file.':'.$param->getStartLine();
                    }
                }
            }
        }

        self::assertSame([], $coreTranslator, "Add #[Autowire(service: 'translator')]: the core's translator does not read the theme's catalogue.");
    }

    public function testEverySentenceTheThemeTranslatesIsInItsCompleteCatalogues(): void
    {
        $catalogues = array_map(
            static fn (string $locale): array => Yaml::parseFile(\dirname(__DIR__, 3).'/translations/messages.'.$locale.'.yaml'),
            array_combine(self::COMPLETE_LOCALES, self::COMPLETE_LOCALES),
        );
        $missing = [];
        foreach ($this->parsedFiles() as $file => $nodes) {
            $calls = (new NodeFinder())->find($nodes, static fn (Node $node): bool => $node instanceof MethodCall
                && $node->name instanceof Identifier && 'trans' === $node->name->name
                && $node->var instanceof PropertyFetch && $node->var->name instanceof Identifier
                && \in_array($node->var->name->name, ['translator', 'translation'], true)
                && isset($node->args[0]) && $node->args[0] instanceof Node\Arg && $node->args[0]->value instanceof String_);
            foreach ($calls as $call) {
                \assert($call instanceof MethodCall && $call->args[0] instanceof Node\Arg && $call->args[0]->value instanceof String_);
                foreach ($catalogues as $locale => $catalogue) {
                    if (!\array_key_exists($call->args[0]->value->value, $catalogue)) {
                        $missing[] = $locale.' '.$file.':'.$call->getStartLine().' '.$call->args[0]->value->value;
                    }
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testEverySentenceReadThroughTheCoreTranslatorIsInTheCoreCatalogue(): void
    {
        $coreCatalogue = require \dirname((string) (new \ReflectionClass(Translator::class))->getFileName(), 3).'/Config/I18n/fr_FR.php';
        $missing = [];
        foreach ($this->parsedFiles() as $file => $nodes) {
            $calls = (new NodeFinder())->find($nodes, static fn (Node $node): bool => $node instanceof MethodCall
                && $node->name instanceof Identifier && 'trans' === $node->name->name
                && $node->var instanceof StaticCall && $node->var->class instanceof Name && 'Translator' === $node->var->class->getLast()
                && isset($node->args[0]) && $node->args[0] instanceof Node\Arg && $node->args[0]->value instanceof String_);
            foreach ($calls as $call) {
                \assert($call instanceof MethodCall && $call->args[0] instanceof Node\Arg && $call->args[0]->value instanceof String_);
                if (!\array_key_exists($call->args[0]->value->value, $coreCatalogue)) {
                    $missing[] = $file.':'.$call->getStartLine().' '.$call->args[0]->value->value;
                }
            }
        }

        self::assertSame([], $missing, "Not a sentence of the core: translate it with the theme's translator, the core's catalogue leaves it in English.");
    }

    /**
     * @return iterable<string, array<Node>>
     */
    private function parsedFiles(): iterable
    {
        $root = \dirname(__DIR__, 3);
        $parser = (new ParserFactory())->createForHostVersion();
        foreach (['src', 'components'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ('php' === $file->getExtension()) {
                    yield substr($file->getPathname(), \strlen($root) + 1) => $parser->parse((string) file_get_contents($file->getPathname())) ?? [];
                }
            }
        }
    }

    private function isATranslator(Param $param): bool
    {
        return $param->type instanceof Name && 'TranslatorInterface' === $param->type->getLast();
    }

    private function isWiredToTheFrameworkTranslator(Param $param): bool
    {
        foreach ($param->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ('Autowire' !== $attribute->name->getLast()) {
                    continue;
                }
                foreach ($attribute->args as $argument) {
                    if ($argument->value instanceof String_ && 'translator' === $argument->value->value) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
