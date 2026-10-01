<?php

declare(strict_types=1);

namespace SlopScan\Support;

use PhpParser\Node;

final class ParentNode
{
    public static function of(Node $node): ?Node
    {
        $parent = $node->getAttribute('parent');

        return $parent instanceof Node ? $parent : null;
    }
}
