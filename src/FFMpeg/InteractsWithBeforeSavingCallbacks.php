<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

trait InteractsWithBeforeSavingCallbacks
{
    /**
     * @var array
     */
    protected $beforeSavingCallbacks = [];

    public function setBeforeSavingCallbacks(array $beforeSavingCallbacks): self
    {
        $this->beforeSavingCallbacks = $beforeSavingCallbacks;

        return $this;
    }

    protected function rebuildCommandWithCallbacks($command)
    {
        foreach ($this->beforeSavingCallbacks as $key => $callback) {
            $newCommand = call_user_func($callback, $command);

            $command = !is_null($newCommand) ? $newCommand : $command;

            unset($this->beforeSavingCallbacks[$key]);
        }

        return $command;
    }
}
