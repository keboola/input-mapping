<?php

namespace Keboola\InputMapping\Staging;

use LogicException;

class NullProvider implements ProviderInterface
{
    public function getWorkspaceId()
    {
        throw new LogicException('getWorkspaceId not implemented.');
    }

    public function cleanup()
    {
    }

    public function getCredentials()
    {
        return [];
    }

    public function getPath()
    {
        throw new LogicException('getPath not implemented.');
    }
}
