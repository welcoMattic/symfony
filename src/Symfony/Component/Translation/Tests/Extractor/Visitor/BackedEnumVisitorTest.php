<?php

namespace Symfony\Component\Translation\Tests\Extractor\Visitor;

use PhpParser\NodeVisitor;
use Symfony\Component\Translation\Extractor\Visitor\BackedEnumVisitor;
use Symfony\Component\Translation\Extractor\Visitor\FormTypeVisitor;
use Symfony\Component\Translation\Extractor\Visitor\TransMethodVisitor;
use Symfony\Component\Translation\MessageCatalogue;

class BackedEnumVisitorTest extends AbstractVisitorTest
{
    private const FIXTURES_FOLDER = __DIR__ . '/../../Fixtures/extractor-php-ast/backed-enum-visitor/';

    public function getVisitor(): BackedEnumVisitor
    {
        return new BackedEnumVisitor();
    }

    public function getResource(): iterable|string
    {
        return self::FIXTURES_FOLDER;
    }

    public function assertCatalogue(MessageCatalogue $catalogue): void
    {
        $this->assertEquals(
            [
                'messages' => [
                    'backed_enum.value_concatenation.foo' => 'prefixbacked_enum.value_concatenation.foo',
                    'backed_enum.name_concatenation.Foo' => 'prefixbacked_enum.name_concatenation.Foo',
                    'backed_enum.value_concatenation.foo.label' => 'prefixbacked_enum.value_concatenation.foo.label',
                    'backed_enum.both_concatenation.Foo_foo' => 'prefixbacked_enum.both_concatenation.Foo_foo',
                    'backed_enum.value_concatenation.0' => 'prefixbacked_enum.value_concatenation.0',
                ],
            ],
            $catalogue->all(),
        );

        $this->assertEquals(['sources' => [self::FIXTURES_FOLDER . 'backed-enum.html.php:13']], $catalogue->getMetadata('backed_enum.value_concatenation.foo'));
    }
}
