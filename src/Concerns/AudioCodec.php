<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/** @internal Audio encoder settings. Forwards to the PendingExport the builder holds in $export. */
trait AudioCodec
{
    public function audio(?string $codec = null, mixed $bitrate = null): self
    {
        $this->export->audio($codec, $bitrate);

        return $this;
    }

    public function audioOptions(array $tokens): self
    {
        $this->export->audioOptions($tokens);

        return $this;
    }

    /** Override encoding in mapped output audio order, not input stream order. */
    public function audioStream(int $track, callable $configure): self
    {
        $this->export->audioStream($track, $configure);

        return $this;
    }
}
