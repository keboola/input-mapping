<?php

declare(strict_types=1);

namespace Keboola\InputMapping\Table\Options;

class ReaderOptions
{
    public function __construct(
        private readonly bool $devInputsDisabled,
        private readonly bool $preserveWorkspace = true,
        private readonly bool $hasProtectedDefaultBranch = false,
    ) {
    }

    public function devInputsDisabled(): bool
    {
        return $this->devInputsDisabled;
    }

    public function preserveWorkspace(): bool
    {
        return $this->preserveWorkspace;
    }

    public function hasProtectedDefaultBranch(): bool
    {
        return $this->hasProtectedDefaultBranch;
    }
}
