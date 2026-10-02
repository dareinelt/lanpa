<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

use RecursiveFilterIterator;

/**
 * Ueberspringt beim Durchsuchen ausgeschlossene Verzeichnisse (Vorschaubilder,
 * Rueckhol-Puffer, ...) und folgt keinen symbolischen Links.
 */
final class ExcludeFilter extends RecursiveFilterIterator
{
    public function __construct(\RecursiveIterator $iterator, private readonly string $source, private readonly string $root)
    {
        parent::__construct($iterator);
    }

    public function accept(): bool
    {
        /** @var \SplFileInfo $info */
        $info = $this->current();
        if ($info->isLink()) {
            return false;
        }
        if ($info->isDir()) {
            $rel = PathRules::relative($this->root, $info->getPathname());

            return $rel !== null && !PathRules::isExcluded($this->source, $rel);
        }

        return true;
    }

    public function getChildren(): self
    {
        /** @var \RecursiveIterator $inner */
        $inner = $this->getInnerIterator();

        return new self($inner->getChildren(), $this->source, $this->root);
    }
}
