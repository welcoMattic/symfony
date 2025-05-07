<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Translation\Extractor\Visitor;

use PhpParser\Node;
use PhpParser\NodeVisitor;
use Symfony\Contracts\Translation\TranslatableInterface;

final class BackedEnumVisitor extends AbstractVisitor implements NodeVisitor
{
    /**
     * Stores whether the current class is a translatable backed enum across visits of all children nodes.
     */
    private bool $isBackedEnum = false;
    private array $cases = [];

    public function beforeTraverse(array $nodes): ?Node
    {
        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if (!$this->isBackedEnum($node)) {
            return null;
        }

        // Visit all enum cases to save name and values
        if ($node instanceof Node\Stmt\EnumCase) {
            $this->visitEnumCase($node);
        }

        if ($node instanceof Node\Expr\MethodCall) {
            $this->visitTransMethodCall($node);
        }

        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\Enum_) {
            $this->isBackedEnum = false;
        }

        return null;
    }

    public function afterTraverse(array $nodes): ?Node
    {
        return null;
    }

    private function visitEnumCase(Node\Stmt\EnumCase $node): void
    {
        $this->cases[$node->name->name] = $node->expr->value;
    }

    private function visitTransMethodCall(Node\Expr\MethodCall $node): void
    {
        if (!\is_string($node->name) && !$node->name instanceof Node\Identifier && !$node->name instanceof Node\Name) {
            return;
        }

        $name = $node->name instanceof Node\Name ? $node->name->getLast() : (string) $node->name;
        if ('trans' !== $name && 't' !== $name) {
            return;
        }

        $firstNamedArgumentIndex = $this->nodeFirstNamedArgumentIndex($node);
        $nodeId =  $node->getArgs()[0 < $firstNamedArgumentIndex ? 0 : 'id'];
        $domain = $this->getStringArguments($node, 2 < $firstNamedArgumentIndex ? 2 : 'domain')[0] ?? null;

        $messagePattern = $this->resolveMessagePattern($nodeId->value);
        if (null === $messagePattern) {
            return;
        }

        foreach ($this->cases as $name => $value) {
            $message = str_replace(
                ['{name}', '{value}'],
                [$name, $value],
                $messagePattern
            );

            $this->addMessageToCatalogue($message, $domain, $node->getStartLine());

        }
    }
    private function resolveMessagePattern(Node\Expr $expr): ?string
    {
        $parts = [];

        while ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $parts[] = $this->resolveExprPart($expr->right);
            $expr = $expr->left;
        }

        $parts[] = $this->resolveExprPart($expr);
        // If there is only one string part and does not contains sprintf value(s), TransMethodVisitor already extracted the key.
        if (1 === \count($parts) && !str_contains($parts[0], '{value}') && !str_contains($parts[0], '{name}')) {
            return null;
        }

        $parts = array_reverse($parts);

        // If any part failed to resolve, abort
        if (in_array(null, $parts, true)) {
            return null;
        }

        return implode('', $parts);
    }

    private function resolveExprPart(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if (
            $expr instanceof Node\Expr\PropertyFetch &&
            $expr->var instanceof Node\Expr\Variable &&
            $expr->var->name === 'this' &&
            $expr->name instanceof Node\Identifier
        ) {
            if ($expr->name->name === 'value') {
                return '{value}';
            }

            if ($expr->name->name === 'name') {
                return '{name}';
            }
        }

        if (
            $expr instanceof Node\Expr\FuncCall &&
            $expr->name->name === 'sprintf'
        ) {
            $args = $expr->args;
            $pattern = array_shift($args)->value->value;
            array_walk($args, fn (Node\Arg &$arg) => $arg = $this->resolveExprPart($arg->value));

            return vsprintf($pattern, $args);
        }

        return null; // unsupported part
    }

    private function isBackedEnum(Node $node): bool
    {
        if ($node instanceof Node\Stmt\Enum_) {
            foreach ($node->implements as $interface) {
                if (TranslatableInterface::class === $interface->name) {
                    $this->isBackedEnum = true;
                    break;
                }
            }
        }


        return $this->isBackedEnum;
    }
}
