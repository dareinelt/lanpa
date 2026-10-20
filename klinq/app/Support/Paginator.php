<?php

declare(strict_types=1);

namespace App\Support;

final class Paginator
{
    public readonly int $page;
    public readonly int $perPage;
    public readonly int $total;
    public readonly int $pages;

    public function __construct(int $total, int $page = 1, int $perPage = 50)
    {
        $this->perPage = max(1, min($perPage, 500));
        $this->total = max(0, $total);
        $this->pages = max(1, (int) ceil($this->total / $this->perPage));
        $this->page = max(1, min($page, $this->pages));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : $this->offset() + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->offset() + $this->perPage);
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages;
    }
}
