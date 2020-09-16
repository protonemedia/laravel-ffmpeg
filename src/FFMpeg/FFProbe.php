<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use Alchemy\BinaryDriver\Exception\ExecutionFailureException;
use FFMpeg\Exception\RuntimeException;
use FFMpeg\FFProbe as FFMpegFFProbe;
use ProtoneMedia\LaravelFFMpeg\Filesystem\MediaOnNetwork;

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

    public function streams($pathfile)
    {
        if (!$this->media instanceof MediaOnNetwork || $this->media->getLocalPath() !== $pathfile) {
            return parent::streams($pathfile);
        }

        if (!$this->getOptionsTester()->has('-show_streams')) {
            throw new RuntimeException('This version of ffprobe is too old and does not support `-show_streams` option, please upgrade');
        }

        return $this->probeStreams($pathfile);
    }

    /**
     * This is just copy-paste from FFMpeg\FFProbe...
     * It prepends the command with the headers.
     */
    private function probeStreams($pathfile, $allowJson = true)
    {
        $commands = array_merge(
            $this->media->getCompiledHeaders(),
            [$pathfile, '-show_streams']
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
            $data = $this->getParser()->parse(static::TYPE_STREAMS, $output);
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

        return $this->getMapper()->map(static::TYPE_STREAMS, $data);
    }
}
