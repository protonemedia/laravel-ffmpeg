<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use Alchemy\BinaryDriver\Exception\ExecutionFailureException;
use FFMpeg\Exception\RuntimeException;
use FFMpeg\FFProbe as FFMpegFFProbe;

class FFProbe extends FFMpegFFProbe
{
    /**
     * @var \ProtoneMedia\LaravelFFMpeg\Filesystem\Media|\ProtoneMedia\LaravelFFMpeg\Filesystem\MediaOnNetwork
     */
    protected $media;

    public function setMedia($media): self
    {
        $this->media = $media;

        return $this;
    }

    /**
     * Create a new instance of this class with the instance of the underlying library.
     *
     * @param \FFMpeg\FFProbe $probe
     * @return self
     */
    public static function make(FFMpegFFProbe $probe): self
    {
        if ($probe instanceof FFProbe) {
            return $probe;
        }

        return new static($probe->getFFProbeDriver(), $probe->getCache());
    }

    /**
     * Probes the streams contained in a given file.
     *
     * @param string $pathfile
     * @return \FFMpeg\FFProbe\DataMapping\StreamCollection
     * @throws \FFMpeg\Exception\InvalidArgumentException
     * @throws \FFMpeg\Exception\RuntimeException
     */

    private function shouldUseCustomProbe($pathfile): bool
    {
        if (!$this->media) {
            return false;
        }

        if ($this->media->getLocalPath() !== $pathfile) {
            return false;
        }

        if (empty($this->media->getInputOptions())) {
            return false;
        }

        if (!$this->getOptionsTester()->has('-show_streams')) {
            throw new RuntimeException('This version of ffprobe is too old and does not support `-show_streams` option, please upgrade');
        }

        return true;
    }

    public function streams($pathfile)
    {
        if (!$this->shouldUseCustomProbe($pathfile)) {
            return parent::streams($pathfile);
        }

        return $this->probeStreams($pathfile, '-show_streams', static::TYPE_STREAMS);
    }

    public function format($pathfile)
    {
        if (!$this->shouldUseCustomProbe($pathfile)) {
            return parent::format($pathfile);
        }

        return $this->probeStreams($pathfile, '-show_format', static::TYPE_FORMAT);
    }

    /**
     * This is just copy-paste from FFMpeg\FFProbe...
     * It prepends the command with the headers.
     */
    private function probeStreams($pathfile, $command, $type, $allowJson = true)
    {
        $commands = array_merge(
            $this->media->getInputOptions(),
            [$pathfile, $command]
        );

        $parseIsToDo = false;

        if ($allowJson && $this->getOptionsTester()->has('-print_format')) {
            // allowed in latest PHP-FFmpeg version
            $commands[] = '-print_format';
            $commands[] = 'json';
        } elseif ($allowJson && $this->getOptionsTester()->has('-of')) {
            // option has changed in avconv 9
            $commands[] = '-of';
            $commands[] = 'json';
        } else {
            $parseIsToDo = true;
        }

        try {
            $output = $this->getFFProbeDriver()->command($commands);
        } catch (ExecutionFailureException $e) {
            throw new RuntimeException(sprintf('Unable to probe %s', $pathfile), $e->getCode(), $e);
        }

        if ($parseIsToDo) {
            $data = $this->getParser()->parse($type, $output);
        } else {
            try {
                $data = @json_decode($output, true);

                if (JSON_ERROR_NONE !== json_last_error()) {
                    throw new RuntimeException(sprintf('Unable to parse json %s', $output));
                }
            } catch (RuntimeException $e) {
                return $this->probeStreams($pathfile, false);
            }
        }

        return $this->getMapper()->map($type, $data);
    }
}
