<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\EventModel;

class Actions
{
    public $update = false;
    public $create = false;
    public $validate = true;
    public $sfidSync = false;

    public function needsDataHydrated(): bool
    {
        return $this->update || $this->create || $this->sfidSync;
    }

}
