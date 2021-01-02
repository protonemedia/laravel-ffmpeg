<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Driver\FFProbeDriver as FFMpegFFProbeDriver;

class FFProbeDriver extends FFMpegFFProbeDriver
{
    private $pendingWorkingDirectory;

    public function setWorkingDirectory(string $directory): self
    {
        $this->pendingWorkingDirectory = $directory;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function command($command, $bypassErrors = false, $listeners = null)
    {
        if (!is_array($command)) {
            $command = [$command];
        }

        $process = $this->factory->create($command);

        if ($this->pendingWorkingDirectory) {
            // $process->setWorkingDirectory($this->pendingWorkingDirectory);
            $this->pendingWorkingDirectory = null;
        }

        return $this->run($process, $bypassErrors, $listeners);
    }
}
